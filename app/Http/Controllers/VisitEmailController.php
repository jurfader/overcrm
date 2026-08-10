<?php

namespace App\Http\Controllers;

use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Jobs\SendVisitEmailJob;
use App\Services\UserMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $maTresc = !empty($validated['template_id'])
            || (trim((string) ($validated['subject'] ?? '')) !== '' && trim((string) ($validated['html_content'] ?? '')) !== '');

        if (!$maTresc) {
            return response()->json([
                'success' => false,
                'message' => 'Wybierz szablon lub wpisz temat i treść wiadomości.',
            ], 422);
        }

        $validated['to_email'] = $toEmail;

        // Przesłane pliki żyją w katalogu tymczasowym PHP tylko do końca żądania,
        // a zadanie z kolejki wystartuje później — trzeba je przenieść w miejsce,
        // które przetrwa. Zadanie sprząta po sobie w bloku finally.
        $token = (string) Str::uuid();
        $zalaczniki = $this->przeniesZalaczniki($request, $token);

        SendVisitEmailJob::zapiszStatus($token, 'pending', 'Wiadomość czeka w kolejce…');

        SendVisitEmailJob::dispatch($token, $visit->id, auth()->id(), $validated, $zalaczniki);

        // 202: przyjęte do realizacji, wynik pod osobnym adresem. Front odpytuje
        // sendStatus() zamiast czekać na zakończenie generowania PDF-a i wysyłki.
        return response()->json([
            'success' => true,
            'queued' => true,
            'token' => $token,
            'status_url' => route('calendar.send-email-status', $token),
            'message' => 'Wiadomość została przyjęta do wysyłki.',
        ], 202);
    }

    /**
     * Stan wysyłki zleconej przez send(). Front odpytuje co kilka sekund.
     */
    public function sendStatus(string $token)
    {
        $stan = SendVisitEmailJob::status($token);

        if (!$stan) {
            return response()->json([
                'success' => false,
                'status' => 'unknown',
                'message' => 'Nie znam takiego zlecenia — mogło wygasnąć.',
            ], 404);
        }

        return response()->json(['success' => true] + $stan);
    }

    /**
     * Przenosi przesłane pliki z katalogu tymczasowego PHP w miejsce trwałe.
     *
     * @return array<int, string>
     */
    protected function przeniesZalaczniki(Request $request, string $token): array
    {
        $pliki = $request->file('attachments', []);

        if (!is_array($pliki)) {
            $pliki = $pliki ? [$pliki] : [];
        }

        $katalog = storage_path('app/temp/mail-'.$token);
        $sciezki = [];

        foreach (array_filter($pliki, fn ($f) => $f && $f->isValid()) as $plik) {
            if (!is_dir($katalog)) {
                mkdir($katalog, 0755, true);
            }

            $nazwa = $plik->getClientOriginalName();
            $plik->move($katalog, $nazwa);
            $sciezki[] = $katalog.'/'.$nazwa;
        }

        return $sciezki;
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
