<?php

namespace App\Jobs;

use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Models\PriceList;
use App\Models\User;
use App\Services\UserMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spatie\Browsershot\Browsershot;

/**
 * Wysyłka oferty z wizyty w tle.
 *
 * Wcześniej całość — generowanie PDF-a cennika Browsershotem, kompresja
 * Ghostscriptem i wysyłka SMTP — działa się w trakcie żądania HTTP. Na większym
 * cenniku potrafi to zająć kilkadziesiąt sekund: przeglądarka czeka, a na
 * hostingu z krótkim limitem czasu żądanie zwyczajnie ginie i użytkownik nie wie,
 * czy mail poszedł, czy nie.
 *
 * Postęp trafia do cache pod tokenem, który front odpytuje. Stany:
 * `pending` → `processing` → `done` | `error`.
 *
 * WYMAGA DZIAŁAJĄCEGO WORKERA (`queue:work` w cronie). Gdy worker nie chodzi,
 * status zostaje na `pending` — i to jest widoczne, w odróżnieniu od cichego
 * zniknięcia wiadomości.
 */
class SendVisitEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generowanie PDF-a bywa wolne; 10 minut to sufit, nie norma. */
    public int $timeout = 600;

    /** Bez ponowień: druga próba wysłałaby klientowi tę samą ofertę drugi raz. */
    public int $tries = 1;

    /** Jak długo status jest dostępny dla frontu po zakończeniu. */
    public const TTL_STATUSU = 3600;

    /**
     * @param array<string, mixed> $dane   zwalidowane pola formularza (same skalary)
     * @param array<int, string>   $zalaczniki  ścieżki plików już przeniesionych w trwałe miejsce
     */
    public function __construct(
        public string $token,
        public int $visitId,
        public int $userId,
        public array $dane,
        public array $zalaczniki = [],
    ) {}

    public static function status(string $token): ?array
    {
        return Cache::get(self::kluczCache($token));
    }

    public static function zapiszStatus(string $token, string $stan, ?string $komunikat = null, array $dodatkowe = []): void
    {
        Cache::put(self::kluczCache($token), array_merge([
            'status' => $stan,
            'message' => $komunikat,
            'updated_at' => now()->toIso8601String(),
        ], $dodatkowe), self::TTL_STATUSU);
    }

    protected static function kluczCache(string $token): string
    {
        return 'visit-email:'.$token;
    }

    public function handle(UserMailService $mailService): void
    {
        self::zapiszStatus($this->token, 'processing', 'Przygotowuję wiadomość…');

        $pdfCennika = null;

        try {
            $wizyta = ClientVisit::with('client')->findOrFail($this->visitId);
            $uzytkownik = User::findOrFail($this->userId);

            $konfiguracja = !empty($this->dane['mail_config_id'])
                ? $uzytkownik->mailConfigs()->find($this->dane['mail_config_id'])
                : null;

            $zalaczniki = array_values(array_filter($this->zalaczniki, 'file_exists'));

            if (!empty($this->dane['price_list_id'])) {
                self::zapiszStatus($this->token, 'processing', 'Generuję PDF cennika…');
                $pdfCennika = $this->zbudujPdfCennika((int) $this->dane['price_list_id']);

                if ($pdfCennika) {
                    $zalaczniki[] = $pdfCennika;
                }
            }

            self::zapiszStatus($this->token, 'processing', 'Wysyłam wiadomość…');

            $wyslany = $this->wyslij($mailService, $uzytkownik, $wizyta, $konfiguracja, $zalaczniki);

            self::zapiszStatus($this->token, 'done', 'Wiadomość została wysłana.', [
                'sent_email_id' => $wyslany?->id,
            ]);
        } catch (\Throwable $e) {
            report($e);

            // Błędy SMTP potrafią zawierać host i dane logowania — użytkownik
            // dostaje komunikat ogólny, szczegóły zostają w logu.
            $klasa = (new \ReflectionClass($e))->getShortName();
            $komunikat = str_contains($klasa, 'Transport') || str_contains($klasa, 'Smtp') || str_contains($klasa, 'Mailer')
                ? 'Błąd wysyłki SMTP: serwer poczty odmówił połączenia lub odrzucił wiadomość. Sprawdź konfigurację skrzynki.'
                : 'Nie udało się wysłać wiadomości. Sprawdź konfigurację skrzynki (SMTP) lub spróbuj ponownie.';

            self::zapiszStatus($this->token, 'error', $komunikat);
        } finally {
            // Sprzątamy zarówno PDF, jak i przesłane załączniki — leżą w katalogu
            // tymczasowym wyłącznie po to, żeby przetrwać do uruchomienia zadania.
            foreach (array_filter([$pdfCennika, ...$this->zalaczniki]) as $sciezka) {
                if (is_string($sciezka) && file_exists($sciezka)) {
                    @unlink($sciezka);
                }
            }
        }
    }

    /** Gdy zadanie padnie poza blokiem try (np. timeout), status też musi to odzwierciedlić. */
    public function failed(\Throwable $e): void
    {
        self::zapiszStatus($this->token, 'error', 'Wysyłka nie powiodła się: '.$e->getMessage());
    }

    protected function wyslij(UserMailService $mailService, User $uzytkownik, ClientVisit $wizyta, $konfiguracja, array $zalaczniki)
    {
        $odbiorca = $this->dane['to_email'];

        if (!empty($this->dane['template_id'])) {
            return $mailService->sendFromTemplate(
                user: $uzytkownik,
                template: EmailTemplate::findOrFail($this->dane['template_id']),
                client: $wizyta->client,
                visit: $wizyta,
                mailConfig: $konfiguracja,
                subjectOverride: trim((string) ($this->dane['subject'] ?? '')) ?: null,
                attachments: $zalaczniki,
                toEmailOverride: $odbiorca
            );
        }

        return $mailService->send(
            user: $uzytkownik,
            toEmail: $odbiorca,
            subject: trim((string) $this->dane['subject']),
            htmlContent: trim((string) $this->dane['html_content']),
            toName: $wizyta->client?->name ?? $mailService->guessRecipientNameFromEmail($odbiorca),
            mailConfig: $konfiguracja,
            client: $wizyta->client,
            visit: $wizyta,
            attachments: $zalaczniki
        );
    }

    /**
     * Renderuje cennik do PDF i kompresuje Ghostscriptem.
     *
     * Surowy wydruk Browsershota potrafi ważyć ~90 MB przez osadzone tła —
     * po kompresji schodzi do kilku MB. Gdy Ghostscript nie zadziała, wysyłamy
     * wersję nieskompresowaną: duży załącznik jest lepszy niż brak oferty.
     */
    protected function zbudujPdfCennika(int $priceListId): ?string
    {
        $cennik = PriceList::find($priceListId);

        if (!$cennik || !$cennik->html_content) {
            return null;
        }

        $katalog = storage_path('app/temp');

        if (!is_dir($katalog)) {
            mkdir($katalog, 0755, true);
        }

        $docelowy = $katalog.'/cennik-'.$cennik->slug.'-'.$this->token.'.pdf';
        $surowy = $docelowy.'.raw.pdf';

        $cssWydruku = '<style>article.card { break-inside: avoid !important; page-break-inside: avoid !important; margin-bottom: 6px !important; } .btn-buy { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; } #ck-topmenu { display: none !important; }</style>';
        $html = str_replace('</head>', $cssWydruku.'</head>', $cennik->html_content);

        Browsershot::html($html)
            ->noSandbox()
            ->format('A4')
            ->margins(8, 8, 8, 8)
            ->showBackground()
            ->windowSize(1200, 800)
            ->waitUntilNetworkIdle()
            ->save($surowy);

        $komenda = sprintf(
            'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dBATCH -dQUIET -sOutputFile=%s %s 2>&1',
            escapeshellarg($docelowy),
            escapeshellarg($surowy)
        );

        exec($komenda, $wyjscie, $kod);
        @unlink($surowy);

        if ($kod !== 0 || !file_exists($docelowy)) {
            if (file_exists($surowy)) {
                rename($surowy, $docelowy);
            }
        }

        return file_exists($docelowy) ? $docelowy : null;
    }
}
