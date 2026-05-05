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
                ->post($this->serverUrl() . '/license/activate', [
                    'licenseKey'     => $key,
                    'domain'         => $this->domain(),
                    'installationId' => $this->installationId(),
                ]);

            $body = $resp->json() ?? [];

            if ($resp->successful() && ($body['success'] ?? false)) {
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
                ->post($this->serverUrl() . '/license/validate', [
                    'licenseKey'     => $key,
                    'domain'         => $this->domain(),
                    'installationId' => $this->installationId(),
                ]);

            $body = $resp->json() ?? [];

            if ($resp->successful() && ($body['valid'] ?? false)) {
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
        Cache::flush();
    }

    protected function saveErrorStatus(string $err, array $body = []): void
    {
        $status = match ($err) {
            'LICENSE_EXPIRED'        => self::STATUS_EXPIRED,
            'INVALID_LICENSE',
            'DOMAIN_NOT_ACTIVATED',
            'LICENSE_INACTIVE',
            'MAX_INSTALLATIONS_REACHED' => self::STATUS_INVALID,
            default                  => self::STATUS_INVALID,
        };
        Setting::set('license_status', $status);
        Setting::set('license_last_check_at', now()->toIso8601String());
        Setting::set('license_last_error', $err);
        Setting::set('license_grace_until', null);
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
            default                    => "Błąd licencji ({$code})",
        };
    }
}
