<?php

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokuje aplikację gdy licencja missing/expired/invalid.
 * Pozwala na:
 *  - logowanie / wylogowanie (admin musi móc się zalogować by wpisać klucz)
 *  - dostęp do /license (gdzie wpisuje klucz)
 *  - dostęp do /support/* (zgłoszenie problemu)
 *  - publiczne route (cenniki) — bez auth nie wymagamy licencji
 *  - 24h refresh fires automatycznie przed sprawdzeniem (jeśli ostatni check był ponad 24h temu)
 */
class EnforceLicense
{
    /** Routes whitelisted (path-prefix match) — niezależnie od stanu licencji */
    protected array $allowed = [
        'login',
        'logout',
        'license',
        'support',
        'cennik',
        'build-version',
        'up',
        '_debugbar',
    ];

    public function __construct(protected LicenseService $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Whitelisted routes — zawsze pozwól
        if ($this->isAllowed($request)) {
            return $next($request);
        }

        // Lazy refresh: jeśli ostatnia walidacja > 24h temu, odpal w tle
        $this->maybeRefresh();

        if ($this->license->isValid()) {
            return $next($request);
        }

        // Auth users → redirect do /license żeby mogli wpisać/odświeżyć klucz
        if ($request->user()) {
            return redirect()->route('license.show')
                ->with('error', 'Wymagana ważna licencja, aby kontynuować');
        }

        // Niezalogowany — niech idzie do logowania
        return redirect()->route('login');
    }

    protected function isAllowed(Request $request): bool
    {
        $path = trim($request->path(), '/');
        if ($path === '' || $path === '/') return false;

        foreach ($this->allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    protected function maybeRefresh(): void
    {
        $last = \App\Models\Setting::get('license_last_check_at', null);
        if (!$last) return; // brak klucza → już handled przez isValid()

        try {
            $lastCheck = \Carbon\Carbon::parse($last);
            if ($lastCheck->diffInHours(now()) >= 24) {
                $this->license->validate(); // re-fetch
            }
        } catch (\Throwable $e) {
            // ignore — validate() already saves error state
        }
    }
}
