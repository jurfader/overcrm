<?php

namespace Modules\Apilo\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ApiloService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiloController extends Controller
{
    public function __construct(protected ApiloService $apilo) {}

    public function config(): Response
    {
        return Inertia::render('Apilo/Config', [
            'status' => $this->apilo->getTokenStatus(),
            'credentials' => [
                'subdomain'     => Setting::get('apilo_subdomain', '', 'core'),
                'client_id'     => Setting::get('apilo_client_id', '', 'core'),
                'client_secret' => $this->maskSecret(Setting::get('apilo_client_secret', '', 'core')),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subdomain'     => 'required|string|max:80',
            'client_id'     => 'required|string|max:120',
            'client_secret' => 'nullable|string|max:255', // pusta = nie zmieniaj
        ]);

        Setting::set('apilo_subdomain', trim($data['subdomain']), 'core');
        Setting::set('apilo_client_id', trim($data['client_id']), 'core');
        if (!empty($data['client_secret'])) {
            Setting::set('apilo_client_secret', trim($data['client_secret']), 'core');
        }

        return back()->with('success', 'Dane Apilo zapisane');
    }

    public function authorize(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'authorization_code' => 'required|string|min:8',
        ]);

        $result = $this->apilo->authorizeWithCode(trim($data['authorization_code']));

        if ($result['success'] ?? false) {
            $expiry = $result['expires_at'] ?? '?';
            return back()->with('success', "Autoryzacja udana. Token ważny do: {$expiry}");
        }

        return back()->with('error', $result['message'] ?? 'Autoryzacja nieudana');
    }

    public function refresh(): RedirectResponse
    {
        $result = $this->apilo->forceRefreshToken();
        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function test(): RedirectResponse
    {
        $result = $this->apilo->testConnection();

        if ($result['success'] ?? false) {
            $msg = "Połączenie OK";
            if (!empty($result['account']['name'])) $msg .= ' — konto: ' . $result['account']['name'];
            return back()->with('success', $msg);
        }

        return back()->with('error', "Błąd połączenia: " . ($result['message'] ?? 'unknown'));
    }

    protected function maskSecret(?string $value): string
    {
        if (!$value) return '';
        if (strlen($value) <= 8) return str_repeat('•', strlen($value));
        return str_repeat('•', max(8, strlen($value) - 4)) . substr($value, -4);
    }
}
