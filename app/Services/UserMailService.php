<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\User;
use App\Models\UserMailConfig;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Illuminate\Http\UploadedFile;

class UserMailService
{
    /**
     * Wyślij email używając konfiguracji SMTP użytkownika
     */
    /**
     * @param array<UploadedFile|string> $attachments UploadedFile lub ścieżki do plików (legacy)
     */
    public function send(
        User $user,
        string $toEmail,
        string $subject,
        string $htmlContent,
        ?string $toName = null,
        ?UserMailConfig $mailConfig = null,
        ?EmailTemplate $template = null,
        ?Client $client = null,
        ?ClientVisit $visit = null,
        array $attachments = []
    ): SentEmail {
        // Pobierz konfigurację SMTP
        $config = $mailConfig ?? $user->mailConfigs()->default()->first();

        if (!$config) {
            throw new \Exception('Użytkownik nie ma skonfigurowanego serwera pocztowego.');
        }

        if (!$config->is_verified) {
            throw new \Exception('Konfiguracja serwera pocztowego nie została zweryfikowana.');
        }

        // Utwórz rekord wysłanego maila
        $sentEmail = SentEmail::create([
            'user_id' => $user->id,
            'user_mail_config_id' => $config->id,
            'email_template_id' => $template?->id,
            'client_id' => $client?->id,
            'client_visit_id' => $visit?->id,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'html_content' => $htmlContent,
            'status' => 'pending',
        ]);

        try {
            // Dodaj stopkę HTML (najpierw użytkownika, potem globalną)
            $footer = $user->email_html_footer ?? \App\Models\Setting::get('email_html_footer', '', 'core');
            if ($footer && is_string($footer) && trim($footer) !== '') {
                $htmlContent = rtrim($htmlContent) . "\n" . trim($footer);
            }

            // Utwórz transport SMTP
            $transport = $this->createTransport($config);
            $mailer = new Mailer($transport);

            // Utwórz wiadomość
            $email = (new Email())
                ->from(new Address($config->mail_from_address, $config->mail_from_name))
                ->to(new Address($toEmail, $toName ?? ''))
                ->subject($subject)
                ->html($htmlContent);

            foreach ($attachments as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $email->addPart(new DataPart(
                        new File($file->getRealPath()),
                        $file->getClientOriginalName(),
                        $file->getMimeType()
                    ));
                } elseif (is_string($file) && is_file($file)) {
                    $email->addPart(new DataPart(new File($file), basename($file)));
                }
            }

            // Wyślij
            $mailer->send($email);

            $sentEmail->markAsSent();

            Log::info('Email wysłany', [
                'sent_email_id' => $sentEmail->id,
                'to' => $toEmail,
                'subject' => $subject,
            ]);

        } catch (\Exception $e) {
            $sentEmail->markAsFailed($e->getMessage());

            Log::error('Błąd wysyłania emaila', [
                'sent_email_id' => $sentEmail->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $sentEmail;
    }

    /**
     * Wyślij email na podstawie szablonu
     *
     * @param string|null $subjectOverride Opcjonalna zmiana tematu maila (nadpisuje temat z szablonu)
     * @param array<UploadedFile|string> $attachments Załączniki do maila (UploadedFile lub ścieżki legacy)
     * @param string|null $toEmailOverride Opcjonalny adres odbiorcy (gdy podany, nadpisuje client.email)
     */
    public function sendFromTemplate(
        User $user,
        EmailTemplate $template,
        ?Client $client,
        ?ClientVisit $visit = null,
        ?UserMailConfig $mailConfig = null,
        ?string $subjectOverride = null,
        array $attachments = [],
        ?string $toEmailOverride = null
    ): SentEmail {
        $toEmail = ($toEmailOverride !== null && trim($toEmailOverride) !== '') ? trim($toEmailOverride) : ($client?->email ?? '');
        if (!$toEmail) {
            throw new \Exception('Wpisz adres email odbiorcy lub dodaj email w danych klienta.');
        }

        // Przygotuj dane do szablonu
        $data = $this->prepareTemplateData($user, $client, $visit);

        // Renderuj szablon
        $htmlContent = $template->render($data);
        $subject = $subjectOverride !== null && trim($subjectOverride) !== ''
            ? trim($subjectOverride)
            : $template->renderSubject($data);

        // Załączniki z opisu szablonu (stary system: "Załącznik (stary system): path1|path2")
        $legacyPaths = $template->getLegacyAttachmentPaths();
        $resolvedPaths = [];

        foreach ($legacyPaths as $legacyPath) {
            $resolved = $this->resolveLegacyAttachmentPath($legacyPath);
            if ($resolved !== null) {
                $resolvedPaths[] = $resolved;
            }
        }

        $allAttachments = array_merge($resolvedPaths, $attachments);

        $toName = $client?->name ?? $this->guessRecipientNameFromEmail($toEmail);

        return $this->send(
            user: $user,
            toEmail: $toEmail,
            subject: $subject,
            htmlContent: $htmlContent,
            toName: $toName,
            mailConfig: $mailConfig,
            template: $template,
            client: $client,
            visit: $visit,
            attachments: $allAttachments
        );
    }

    /**
     * Krótka nazwa odbiorcy z adresu email (gdy brak klienta w bazie)
     */
    public function guessRecipientNameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0] ?? '';
        $local = str_replace(['.', '_'], ' ', $local);

        return $local !== '' ? ucwords($local) : 'Odbiorca';
    }

