<?php

namespace Modules\PlayCentrala\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Play Wirtualna Centralka API Service
 * (zastąpiło integrację z Ringostat)
 */
class RingostatService
{
    private string $clientId;
    private string $clientSecret;
    private string $privateKey; // PEM
    private string $apiBase = 'https://uslugidlafirm.play.pl';

    public function __construct()
    {
        $this->clientId     = Setting::get('play_client_id', '', 'ringostat') ?? '';
        $this->clientSecret = Setting::get('play_client_secret', '', 'ringostat') ?? '';
        $this->privateKey   = Setting::get('play_private_key', '', 'ringostat') ?? '';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    // ==================== JWT AUTH ====================

    /**
     * Pobiera lub odświeża token JWT (cache 19 min — token domyślnie 20 min).
     */
    public function getJwtToken(): string
    {
        return Cache::remember('play_jwt_token', 1140, function () {
            $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type'  => 'application/json',
            ])->post($this->apiBase . '/oauth/token-jwt');

            if (!$response->successful()) {
                throw new \RuntimeException('Play API JWT error: HTTP ' . $response->status() . ' — ' . $response->body());
            }

            $data = $response->json();

            // Różne formaty odpowiedzi — zabezpieczenie
            return $data['token'] ?? $data['access_token'] ?? $data['jwt'] ?? (string) $response->body();
        });
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getJwtToken(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    // ==================== POŁĄCZENIA ====================

    /**
     * Historia połączeń — jedna strona.
     */
    public function getCallHistory(string $from, string $to, int $page = 1, int $size = 100): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        // Play API wymaga formatu "yyyy-mm-dd hh:mm" (bez sekund)
        $fromFmt = substr($from, 0, 16);
        $toFmt   = substr($to,   0, 16);

        $response = Http::withHeaders($this->authHeaders())
            ->timeout(30)
            ->get($this->apiBase . '/api/wirtualnacentralka/getCallHistory', [
                'fromDate'  => $fromFmt,
                'toDate'    => $toFmt,
                'page'      => $page,
                'size'      => $size,
                'orderProp' => 'timestamp',
                'orderDir'  => 'desc',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Play getCallHistory error: HTTP ' . $response->status());
        }

        return $response->json('calls') ?? [];
    }

    /**
     * Pobiera WSZYSTKIE połączenia z danego zakresu (auto-paginacja).
     * Alias getCalls() zachowuje kompatybilność z istniejącym kodem.
     */
    public function getAllCalls(string $from, string $to): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $allCalls = [];
        $page     = 1;
        $size     = 100;

        try {
            do {
                $page_calls = $this->getCallHistory($from, $to, $page, $size);
                $allCalls   = array_merge($allCalls, $page_calls);
                $page++;
            } while (count($page_calls) === $size); // kontynuuj jeśli pełna strona
        } catch (\Exception $e) {
            Log::error('Play getAllCalls error: ' . $e->getMessage());
        }

        return $allCalls;
    }

    /** Alias dla kompatybilności z istniejącym kodem (RingostatSyncAndAnalyze itp.) */
    public function getCalls(string $from, string $to, ?array $fields = null): array
    {
        return $this->getAllCalls($from, $to);
    }

    /**
     * Lista numerów telefonów użytkowników centralki.
     */
    public function getUsersNumbers(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->authHeaders())
            ->timeout(15)
            ->get($this->apiBase . '/api/wirtualnacentralka/getUsersNumbers');

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    // ==================== NAGRANIA ====================

