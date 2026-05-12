<?php

namespace Modules\Ringostat\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient Ringostat.net API.
 *
 * Auth: `auth-key` (header) + `x-project-id` (header) — obie wymagane.
 *
 * Endpointy:
 *  - POST /a/v2                          callback (zaawansowana metoda)
 *  - POST /callback/outward_call         callback (prosta metoda — click-to-call)
 *  - GET  /calls/list                    eksport polaczen
 *  - GET  /sipstatus/online              konta SIP online
 *  - GET  /sipstatus/speaking            konta SIP w trakcie rozmowy
 *  - POST /minicrm/contacts/sync         sync kontaktow do Ringostat Smart Phone
 *  - POST /minicrm/organizations/sync    sync organizacji do RSP
 */
class RingostatNetService
{
    protected string $authKey;
    protected ?int $projectId;
    protected string $apiBase = 'https://api.ringostat.net';

    public function __construct()
    {
        $this->authKey  = (string) Setting::get('ringostat_auth_key', '', 'core');
        $projectId      = Setting::get('ringostat_project_id', null, 'core');
        $this->projectId = is_numeric($projectId) ? (int) $projectId : null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->authKey) && $this->projectId !== null;
    }

    public function projectId(): ?int
    {
        return $this->projectId;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Brak auth-key lub project-id'];
        }

        try {
            $response = $this->client()->get("{$this->apiBase}/sipstatus/online");

            if ($response->successful()) {
                $count = is_array($response->json()) ? count($response->json()) : 0;
                return ['success' => true, 'message' => "Polaczenie OK — {$count} kont SIP online"];
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
     * Callback zaawansowana metoda (POST /a/v2). Pozwala precyzyjnie sterowac:
     * z jakiego numeru, do jakiego, scenariuszem dzwonkow, itd.
     *
     * @param array $payload doc Ringostat: { from, to, scenario_id?, ... }
     */
    public function callbackAdvanced(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ringostat nie skonfigurowany'];
        }

        try {
            $response = $this->client()->post("{$this->apiBase}/a/v2", $payload);

            if ($response->successful()) {
                return ['success' => true, 'response' => $response->json()];
            }
            return ['success' => false, 'message' => 'HTTP ' . $response->status(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            Log::warning('Ringostat callbackAdvanced exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Prosty click-to-call. POST /callback/outward_call z {from, to}.
     * `from` to wewn. numer/SIP pracownika, `to` to numer klienta.
     */
    public function callback(string $from, string $to): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ringostat nie skonfigurowany'];
        }

        try {
            $response = $this->client()->post("{$this->apiBase}/callback/outward_call", [
                'from' => $from,
                'to'   => $to,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return ['success' => true, 'call_id' => $data['call_id'] ?? null, 'response' => $data];
            }

            return ['success' => false, 'message' => 'HTTP ' . $response->status(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            Log::warning('Ringostat callback exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Eksport polaczen (GET /calls/list). Filters: date_from, date_to, limit, page, ...
     */
    public function listCalls(array $filters = []): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $response = $this->client()->get("{$this->apiBase}/calls/list", $filters);

            if ($response->successful()) {
                $body = $response->json();
                return is_array($body) ? ($body['calls'] ?? $body['data'] ?? $body) : [];
            }
            Log::warning('Ringostat listCalls HTTP error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return [];
        } catch (\Throwable $e) {
            Log::warning('Ringostat listCalls exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Konta SIP aktualnie online (GET /sipstatus/online).
     */
    public function sipStatusOnline(): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/sipstatus/online");
            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (\Throwable $e) {
            Log::warning('Ringostat sipStatusOnline exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Konta SIP aktualnie w trakcie rozmowy (GET /sipstatus/speaking).
     */
    public function sipStatusSpeaking(): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/sipstatus/speaking");
            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (\Throwable $e) {
            Log::warning('Ringostat sipStatusSpeaking exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Sync kontaktu do Ringostat Smart Phone (POST /minicrm/contacts/sync).
     *
     * Wymagane pola:
     *  - projectId (auto-wstrzykiwane)
     *  - fullName
     *  - origin (nazwa CRM, np. 'OVERCRM')
     *  - externalId LUB leadId (przynajmniej jedno)
     *  - responsible (ID pracownika w CRM)
     *  - contactDirections[] (przynajmniej jeden phone/email)
     *
     * Opcjonalne: staffId, contactLink, leadLink, dealLink, googleClientId, organizations[].
     */
    public function syncContact(array $contact): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ringostat nie skonfigurowany'];
        }

        $payload = array_merge([
            'projectId' => $this->projectId,
            'origin'    => 'OVERCRM',
        ], $contact);

        try {
            $response = $this->client()->post("{$this->apiBase}/minicrm/contacts/sync", $payload);

            if ($response->successful()) {
                return ['success' => true, 'response' => $response->json()];
            }
            Log::warning('Ringostat syncContact HTTP error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return ['success' => false, 'message' => 'HTTP ' . $response->status(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            Log::warning('Ringostat syncContact exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sync organizacji do RSP (POST /minicrm/organizations/sync).
     *
     * Payload: { organizations: [ { ... } ] }
     */
    public function syncOrganizations(array $organizations): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Ringostat nie skonfigurowany'];
        }

        try {
            $response = $this->client()->post("{$this->apiBase}/minicrm/organizations/sync", [
                'organizations' => $organizations,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'count' => count($organizations), 'response' => $response->json()];
            }
            Log::warning('Ringostat syncOrganizations HTTP error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return ['success' => false, 'message' => 'HTTP ' . $response->status(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            Log::warning('Ringostat syncOrganizations exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function client(): PendingRequest
    {
        return Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'auth-key'     => $this->authKey,
                'x-project-id' => (string) $this->projectId,
            ]);
    }
}