    /**
     * Rozwiąż ścieżkę legacy (np. web/files/x.pdf) do rzeczywistej ścieżki na dysku
     */
    private function resolveLegacyAttachmentPath(string $legacyPath): ?string
    {
        $legacyPath = str_replace('\\', '/', trim($legacyPath));
        $legacyBase = config('app.legacy_files_path');
        $candidates = [
            base_path($legacyPath),
            public_path($legacyPath),
            public_path(ltrim(preg_replace('#^web/files/?#', 'files/', $legacyPath), '/')),
            public_path('files/' . basename($legacyPath)),
            storage_path('app/legacy_files/' . basename($legacyPath)),
        ];
        if ($legacyBase && is_dir($legacyBase)) {
            $candidates[] = rtrim($legacyBase, '/') . '/' . $legacyPath;
        }

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Przygotuj dane do szablonu (client opcjonalny – np. wizyta bez przypisanego klienta + ręczny email)
     */
    public function prepareTemplateData(User $user, ?Client $client, ?ClientVisit $visit = null): array
    {
        $address = $client ? collect([
            $client->street,
            $client->building_number ? 'nr ' . $client->building_number : null,
            $client->apartment_number ? '/' . $client->apartment_number : null,
            $client->postal_code,
            $client->city,
        ])->filter()->implode(', ') : '';

        return [
            'client_name' => $client?->name ?? '',
            'client_email' => $client?->email ?? '',
            'client_phone' => $client?->phone ?? '',
            'client_nip' => $client?->nip ?? '',
            'client_address' => $address,
            'visit_date' => $visit?->visit_date?->format('d.m.Y'),
            'visit_time' => $visit?->visit_time?->format('H:i'),
            'visit_title' => $visit?->title ?? $visit?->display_title,
            'visit_notes' => $visit?->notes,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => $user->phone ?? '',
            'current_date' => now()->format('d.m.Y'),
            'company_name' => config('app.name', 'CHICKENKING'),
        ];
    }

    /**
     * Utwórz transport SMTP na podstawie konfiguracji
     * Zachowuje kompatybilność ze starym planerem (PHPMailer) — nie wymusza weryfikacji SSL
     */
    protected function createTransport(UserMailConfig $config): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $encryption = $config->mail_encryption;
        $port = $config->mail_port;

        // EsmtpTransport: $tls = true oznacza implicit SSL (smtps, port 465)
        // $tls = false oznacza STARTTLS lub brak szyfrowania (port 587, 25)
        $tls = ($encryption === 'ssl' || $port == 465);

        $transport = new EsmtpTransport(
            $config->mail_host,
            $port,
            $tls
        );

        $transport->setUsername($config->mail_username);
        $transport->setPassword($config->getDecryptedPassword());

        // Wyłącz weryfikację certyfikatów SSL (kompatybilność z PHPMailer)
        // Pozwala na połączenie ze zwykłym hasłem Google Workspace
        $stream = $transport->getStream();
        $streamOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
        $stream->setStreamOptions($streamOptions);

        return $transport;
    }

