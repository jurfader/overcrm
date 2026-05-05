<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Klient licencji OVERMEDIA license-server.
 *
 * Statusy: missing | active | grace | expired | invalid
 *  - missing  → brak klucza w bazie (świeża instalacja)
 *  - active   → ostatnia walidacja OK, expires_at > now
 *  - grace    → ostatnia walidacja FAIL z powodu network/server, ale jesteśmy w 7-dniowym oknie karencji
 *  - expired  → server zwraca expired (klucz wygasł)
 *  - invalid  → server zwraca INVALID_LICENSE / DOMAIN_NOT_ACTIVATED
 *
 * Walidacja online co 24h (schedule daily). Pomiędzy — czytamy z Settings (cache).
 */
class LicenseService
{
    public const STATUS_MISSING = 'missing';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_GRACE   = 'grace';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALID = 'invalid';

    public const GRACE_DAYS = 7;
    public const HTTP_TIMEOUT = 8;

    protected function serverUrl(): string
    {
        return rtrim(config('services.license.url', env('LICENSE_SERVER_URL', 'http://51.38.137.199:3002')), '/');
    }

    protected function domain(): string
    {
        $url = config('app.url');
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return strtolower($host);
    }

    protected function installationId(): string
    {
        $id = Setting::get('license_installation_id', null);
        if ($id) return $id;
        $id = (string) Str::uuid();
        Setting::set('license_installation_id', $id);
        return $id;
    }

    public function status(): array
    {
        return [
            'key'           => $this->maskedKey(),
            'status'        => Setting::get('license_status', self::STATUS_MISSING),
            'plan'          => Setting::get('license_plan', null),
            'expires_at'    => Setting::get('license_expires_at', null),
            'last_check_at' => Setting::get('license_last_check_at', null),
            'grace_until'   => Setting::get('license_grace_until', null),
            'last_error'    => Setting::get('license_last_error', null),
            'is_valid'      => $this->isValid(),
        ];
    }

    public function isValid(): bool
    {
        // Anti-tamper: jeśli HMAC zapisanego state nie zgadza się z aktualnym, ktoś
        // zmienil DB recznie. Wracamy do invalid (ignorujemy zmiane).
        if (!$this->verifyStateLock()) {
            return false;
        }

        $status = Setting::get('license_status', self::STATUS_MISSING);

        if ($status === self::STATUS_ACTIVE) {
            $exp = Setting::get('license_expires_at', null);
            if ($exp) {
                return Carbon::parse($exp)->isFuture();
            }
            return true;
        }

        if ($status === self::STATUS_GRACE) {
            $until = Setting::get('license_grace_until', null);
            return $until && Carbon::parse($until)->isFuture();
        }

        return false;
    }

    public function hasKey(): bool
    {
        return !empty(Setting::get('license_key', null));
    }

    protected function maskedKey(): ?string
    {
        $key = Setting::get('license_key', null);
        if (!$key) return null;
        if (strlen($key) <= 8) return $key;
        return substr($key, 0, 4) . '-…-' . substr($key, -4);
    }

