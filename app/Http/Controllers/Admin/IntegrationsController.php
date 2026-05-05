<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    public function __construct(protected ProviderRegistry $registry) {}

    /**
     * Inertia shared `providersConfig` zwracane już w SettingController::index pod
     * tab activeGroup='integrations'. Ten controller obsługuje tylko save (POST).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider_product' => 'required|string|max:50',
            'provider_order'   => 'required|string|max:50',
            'provider_invoice' => 'required|string|max:50',
        ]);

        try {
            foreach (['product', 'order', 'invoice'] as $cat) {
                $this->registry->setActive($cat, $data['provider_' . $cat]);
            }
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Provider configuration zapisana');
    }
}