    /**
     * Testuj konfigurację SMTP
     */
    public function testConnection(UserMailConfig $config): array
    {
        try {
            $transport = $this->createTransport($config);
            $mailer = new Mailer($transport);

            // Wyślij testową wiadomość
            $email = (new Email())
                ->from(new Address($config->mail_from_address, $config->mail_from_name))
                ->to(new Address($config->mail_from_address, 'Test'))
                ->subject('Test połączenia SMTP - ' . config('app.name'))
                ->html('<p>To jest testowa wiadomość potwierdzająca poprawność konfiguracji serwera SMTP.</p><p>Data testu: ' . now()->format('d.m.Y H:i:s') . '</p>');

            $mailer->send($email);

            // Oznacz jako zweryfikowaną
            $config->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            return ['success' => true, 'message' => 'Połączenie działa poprawnie! Email testowy został wysłany.'];

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Przyjazne komunikaty błędów
            $friendlyMessage = $this->getFriendlySmtpError($errorMessage, $config);

            Log::error('Test SMTP nieudany', [
                'config_id' => $config->id,
                'host' => $config->mail_host,
                'port' => $config->mail_port,
                'encryption' => $config->mail_encryption,
                'error' => $errorMessage,
            ]);

            return ['success' => false, 'message' => $friendlyMessage];
        }
    }

    /**
     * Zwróć przyjazny komunikat błędu SMTP
     */
    protected function getFriendlySmtpError(string $error, UserMailConfig $config): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'authentication') || str_contains($lower, '535') || str_contains($lower, 'credentials')) {
            if (str_contains(strtolower($config->mail_host), 'gmail')) {
                return 'Błąd uwierzytelniania Gmail. Upewnij się, że używasz "Hasła aplikacji" (App Password), a nie zwykłego hasła. '
                     . 'Wejdź na myaccount.google.com → Bezpieczeństwo → Hasła do aplikacji, aby wygenerować hasło.';
            }
            return 'Błąd uwierzytelniania. Sprawdź nazwę użytkownika i hasło.';
        }

        if (str_contains($lower, 'connection refused') || str_contains($lower, 'connection timed out') || str_contains($lower, 'could not connect')) {
            return 'Nie można połączyć się z serwerem ' . $config->mail_host . ':' . $config->mail_port
                 . '. Sprawdź host, port i typ szyfrowania.';
        }

        if (str_contains($lower, 'ssl') || str_contains($lower, 'tls') || str_contains($lower, 'crypto')) {
            return 'Błąd szyfrowania SSL/TLS. Dla Gmaila użyj: Host smtp.gmail.com, Port 587, Szyfrowanie TLS. '
                 . 'Alternatywnie: Port 465, Szyfrowanie SSL.';
        }

        if (str_contains($lower, 'certificate') || str_contains($lower, 'verify')) {
            return 'Błąd certyfikatu SSL. Sprawdź konfigurację szyfrowania.';
        }

        return 'Nie udało się połączyć: ' . $error;
    }

    /**
     * Podgląd wyrenderowanego szablonu
     */
    public function previewTemplate(
        EmailTemplate $template,
        ?Client $client,
        ?ClientVisit $visit = null,
        ?User $user = null
    ): array {
        $user = $user ?? auth()->user();
        $data = $this->prepareTemplateData($user, $client, $visit);
        $html = $template->render($data);

        $footer = $user->email_html_footer ?? \App\Models\Setting::get('email_html_footer', '', 'core');
        if ($footer && is_string($footer) && trim($footer) !== '') {
            $html = rtrim($html) . "\n" . trim($footer);
        }

        return [
            'subject' => $template->renderSubject($data),
            'html' => $html,
            'variables' => $data,
        ];
    }
}
