<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    public function __construct(protected MarketplaceService $marketplace) {}

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
            return back()->with('success', $result['message'] ?? 'Moduł zainstalowany i aktywowany');
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
            return back()->with('success', $result['message'] ?? 'Moduł zaktualizowany');
        }

        $msg = $result['message'] ?? 'Aktualizacja nieudana';
        if (!empty($result['code'])) $msg .= ' (' . $result['code'] . ')';
        return back()->with('error', $msg);
    }
}