    /**
     * Pobierz i odszyfruj nagranie z Play API.
     * Zwraca ścieżkę do tymczasowego pliku MP3 lub null przy błędzie.
     * Wywołujący jest odpowiedzialny za usunięcie pliku po użyciu.
     */
    public function downloadAndDecryptRecording(string $url): ?string
    {
        if (empty($this->privateKey)) {
            Log::warning('Play: brak klucza prywatnego do odszyfrowania nagrania');
            return null;
        }

        try {
            // 1. Pobierz zaszyfrowane nagranie z autoryzacją JWT
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(120)
                ->get($url);

            if (!$response->successful()) {
                Log::error('Play: błąd pobierania nagrania', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            $encryptedBody = $response->body();
            if (strlen($encryptedBody) < 100) {
                Log::error('Play: nagranie zbyt małe, prawdopodobnie błąd', ['size' => strlen($encryptedBody)]);
                return null;
            }

            // 2. Zapisz zaszyfrowany plik i klucz do plików tymczasowych
            $encryptedFile = tempnam(sys_get_temp_dir(), 'play_enc_');
            $keyFile       = tempnam(sys_get_temp_dir(), 'play_key_');
            $decryptedFile = sys_get_temp_dir() . '/play_dec_' . uniqid() . '.mp3';

            file_put_contents($encryptedFile, $encryptedBody);

            // Normalizuj klucz do formatu PEM jeśli przechowywany jako bare base64
            $privateKey = $this->privateKey;
            if (!str_contains($privateKey, '-----')) {
                $b64 = preg_replace('/\s+/', '', $privateKey); // usuń wszelkie białe znaki
                $privateKey = "-----BEGIN PRIVATE KEY-----\n"
                    . chunk_split($b64, 64, "\n")
                    . "-----END PRIVATE KEY-----\n";
            }
            file_put_contents($keyFile, $privateKey);

            // 3. Odszyfruj przez openssl cms (Play zwraca CMS w formacie DER binarnym)
            $cmd        = sprintf(
                'openssl cms -decrypt -inform DER -in %s -inkey %s -out %s 2>&1',
                escapeshellarg($encryptedFile),
                escapeshellarg($keyFile),
                escapeshellarg($decryptedFile)
            );
            $cmdOutput  = [];
            $returnCode = 0;
            exec($cmd, $cmdOutput, $returnCode);

            @unlink($encryptedFile);
            @unlink($keyFile);

            if ($returnCode !== 0 || !file_exists($decryptedFile) || filesize($decryptedFile) < 1000) {
                Log::error('Play: odszyfrowanie nieudane', [
                    'cmd_output' => implode("\n", $cmdOutput),
                    'return_code' => $returnCode,
                ]);
                @unlink($decryptedFile);
                return null;
            }

            return $decryptedFile;
        } catch (\Exception $e) {
            Log::error('Play: downloadAndDecryptRecording error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Proxy — strumieniuj odszyfrowane nagranie (do audio playera w przeglądarce).
     * Zwraca zaszyfrowaną zawartość z nagłówkami HTTP lub null przy błędzie.
     */
    public function streamDecryptedRecording(string $url): ?\Illuminate\Http\Response
    {
        $tempFile = $this->downloadAndDecryptRecording($url);
        if (!$tempFile) {
            return null;
        }

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return response($content, 200, [
            'Content-Type'        => 'audio/mpeg',
            'Content-Length'      => strlen($content),
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'no-store',
            'Content-Disposition' => 'inline',
        ]);
    }

    // ==================== CLICK2CALL ====================

    /**
     * Inicjuje połączenie wychodzące z numeru użytkownika na numer docelowy.
     */
    public function initiateCallback(string $callingNumber, string $destination): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Play API nie jest skonfigurowane'];
        }

        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(15)
                ->post($this->apiBase . '/api/wirtualnacentralka/click2call', [
                    'callingNumber' => $this->normalizePlayPhone($callingNumber),
                    'calledNumber'  => $this->normalizePlayPhone($destination),
                ]);

            if ($response->successful()) {
                Log::info('Play click2call initiated', [
                    'calling' => $callingNumber,
                    'called'  => $destination,
                ]);
                return ['success' => true, 'message' => 'Połączenie zostało zainicjowane'];
            }

            return [
                'success' => false,
                'message' => match ($response->status()) {
                    400     => 'Nieprawidłowy numer telefonu',
                    401     => 'Błąd autoryzacji — sprawdź Client ID i Secret',
                    403     => 'Brak uprawnień do wykonania połączenia',
                    default => 'Błąd API: HTTP ' . $response->status(),
                },
            ];
        } catch (\Exception $e) {
            Log::error('Play click2call error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Błąd: ' . $e->getMessage()];
        }
    }

    // ==================== TEST ====================

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Brak konfiguracji (Client ID lub Client Secret)'];
        }

        try {
            // Unieważnij cache tokena na czas testu
            Cache::forget('play_jwt_token');
            $this->getJwtToken(); // rzuci wyjątek jeśli błąd

            $numbers = $this->getUsersNumbers();

            return [
                'success' => true,
                'message' => 'Połączenie aktywne — znaleziono ' . count($numbers) . ' numerów użytkowników',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Błąd połączenia: ' . $e->getMessage()];
        }
    }

    // ==================== MAPOWANIE DANYCH ====================

    /**
     * Mapuje dane połączenia z Play API na kolumny ringostat_calls.
     * Sygnatura statyczna zachowana dla kompatybilności z istniejącym kodem.
     */
    public static function mapCallData(array $call): array
    {
        $direction = strtoupper($call['direction'] ?? 'MT');
        $callType  = $direction === 'MO' ? 'out' : 'in';

        $status      = strtoupper($call['status'] ?? 'MISSED');
        $disposition = self::mapPlayStatus($status);

        // Numer pracownika: dla wychodzących = callingNumber, dla przychodzących = answeredByNumber
        $callingNumber      = $call['callingNumber'] ?? null;
        $answeredByNumber   = !empty($call['answeredByNumber']) ? $call['answeredByNumber'] : null;
        $employeePhone      = $callType === 'out' ? $callingNumber : ($answeredByNumber ?: null);

        return [
            'caller'              => $callingNumber,
            'destination'         => $call['calledNumber'] ?? null,
            'answered_by_number'  => $answeredByNumber,
            'call_type'           => $callType,
            'disposition'         => $disposition,
            'call_date'           => $call['timestamp'] ?? now(),
            'duration'            => intval($call['duration'] ?? 0),
            'wait_time'           => 0,
            'billsec'             => intval($call['duration'] ?? 0),
            'recording_url'       => (isset($call['recordingApiUrl']) && str_starts_with((string) $call['recordingApiUrl'], 'https://uslugidlafirm.play.pl')) ? $call['recordingApiUrl'] : null,
            'recording_wav_url'   => null,
            'encryption_key_name' => $call['encryptionKeyName'] ?? null,
            'employee_id'         => $employeePhone,   // numer telefonu pracownika
            'employee_name'       => null,             // wypełni matchUser()
            'department'          => null,
            'utm_source'          => null,
            'utm_medium'          => null,
            'utm_campaign'        => null,
            'landing_page'        => null,
            'referrer'            => null,
            'call_card_url'       => null,
            'scheme_name'         => null,
            'missing_reason'      => null,
        ];
    }

    /**
     * Mapuje status Play na wartości disposition w DB.
     */
    public static function mapPlayStatus(string $status): string
    {
        return match ($status) {
            'ANSWERED', 'CONNECTED' => 'ANSWERED',
            'MISSED'                => 'NO ANSWER',
            'ESTABLISHED'           => 'ESTABLISHED',
            'REDIRECTED'            => 'REDIRECTED',
            default                 => strtoupper($status),
        };
    }

    /**
     * Normalizuje numer telefonu do formatu Play (48XXXXXXXXX).
     */
    public static function normalizePlayPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Jeśli 9 cyfr — dodaj prefiks PL
        if (strlen($digits) === 9) {
            $digits = '48' . $digits;
        }

        // Jeśli zaczyna od 0 — zamień na 48
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '48' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Kompatybilność z poprzednim kodem — identyczna logika co normalizePlayPhone.
     */
    public static function normalizePhoneField(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        return self::normalizePlayPhone($value) ?: null;
    }
}
