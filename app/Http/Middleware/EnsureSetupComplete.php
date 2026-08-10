<?php

namespace App\Http\Middleware;

use App\Services\SetupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokuje aplikację do czasu ukończenia kreatora pierwszego uruchomienia.
 *
 *  - admin/developer  → redirect na /setup
 *  - pozostali userzy → ekran "Trwa konfiguracja" (mogą się tylko wylogować)
 *  - demo             → bypass (template DB jest zaseedowany, kreator zbędny)
 *
 * Musi stać PRZED EnforceLicense w stacku: pierwszym krokiem kreatora jest
 * aktywacja licencji, więc /setup jest też whitelistowane w EnforceLicense.
 */
class EnsureSetupComplete
{
    /** Ścieżki dostępne mimo nieukończonego setupu (prefix match) */
    protected array $allowed = [
        'setup',
        'login',
        'logout',
        'two-factor',
        'license',
        'support',
        'cennik',
        'build-version',
        'up',
        '_debugbar',
    ];

    public function __construct(protected SetupService $setup) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('demo.enabled')) {
            return $next($request);
        }

        // Świeży klon repo przed `php artisan migrate` — nie ma gdzie sprawdzić stanu.
        if (! $this->settingsTableReady()) {
            return $next($request);
        }

        if ($this->setup->isComplete() || $this->isAllowed($request)) {
            return $next($request);
        }

        // Niezalogowani lecą swoją zwykłą ścieżką (auth middleware → /login)
        if (! $request->user()) {
            return $next($request);
        }

        if (! $request->user()->hasAdminRights()) {
            return Inertia::render('Setup/Pending', ['brand' => brand()])->toResponse($request);
        }

        return redirect()->route('setup.show');
    }

    protected function settingsTableReady(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isAllowed(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if ($path === '' || $path === '/') {
            return false;
        }

        foreach ($this->allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
