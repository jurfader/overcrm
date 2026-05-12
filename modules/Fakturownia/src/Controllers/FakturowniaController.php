<?php

namespace Modules\Fakturownia\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FakturowniaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FakturowniaController extends Controller
{
    public function __construct(protected FakturowniaService $fakturownia) {}

    public function config(): Response
    {
        $token = (string) Setting::get('fakturownia_api_token', '', 'core');

        return Inertia::render('Fakturownia/Config', [
            'status' => [
                'configured'    => $this->fakturownia->isConfigured(),
                'subdomain'     => Setting::get('fakturownia_subdomain', '', 'core'),
                'api_token_set' => !empty($token),
                'api_token_mask'=> $this->maskSecret($token),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subdomain' => 'required|string|max:80',
            'api_token' => 'nullable|string|max:255',
        ]);

        Setting::set('fakturownia_subdomain', trim($data['subdomain']), 'core');
        if (!empty($data['api_token'])) {
            Setting::set('fakturownia_api_token', trim($data['api_token']), 'core');
        }

        return back()->with('success', 'Dane Fakturownia zapisane');
    }

    public function test(): RedirectResponse
    {
        $result = $this->fakturownia->testConnection();

        if ($result['success'] ?? false) {
            $msg = 'Połączenie OK';
            if (!empty($result['account']['name'])) $msg .= ' — konto: ' . $result['account']['name'];
            return back()->with('success', $msg);
        }

        return back()->with('error', 'Błąd: ' . ($result['message'] ?? 'unknown'));
    }

    protected function maskSecret(?string $value): string
    {
        if (!$value) return '';
        if (strlen($value) <= 8) return str_repeat('•', strlen($value));
        return str_repeat('•', max(8, strlen($value) - 4)) . substr($value, -4);
    }
}
