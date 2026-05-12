<?php

namespace Modules\Ringostat\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
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
        $projectId = Setting::get('ringostat_project_id', null, 'core');

        return Inertia::render('Ringostat/Config', [
            'status' => [
                'configured'    => $this->service->isConfigured(),
                'auth_key_set'  => !empty($authKey),
                'auth_key_mask' => $this->mask($authKey),
                'project_id'    => $projectId,
                'webhook_url'   => url('/ringostat/webhook'),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auth_key'   => 'nullable|string|max:255',
            'project_id' => 'nullable|integer|min:1',
        ]);

        if (!empty($data['auth_key'])) {
            Setting::set('ringostat_auth_key', trim($data['auth_key']), 'core');
        }
        if (!empty($data['project_id'])) {
            Setting::set('ringostat_project_id', (int) $data['project_id'], 'core');
        }

        return back()->with('success', 'Konfiguracja Ringostat zapisana');
    }

    public function test(): RedirectResponse
    {
        $result = $this->service->testConnection();
        return back()->with($result['success'] ? 'success' : 'error', $result['message'] ?? '');
    }

    /**
     * Click-to-call: wywoluje POST /callback/outward_call.
     * Body: { from: "SIP/wewn. numer pracownika", to: "+48..." }
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|string|max:30',
            'to'   => 'required|string|max:30',
        ]);

        $result = $this->service->callback($data['from'], $data['to']);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Status SIP: kto online + kto teraz dzwoni. Uzywany przez dashboard widget.
     */
    public function sipStatus(): JsonResponse
    {
        return response()->json([
            'online'   => $this->service->sipStatusOnline(),
            'speaking' => $this->service->sipStatusSpeaking(),
        ]);
    }

    /**
     * Lista polaczen z Ringostat (GET /calls/list). Query: date_from, date_to, limit.
     */
    public function listCalls(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'limit'     => 'nullable|integer|min:1|max:1000',
            'page'      => 'nullable|integer|min:1',
        ]);

        return response()->json([
            'calls' => $this->service->listCalls($filters),
        ]);
    }

    /**
     * Bulk sync wszystkich aktywnych klientow + organizacji do Ringostat Smart Phone.
     * Wywolywane manualnie przez admina po wlaczeniu modulu, zeby ksiazka kontaktow
     * RSP zostala wypelniona.
     */
    public function syncAll(Request $request): RedirectResponse
    {
        if (!$this->service->isConfigured()) {
            return back()->with('error', 'Najpierw skonfiguruj auth-key i project-id');
        }

        $clients = Client::query()
            ->whereIn('status', ['active', 'potential'])
            ->limit(1000)
            ->get();

        $contactsSynced = 0;
        $orgsSynced = 0;
        $errors = 0;

        foreach ($clients as $client) {
            $payload = $this->clientToContactPayload($client);
            if ($payload === null) continue;

            $result = $this->service->syncContact($payload);
            if ($result['success'] ?? false) {
                $contactsSynced++;
            } else {
                $errors++;
            }
        }

        $orgs = $clients
            ->where('type', 'company')
            ->map(fn ($c) => $this->clientToOrganizationPayload($c))
            ->filter()
            ->values()
            ->all();

        if (!empty($orgs)) {
            $result = $this->service->syncOrganizations($orgs);
            if ($result['success'] ?? false) {
                $orgsSynced = count($orgs);
            }
        }

        $msg = "Synchronizacja zakonczona — kontaktow: {$contactsSynced}, organizacji: {$orgsSynced}";
        if ($errors > 0) $msg .= ", bledow: {$errors}";

        return back()->with($errors > 0 ? 'warning' : 'success', $msg);
    }

    /**
     * Webhook receiver — Ringostat POSTuje dane polaczenia po zakonczeniu.
     * Skeleton — w pelnej implementacji zapisuje do ringostat_calls_v2.
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('Ringostat webhook received', $request->all());
        // TODO: walidacja podpisu + zapis do DB + match z client przez phone
        return response()->json(['ok' => true]);
    }

    /**
     * Mapuje Client model na payload dla POST /minicrm/contacts/sync.
     * Zwraca null gdy klient nie ma zadnego phone/email (Ringostat wymaga przynajmniej jednego).
     */
    protected function clientToContactPayload(Client $client): ?array
    {
        $directions = [];
        foreach (['phone', 'phone2', 'contact_phone'] as $field) {
            $val = trim((string) ($client->$field ?? ''));
            if ($val !== '') {
                $directions[] = ['type' => 'phone', 'value' => $val];
            }
        }
        foreach (['email', 'contact_email'] as $field) {
            $val = trim((string) ($client->$field ?? ''));
            if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $directions[] = ['type' => 'email', 'value' => $val];
            }
        }

        if (empty($directions)) {
            return null;
        }

        return [
            'fullName'          => $client->name,
            'externalId'        => (string) $client->id,
            'leadId'            => null,
            'responsible'       => (string) ($client->user_id ?? 1),
            'staffId'           => null,
            'contactLink'       => url("/clients/{$client->id}"),
            'leadLink'          => null,
            'dealLink'          => null,
            'googleClientId'    => null,
            'contactDirections' => $directions,
            'organizations'     => $client->type === 'company'
                ? [['name' => $client->name, 'externalId' => 'org-' . $client->id]]
                : null,
        ];
    }

    /**
     * Mapuje Client (company) na payload dla POST /minicrm/organizations/sync.
     */
    protected function clientToOrganizationPayload(Client $client): ?array
    {
        if ($client->type !== 'company') return null;
        return [
            'name'        => $client->name,
            'externalId'  => 'org-' . $client->id,
            'description' => $client->nip ? "NIP {$client->nip}" : null,
        ];
    }

    protected function mask(?string $v): string
    {
        if (!$v) return '';
        if (strlen($v) <= 8) return str_repeat('•', strlen($v));
        return str_repeat('•', max(8, strlen($v) - 4)) . substr($v, -4);
    }
}
