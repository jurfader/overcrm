<?php

namespace App\Http\Controllers;

use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Models\PriceList;
use App\Services\UserMailService;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class VisitEmailController extends Controller
{
    protected UserMailService $mailService;

    public function __construct(UserMailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Wyślij email dla wizyty (szablon lub własna wiadomość)
     */
    public function send(Request $request, ClientVisit $visit)
    {
        $validated = $request->validate([
            'template_id' => 'nullable|exists:email_templates,id',
            'subject' => 'nullable|string|max:500',
            'html_content' => 'nullable|string',
            'to_email' => 'nullable|email',
            'mail_config_id' => 'nullable|exists:user_mail_configs,id',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240',
            'price_list_id' => 'nullable|exists:price_lists,id',
        ]);

        $visit->load('client');

        $manualTo = isset($validated['to_email']) ? trim((string) $validated['to_email']) : '';

        if (!$visit->client && $manualTo === '') {
            return response()->json([
                'success' => false,
                'message' => 'Wizyta nie ma przypisanego klienta. Wpisz adres email odbiorcy.',
            ], 422);
        }

        $toEmail = $manualTo !== '' ? $manualTo : ($visit->client?->email ?? '');
        if (!$toEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Wpisz adres email odbiorcy lub dodaj email w danych klienta.',
            ], 422);
        }

        $user = auth()->user();
        $mailConfig = null;

        if (!empty($validated['mail_config_id'])) {
            $mailConfig = $user->mailConfigs()->find($validated['mail_config_id']);
        }

        $attachments = $request->file('attachments', []);
        if (!is_array($attachments)) {
            $attachments = $attachments ? [$attachments] : [];
        }
        $attachments = array_filter($attachments, fn ($f) => $f && $f->isValid());

        // Generuj PDF z cennika i dodaj jako załącznik
        $pdfTempPath = null;
        if (!empty($validated['price_list_id'])) {
            $priceList = PriceList::find($validated['price_list_id']);
            if ($priceList && $priceList->html_content) {
                $pdfTempPath = storage_path('app/temp/cennik-' . $priceList->slug . '-' . time() . '.pdf');
                if (!is_dir(dirname($pdfTempPath))) {
                    mkdir(dirname($pdfTempPath), 0755, true);
                }
                $printCss = '<style>article.card { break-inside: avoid !important; page-break-inside: avoid !important; margin-bottom: 6px !important; } .btn-buy { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; } #ck-topmenu { display: none !important; }</style>';
                $pdfHtml = str_replace('</head>', $printCss . '</head>', $priceList->html_content);
                $pdfRawPath = $pdfTempPath . '.raw.pdf';
                Browsershot::html($pdfHtml)
                    ->noSandbox()
                    ->format('A4')
                    ->margins(8, 8, 8, 8)
                    ->showBackground()
                    ->windowSize(1200, 800)
                    ->waitUntilNetworkIdle()
                    ->save($pdfRawPath);
                // Kompresja Ghostscriptem (~92MB → ~3-5MB)
                $gsCmd = sprintf(
                    'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dBATCH -dQUIET -sOutputFile=%s %s 2>&1',
                    escapeshellarg($pdfTempPath),
                    escapeshellarg($pdfRawPath)
                );
                exec($gsCmd, $gsOutput, $gsExitCode);
                @unlink($pdfRawPath);
                if ($gsExitCode !== 0 || !file_exists($pdfTempPath)) {
                    // Fallback: użyj nieskompresowanego
                    if (file_exists($pdfRawPath)) {
                        rename($pdfRawPath, $pdfTempPath);
                    }
                }
                $attachments[] = $pdfTempPath;
            }
        }

        try {
            if (!empty($validated['template_id'])) {
                // Wysyłka z szablonu
                $template = EmailTemplate::findOrFail($validated['template_id']);
                $subjectOverride = !empty(trim($validated['subject'] ?? '')) ? trim($validated['subject']) : null;
                $sentEmail = $this->mailService->sendFromTemplate(
                    user: $user,
                    template: $template,
                    client: $visit->client,
                    visit: $visit,
                    mailConfig: $mailConfig,
                    subjectOverride: $subjectOverride,
                    attachments: $attachments,
                    toEmailOverride: $toEmail
                );
            } elseif (!empty(trim($validated['subject'] ?? '')) && !empty(trim($validated['html_content'] ?? ''))) {
                // Wysyłka własnej wiadomości
                $toName = $visit->client?->name ?? $this->mailService->guessRecipientNameFromEmail($toEmail);
                $sentEmail = $this->mailService->send(
                    user: $user,
                    toEmail: $toEmail,
                    subject: trim($validated['subject']),
                    htmlContent: trim($validated['html_content']),
                    toName: $toName,
                    mailConfig: $mailConfig,
                    client: $visit->client,
                    visit: $visit,
                    attachments: $attachments
                );
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Wybierz szablon lub wpisz temat i treść wiadomości.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Email został wysłany pomyślnie.',
                'sent_email_id' => $sentEmail->id,
            ]);

        } catch (\Throwable $e) {
            report($e);
            // SMTP błędy często zawierają dane logowania/host — zwróć użytkownikowi generyczny komunikat
            $userMsg = 'Nie udało się wysłać wiadomości. Sprawdź konfigurację skrzynki (SMTP) w ustawieniach lub spróbuj ponownie za chwilę.';
            $class = (new \ReflectionClass($e))->getShortName();
            if (str_contains($class, 'Transport') || str_contains($class, 'Smtp') || str_contains($class, 'Mailer')) {
                $userMsg = 'Błąd wysyłki SMTP: serwer poczty odmówił połączenia lub odrzucił wiadomość. Sprawdź konfigurację skrzynki.';
            }
            return response()->json([
                'success' => false,
                'message' => $userMsg,
            ], 500);
        } finally {
            // Usuń tymczasowy PDF cennika
            if ($pdfTempPath && file_exists($pdfTempPath)) {
                @unlink($pdfTempPath);
            }
        }
    }

    /**
     * Podgląd wyrenderowanego szablonu lub własnej wiadomości dla wizyty
     */
    public function preview(Request $request, ClientVisit $visit)
    {
        $validated = $request->validate([
            'template_id' => 'nullable|exists:email_templates,id',
            'subject' => 'nullable|string|max:500',
            'html_content' => 'nullable|string',
        ]);

        $visit->load('client');

        if (!empty($validated['template_id'])) {
            $template = EmailTemplate::findOrFail($validated['template_id']);
            $preview = $this->mailService->previewTemplate(
                template: $template,
                client: $visit->client,
                visit: $visit
            );
            return response()->json([
                'success' => true,
                'subject' => $preview['subject'],
                'html' => $preview['html'],
                'variables' => $preview['variables'],
            ]);
        }

        if (!empty(trim($validated['subject'] ?? '')) && !empty(trim($validated['html_content'] ?? ''))) {
            $user = auth()->user();
            $footer = $user->email_html_footer ?? \App\Models\Setting::get('email_html_footer', '', 'core');
            $html = trim($validated['html_content']);
            if ($footer && is_string($footer) && trim($footer) !== '') {
                $html .= "\n" . trim($footer);
            }
            return response()->json([
                'success' => true,
                'subject' => trim($validated['subject']),
                'html' => $html,
                'variables' => [],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Wybierz szablon lub wpisz temat i treść.',
        ], 422);
    }
}
