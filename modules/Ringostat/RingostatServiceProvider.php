<?php

namespace Modules\Ringostat;

use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\Ringostat\Services\RingostatNetService;

/**
 * Modul Ringostat.net (call tracking + Smart Phone).
 *
 * Po wlaczeniu modulu admin wpisuje auth-key + project-id (panel Ringostat
 * → Integracje → API/Webhooks). Webhook URL z config kopiuje do Ringostat.
 *
 * Auto-sync: po kazdym zapisie Client::saved synchronizuje kontakt do
 * Ringostat Smart Phone (afterResponse zeby nie blokowac requesta).
 */
class RingostatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RingostatNetService::class);
    }

    public function boot(): void
    {
        Client::saved(function (Client $client) {
            // Trzymamy referencje do serwisu poza closure dispatcha (po requestcie
            // nadal jest dostepne app() ale singleton jest bezpieczniejszy).
            dispatch(function () use ($client) {
                $service = app(RingostatNetService::class);
                if (!$service->isConfigured()) return;

                $payload = $this->clientToContactPayload($client);
                if ($payload === null) return;

                $result = $service->syncContact($payload);
                if (!($result['success'] ?? false)) {
                    Log::info('Ringostat auto-sync failed', [
                        'client_id' => $client->id,
                        'message'   => $result['message'] ?? '?',
                    ]);
                }
            })->afterResponse();
        });
    }

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

        if (empty($directions)) return null;

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
}