    /**
     * Aktywacja nowego klucza (lub re-aktywacja po zmianie domeny).
     * Wywoływane z UI gdy admin wpisuje klucz w /license.
     */
    public function activate(string $key): array
    {
        $key = strtoupper(trim($key));
        Setting::set('license_key', $key);

        try {
            $resp = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->serverUrl() . '/activate', [
                    'licenseKey'     => $key,
                    'domain'         => $this->domain(),
                    'installationId' => $this->installationId(),
                    'metrics'        => $this->collectMetrics(),
                ]);

            $body = $resp->json() ?? [];

            if ($resp->successful() && ($body['success'] ?? false)) {
                // Activate response payload signed: { success, plan, expiresAt }
                if (!$this->verifyResponseSignature($body, ['success', 'plan', 'expiresAt'])) {
                    $this->saveErrorStatus('INVALID_SIGNATURE', $body);
                    return ['success' => false, 'message' => 'Odpowiedź serwera licencji ma nieprawidłowy podpis (możliwy MITM)', 'data' => $body];
                }
                $this->saveActiveStatus($body);
                return ['success' => true, 'message' => 'Licencja aktywowana', 'data' => $body];
            }

            $this->saveErrorStatus($body['error'] ?? 'UNKNOWN_ERROR', $body);
            return ['success' => false, 'message' => $this->errorMessage($body['error'] ?? 'UNKNOWN_ERROR'), 'data' => $body];
        } catch (\Throwable $e) {
            Log::warning('License activate failed', ['error' => $e->getMessage()]);
            Setting::set('license_last_error', $e->getMessage());
            return ['success' => false, 'message' => 'Nie udało się połączyć z serwerem licencji: ' . $e->getMessage()];
        }
    }

    /**
     * Walidacja zapisanego klucza (uruchamiane z schedule co 24h + przy CTA "odśwież").
     */
    public function validate(): array
    {
        $key = Setting::get('license_key', null);
        if (!$key) {
            Setting::set('license_status', self::STATUS_MISSING);
            return ['success' => false, 'message' => 'Brak klucza licencji', 'status' => self::STATUS_MISSING];
        }

        try {
            $resp = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->serverUrl() . '/validate', [
                    'licenseKey'     => $key,
                    'domain'         => $this->domain(),
                    'installationId' => $this->installationId(),
                    'metrics'        => $this->collectMetrics(),
                ]);

            $body = $resp->json() ?? [];

            if ($resp->successful() && ($body['valid'] ?? false)) {
                // Validate response payload signed: { valid, plan, expiresAt }
                if (!$this->verifyResponseSignature($body, ['valid', 'plan', 'expiresAt'])) {
                    $this->saveErrorStatus('INVALID_SIGNATURE', $body);
                    Log::warning('License response signature mismatch', ['domain' => $this->domain()]);
                    return ['success' => false, 'message' => 'Nieprawidłowy podpis odpowiedzi serwera licencji', 'status' => self::STATUS_INVALID];
                }
                $this->saveActiveStatus($body);
                return ['success' => true, 'message' => 'Licencja aktywna', 'status' => self::STATUS_ACTIVE];
            }

            $err = $body['error'] ?? 'UNKNOWN_ERROR';
            $this->saveErrorStatus($err, $body);
            return ['success' => false, 'message' => $this->errorMessage($err), 'status' => Setting::get('license_status')];
        } catch (\Throwable $e) {
            // Network/server down — wchodzimy w okres karencji jeśli jeszcze nie jesteśmy w nim
            $this->enterGrace($e->getMessage());
            Log::info('License server unreachable, grace mode', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Serwer licencji niedostępny — tryb karencji', 'status' => self::STATUS_GRACE];
        }
    }

    protected function saveActiveStatus(array $body): void
    {
        Setting::set('license_status', self::STATUS_ACTIVE);
        Setting::set('license_plan', $body['plan'] ?? null);
        Setting::set('license_expires_at', $body['expiresAt'] ?? null);
        Setting::set('license_last_check_at', now()->toIso8601String());
        Setting::set('license_grace_until', null);
        Setting::set('license_last_error', null);
        Setting::set('setup_completed', '1');
        $this->writeStateLock();
        Cache::flush();
    }

    protected function saveErrorStatus(string $err, array $body = []): void
    {
        $status = match ($err) {
            'LICENSE_EXPIRED'        => self::STATUS_EXPIRED,
            'INVALID_LICENSE',
            'DOMAIN_NOT_ACTIVATED',
            'LICENSE_INACTIVE',
            'MAX_INSTALLATIONS_REACHED',
            'INVALID_SIGNATURE'      => self::STATUS_INVALID,
            default                  => self::STATUS_INVALID,
        };
        Setting::set('license_status', $status);
        Setting::set('license_last_check_at', now()->toIso8601String());
        Setting::set('license_last_error', $err);
        Setting::set('license_grace_until', null);
        $this->writeStateLock();
        Cache::flush();
    }

    protected function enterGrace(string $reason): void
    {
        $current = Setting::get('license_grace_until', null);
        if (!$current) {
            // Pierwszy network failure — startujemy okno karencji
            Setting::set('license_grace_until', now()->addDays(self::GRACE_DAYS)->toIso8601String());
        }
        Setting::set('license_status', self::STATUS_GRACE);
        Setting::set('license_last_check_at', now()->toIso8601String());
        Setting::set('license_last_error', $reason);
        $this->writeStateLock();
        Cache::flush();
    }

    protected function errorMessage(string $code): string
    {
        return match ($code) {
            'INVALID_LICENSE'          => 'Nieprawidłowy klucz licencji',
            'LICENSE_EXPIRED'          => 'Licencja wygasła — skontaktuj się z OVERMEDIA aby przedłużyć',
            'LICENSE_INACTIVE'         => 'Licencja nieaktywna',
            'DOMAIN_NOT_ACTIVATED'     => 'Klucz nie jest przypisany do tej domeny',
            'MAX_INSTALLATIONS_REACHED'=> 'Osiągnięto maksymalną liczbę instalacji dla tej licencji',
            'INVALID_SIGNATURE'        => 'Nieprawidłowy podpis odpowiedzi serwera (możliwy MITM)',
            default                    => "Błąd licencji ({$code})",
        };
    }

    // ===================================================================
    // ANTI-TAMPER (Etap 1 zabezpieczeń):
    //  - verifyResponseSignature: ED25519 weryfikacja każdej odpowiedzi z license-server
    //    (klucz publiczny w env LICENSE_SIGNING_PUBLIC_KEY). Powstrzymuje MITM/fake DNS.
    //  - writeStateLock + verifyStateLock: HMAC nad zapisanym state w DB (klucz=APP_KEY).
    //    Manualna edycja DB (`UPDATE settings SET value='active'…`) → HMAC się rozjedzie
    //    → isValid() zwróci false. Pirat musi też znać APP_KEY.
    // ===================================================================

    /**
     * Weryfikacja podpisu ED25519 z license-server.
     * Format: server podpisuje JSON.stringify(payload) używając ED25519 private key,
     * klient weryfikuje używając klucza publicznego.
     *
     * KRYTYCZNE: kolejność i format JSON musi się zgadzać 1:1 z tym co server podpisał.
     * Server (Node): JSON.stringify({success, plan, expiresAt})
     * Tutaj (PHP):   json_encode(['success'=>…, 'plan'=>…, 'expiresAt'=>…], UNESCAPED_UNICODE|UNESCAPED_SLASHES)
     */
    protected function verifyResponseSignature(array $body, array $payloadKeys): bool
    {
        $publicKeyB64 = config('services.license.public_key', '');

        // Brak public key w env = fail-closed w produkcji, fail-open w dev
        if (empty($publicKeyB64)) {
            if (app()->environment('production')) {
                Log::error('License signature verification skipped — LICENSE_SIGNING_PUBLIC_KEY not set');
                return false;
            }
            return true; // dev/staging — pozwól bez podpisu
        }

        $signatureB64 = $body['signature'] ?? null;
        if (!$signatureB64) return false;

        try {
            $publicKey = base64_decode($publicKeyB64, true);
            $signature = base64_decode($signatureB64, true);
            if (!$publicKey || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
            if (!$signature || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return false;

            // Zbuduj payload w DOKŁADNIE tej kolejności co server (kolejność kluczy w JSON ma znaczenie!)
            $payload = [];
            foreach ($payloadKeys as $k) {
                $payload[$k] = $body[$k] ?? null;
            }
            $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\Throwable $e) {
            Log::warning('License signature verify exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Lista pól licencji które są pod ochroną HMAC. Zmiana któregokolwiek z nich
     * w DB ręcznie unieważnia stan.
     */
    protected function lockedKeys(): array
    {
        return [
            'license_key', 'license_status', 'license_plan',
            'license_expires_at', 'license_grace_until', 'license_installation_id',
        ];
    }

    /** Po każdej zmianie state → przelicz i zapisz HMAC. */
    protected function writeStateLock(): void
    {
        $hmac = $this->computeStateHmac();
        Setting::set('license_state_hmac', $hmac);
    }

    /**
     * Sprawdź czy zapisany HMAC zgadza się z aktualnym state.
     *
     * KRYTYCZNE: nie wolno bootstrapować HMAC dla istniejącego active state — to byłby
     * wektor ataku (pirat usuwa HMAC, app go odtwarza dla tampered state). Brak HMAC =
     * nieufanie state aż do następnej online walidacji (która zapisze nowy HMAC).
     */
    protected function verifyStateLock(): bool
    {
        $stored = Setting::get('license_state_hmac', null);
        $status = Setting::get('license_status', self::STATUS_MISSING);

        if (!$stored) {
            // Brak HMAC = albo świeża instalacja (status=missing OK), albo ktoś go usunął
            // żeby ominąć weryfikację. W obu przypadkach: nie ufamy active/grace.
            return $status === self::STATUS_MISSING;
        }

        $current = $this->computeStateHmac();
        return hash_equals($stored, $current);
    }

    /** HMAC-SHA256 nad konkatenacją locked fields, klucz = APP_KEY. */
    protected function computeStateHmac(): string
    {
        $parts = [];
        foreach ($this->lockedKeys() as $k) {
            $parts[$k] = (string) Setting::get($k, '');
        }
        $material = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $appKey = config('app.key') ?: 'no-app-key';
        return hash_hmac('sha256', $material, $appKey);
    }

    // ===================================================================
    // ETAP 2b: Telemetry phone-home
    // Wysyłane przy każdym activate/validate. License-server zapisuje w lic_audit
    // (event='telemetry'). Admin OVERMEDIA może wykryć anomalie:
    //   - 5 instancji z różnymi build_hash dla tego samego klucza solo = klonowanie
    //   - duże skoki w liczbie userów/klientów (potencjalny re-sale)
    //   - brak telemetry przez X dni (instalacja offline / cracked)
    // ===================================================================

    protected function collectMetrics(): array
    {
        try {
            return [
                'app_version'   => $this->buildVersion(),
                'php_version'   => PHP_VERSION,
                'laravel_version' => \Illuminate\Foundation\Application::VERSION,
                'users_count'   => $this->safeCount(fn () => \App\Models\User::query()->count()),
                'clients_count' => $this->safeCount(fn () => \App\Models\Client::query()->count()),
                'tasks_count'   => $this->safeCount(fn () => \App\Models\Task::query()->count()),
                'modules'       => $this->activeModules(),
                'timezone'      => config('app.timezone'),
                'locale'        => config('app.locale'),
            ];
        } catch (\Throwable $e) {
            // Telemetry nie może wywalić aktywacji — wracamy minimum
            return ['app_version' => 'unknown', 'error' => substr($e->getMessage(), 0, 100)];
        }
    }

    protected function buildVersion(): string
    {
        $manifestPath = public_path('build/manifest.json');
        if (!file_exists($manifestPath)) return 'dev';
        try {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $entry = $manifest['resources/js/app.js']['file'] ?? null;
            return $entry ? basename($entry) : (string) filemtime($manifestPath);
        } catch (\Throwable $e) {
            return 'dev';
        }
    }

    protected function safeCount(callable $fn): int
    {
        try { return (int) $fn(); } catch (\Throwable $e) { return 0; }
    }

    protected function activeModules(): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('modules')) return [];
            return \App\Models\Module::query()
                ->where('is_active', true)
                ->pluck('name')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
