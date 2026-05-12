<?php

namespace Modules\Ringostat\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient Ringostat.net API (NIE Play Centrala — to osobny moduł).
 *
 * Dokumentacja: https://help.ringostat.com/en/collections/106929
 *   - Callback API:  POST https://api.ringostat.net/callback/outward_call
 *   - Expanded API:  POST https://api.ringostat.net/a/v2/...
 *   - Webhooks: incoming, outbound, AI processed
 *
 * Auth: unique Auth-key per projekt (zapisany w Settings 'ringostat_auth_key').
 *
 * Stan: skeleton. Pełna implementacja w kolejnej iteracji — na razie tylko
 * isConfigured + testConnection + callback (click-to-call).
 */
class RingostatNetService
{
    protected string $authKey;
    protected string $apiBase = 'https://api.ringostat.net';

    public function __construct()
    {
        $this->authKey = (string) Setting::get('ringostat_auth_key', '', 'core');
    }

    public function isConfigured(): bool
    {
        return !empty($this->authKey);
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Brak Auth-key'];
        }

        try {
            // Ringostat nie ma dedykowanego /ping — używamy GET statystyk z limitem 1
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("{$this->apiBase}/a/v2/calls", [
                    'auth_key' => $this->authKey,
                    'limit'    => 1,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Połączenie OK'];
            }

            return [
                'success' => false,
                'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Inicjuje click-to-call (callback Ringostat).
     * POST /callback/outward_call z { from, to, auth_key }.
     *
     * @return array{success: bool, message?: string, call_id?: string}
     */
    public function callback(string $from, string $to): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ringostat nie skonfigurowany'];
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->post("{$this->apiBase}/callback/outward_call", [
                    'auth_key' => $this->authKey,
                    'from'     => $from,
                    'to'       => $to,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return ['success' => true, 'call_id' => $data['call_id'] ?? null];
            }

            return [
                'success' => false,
                'message' => 'HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Ringostat callback exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
