<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LicenseService;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketplace — UI do przegladania i instalacji modulow.
 *
 * Dwa zrodla:
 *  - installed[] z DB (Module::all) — pelne zarzadzanie (aktywacja/dezaktywacja/usuniecie)
 *  - remote[] z license servera (/plugins?product=overcrm) — instalacja jednym klikiem
 *
 * Sa tez stare endpointy /admin/modules (ModuleController) — marketplace
 * jest nowa warstwa nad nimi, nie zastepuje ich.
 */
class MarketplaceController extends Controller
{
    public function __construct(
        protected MarketplaceService $marketplace,
        protected LicenseService $license,
    ) {}

    public function refresh()
    {
        $this->license->forgetMarketplaceCache();
        return back()->with('success', 'Lista modulow odswiezona z serwera');
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Marketplace/Index', [
            'marketplace' => $this->marketplace->snapshot(),
        ]);
    }

    public function install(Request $request)
    {
        $data = $request->validate([
            'plugin_id' => 'required|string|max:80',
        ]);

        $result = $this->marketplace->installFromMarketplace($data['plugin_id']);

        if ($result['success'] ?? false) {
            return back()->with('success', $this->withRebuildHint($result['message'] ?? 'Moduł zainstalowany i aktywowany'));
        }

        $msg = $result['message'] ?? 'Instalacja nieudana';
        if (!empty($result['code'])) $msg .= ' (' . $result['code'] . ')';
        return back()->with('error', $msg);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'plugin_id' => 'required|string|max:80',
        ]);

        $result = $this->marketplace->updateFromMarketplace($data['plugin_id']);

        if ($result['success'] ?? false) {
            return back()->with('success', $this->withRebuildHint($result['message'] ?? 'Moduł zaktualizowany'));
        }

        $msg = $result['message'] ?? 'Aktualizacja nieudana';
        if (!empty($result['code'])) $msg .= ' (' . $result['code'] . ')';
        return back()->with('error', $msg);
    }

    /**
     * Frontend Vue files modulu sa pakowane do JS bundle przez Vite przy buildzie.
     * Po marketplace install/update pliki .vue sa na disku ale jeszcze nie w bundle.
     * Wymagana akcja: 'npm run build' na serwerze. Probujemy uruchomic w tle,
     * jezeli sie nie uda — informujemy admina zeby zrobil to recznie.
     */
    protected function withRebuildHint(string $msg): string
    {
        $this->tryAutoRebuild();
        return $msg . ' — strony Vue zostaja dostepne po rebuildzie JS (npm run build).';
    }

    /**
     * Probuje uruchomic 'npm run build' w tle. Background process, max 60s,
     * blad logowany ale nie blokuje response.
     */
    protected function tryAutoRebuild(): void
    {
        if (!function_exists('proc_open')) return;
        try {
            $cmd = 'cd ' . escapeshellarg(base_path()) . ' && nohup npm run build > /tmp/overcrm-build.log 2>&1 &';
            @shell_exec($cmd);
            \Log::info('Triggered npm run build after marketplace install/update');
        } catch (\Throwable $e) {
            \Log::warning('Auto-rebuild failed', ['error' => $e->getMessage()]);
        }
    }
}
