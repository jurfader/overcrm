<?php

namespace Modules\Ringostat\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ringostat\Services\RingostatNetService;

class RingostatController extends Controller
{
    public function __construct(protected RingostatNetService $service) {}

    public function config(): Response
    {
        $authKey = (string) Setting::get('ringostat_auth_key', '', 'core');

        return Inertia::render('Ringostat/Config', [
            'status' => [
                'configured'    => $this->service->isConfigured(),
                'auth_key_set'  => !empty($authKey),
                'auth_key_mask' => $this->mask($authKey),
                'webhook_url'   => url('/ringostat/webhook'),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auth_key' => 'nullable|string|max:255',
        ]);

        if (!empty($data['auth_key'])) {
            Setting::set('ringostat_auth_key', trim($data['auth_key']), 'core');
        }

        return back()->with('success', 'Konfiguracja Ringostat zapisana');
    }

    public function test(): RedirectResponse
    {
        $result = $this->service->testConnection();
        return back()->with($result['success'] ? 'success' : 'error', $result['message'] ?? '');
    }

    /**
     * Webhook receiver — Ringostat POSTuje dane połączenia po zakończeniu.
     * Skeleton — w pełnej implementacji zapisuje do ringostat_calls_v2 + dispatch event.
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('Ringostat webhook received', $request->all());
        // TODO: walidacja podpisu + zapis do DB + match z client przez phone
        return response()->json(['ok' => true]);
    }

    protected function mask(?string $v): string
    {
        if (!$v) return '';
        if (strlen($v) <= 8) return str_repeat('•', strlen($v));
        return str_repeat('•', max(8, strlen($v) - 4)) . substr($v, -4);
    }
}
