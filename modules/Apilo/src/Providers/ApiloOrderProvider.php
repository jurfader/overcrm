<?php

namespace Modules\Apilo\Providers;

use App\Contracts\OrderProvider;
use App\Models\Client;
use Modules\Apilo\Services\ApiloService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * OrderProvider implementation tworzacy zamowienia w Apilo zamiast lokalnej tabeli orders.
 *
 * Dane wejsciowe (client_id, items, etc.) sa konwertowane na format Apilo Orders API.
 * Apilo zwraca id zamowienia, ktory CRM zapisze jako external reference.
 *
 * supportsPdf = false na MVP — Apilo ma wlasne PDF dostepne w panelu Apilo.
 * Zwracamy external_url jako link do panelu Apilo zeby user mogl tam pobrac.
 */
class ApiloOrderProvider implements OrderProvider
{
    public function __construct(protected ApiloService $apilo) {}

    public function key(): string { return 'apilo'; }
    public function label(): string { return 'Apilo (POST do Apilo orders API)'; }
    public function supportsPdf(): bool { return false; }

    public function create(array $data): array
    {
        $client = Client::find($data['client_id']);

        // Konwersja CRM data → Apilo orders format
        $apiloPayload = [
            'externalId' => 'CRM-' . now()->format('YmdHis') . '-' . ($data['client_id'] ?? 'X'),
            'orderType'  => 'order',
            'status'     => $data['status'] ?? 'new',
            'orderDate'  => $data['order_date'] ?? now()->toDateString(),
            'notes'      => $data['notes'] ?? '',
            'buyer' => $client ? [
                'firstName' => $client->contact_person ?: '',
                'companyName' => $client->name,
                'email'     => $client->email,
                'phone'     => $client->phone,
                'taxId'     => $client->nip,
                'address' => [
                    'street'     => $client->address ?? '',
                    'city'       => $client->city ?? '',
                    'postalCode' => $client->postal_code ?? '',
                    'country'    => 'PL',
                ],
            ] : null,
            'items' => array_map(fn ($i) => [
                'externalId' => $i['product_id'] ?? null,
                'sku'        => $i['sku'] ?? null,
                'name'       => $i['name'],
                'quantity'   => $i['quantity'],
                'priceWithTax' => round($i['price_net'] * (1 + $i['vat_rate'] / 100), 2),
                'tax'        => $i['vat_rate'],
                'unit'       => $i['unit'],
            ], $data['items']),
        ];

        $result = $this->apilo->createOrder($apiloPayload);
        if (!$result || empty($result['id'])) {
            throw new \RuntimeException('Apilo nie zwrocilo ID zamowienia — sprawdz Logi integracji');
        }

        $apiloId = $result['id'];
        $subdomain = config('services.apilo.subdomain') ?? \App\Models\Setting::get('apilo_subdomain', '');
        $externalUrl = $subdomain ? "https://{$subdomain}.apilo.com/order/show/{$apiloId}" : null;

        return [
            'id'           => $apiloId,
            'number'       => $result['orderNumber'] ?? $apiloPayload['externalId'],
            'pdf_url'      => null,
            'external_url' => $externalUrl,
        ];
    }

    public function find(string|int $id): ?array
    {
        try {
            return $this->apilo->getOrder((string) $id);
        } catch (\Throwable $e) {
            Log::warning('ApiloOrderProvider find failed', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function listForClient(int $clientId, int $limit = 50): Collection
    {
        try {
            $orders = $this->apilo->getOrdersForClient($clientId);
        } catch (\Throwable $e) {
            Log::warning('ApiloOrderProvider listForClient failed', ['error' => $e->getMessage()]);
            return collect();
        }

        $subdomain = config('services.apilo.subdomain') ?? \App\Models\Setting::get('apilo_subdomain', '');

        return collect($orders)
            ->take($limit)
            ->map(fn ($o) => [
                'id'           => $o['id'] ?? null,
                'number'       => $o['orderNumber'] ?? $o['externalId'] ?? '?',
                'status'       => $o['status'] ?? 'new',
                'status_label' => ucfirst($o['status'] ?? 'new'),
                'order_date'   => isset($o['orderDate']) ? substr($o['orderDate'], 0, 10) : null,
                'delivery_date'=> null,
                'total_net'    => (float) ($o['totalNet'] ?? 0),
                'total_vat'    => (float) ($o['totalTax'] ?? 0),
                'total_gross'  => (float) ($o['totalGross'] ?? $o['total'] ?? 0),
                'items_count'  => count($o['items'] ?? []),
                'pdf_url'      => null,
                'external_url' => $subdomain && !empty($o['id']) ? "https://{$subdomain}.apilo.com/order/show/{$o['id']}" : null,
            ]);
    }

    public function pdf(string|int $id): Response
    {
        // Apilo PDF generowane po stronie Apilo — redirect na external_url
        $subdomain = config('services.apilo.subdomain') ?? \App\Models\Setting::get('apilo_subdomain', '');
        if ($subdomain) {
            return redirect("https://{$subdomain}.apilo.com/order/show/{$id}");
        }
        abort(404, 'Apilo subdomain not configured');
    }
}
