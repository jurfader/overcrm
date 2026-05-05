<?php

namespace App\Support;

use App\Services\LicenseService;

/**
 * Cienki helper nad LicenseService — ułatwia rozproszone sprawdzanie licencji
 * w wielu miejscach kodu (Etap 2a anti-piracy).
 *
 * Im więcej miejsc wywołuje License::ok() / ::guard(), tym trudniej obejść
 * piratowi (musi znaleźć i wykomentować KAŻDE z nich, nie jedno).
 *
 * Cache na poziomie instancji request: w jednym requeście isValid liczone raz.
 */
class License
{
    protected static ?bool $cached = null;

    public static function ok(): bool
    {
        if (self::$cached !== null) return self::$cached;
        return self::$cached = app(LicenseService::class)->isValid();
    }

    /** Wymuś abort 402 jeśli licencja invalid. Używać w krytycznych write actions. */
    public static function guard(string $reason = 'Wymagana ważna licencja'): void
    {
        if (!self::ok()) {
            abort(402, $reason);
        }
    }

    public static function reset(): void { self::$cached = null; }
}
