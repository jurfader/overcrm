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
     * Probujemy auto-build, ale flash message wyraznie informuje admina co zrobic
     * jezeli widget/strona modulu nie dziala.
     */
    protected function withRebuildHint(string $msg): string
    {
        $triggered = $this->tryAutoRebuild();
        if ($triggered) {
            return $msg . ' Frontend JS jest przebudowywany w tle (~30s). Jezeli widgety/strony modulu nie dzialaja po odswiezeniu — uruchom recznie na serwerze: cd ' . base_path() . ' && npm run build';
        }
        return $msg . ' UWAGA: Aby widgety/strony modulu zadzialaly, uruchom na serwerze: cd ' . base_path() . ' && npm run build';
    }

    /**
     * Probuje uruchomic 'npm run build' w tle. Webserver PATH zwykle nie zawiera
     * npm/node, wiec probujemy typowe lokalizacje. Zwraca true gdy command poszedl,
     * false gdy nie znaleziono npm.
     */
    protected function tryAutoRebuild(): bool
    {
        if (!function_exists('shell_exec')) return false;

        $candidates = [
            trim((string) @shell_exec('which npm 2>/dev/null')),
            '/usr/local/bin/npm',
            '/usr/bin/npm',
            '/root/.nvm/versions/node/*/bin/npm',
            $_SERVER['HOME'] ?? '' . '/.nvm/versions/node/*/bin/npm',
        ];

        $npm = null;
        foreach ($candidates as $candidate) {
            if (!$candidate) continue;
            $resolved = glob($candidate) ? glob($candidate)[0] : $candidate;
            if (is_executable($resolved)) {
                $npm = $resolved;
                break;
            }
        }

        if (!$npm) {
            \Log::warning('Auto-rebuild skipped: npm not found in webserver PATH');
            return false;
        }

        try {
            $cmd = 'cd ' . escapeshellarg(base_path())
                . ' && nohup ' . escapeshellarg($npm) . ' run build > /tmp/overcrm-build.log 2>&1 &';
            @shell_exec($cmd);
            \Log::info('Triggered npm run build after marketplace install/update', ['npm' => $npm]);
            return true;
        } catch (\Throwable $e) {
            \Log::warning('Auto-rebuild failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
