<?php

namespace Modules\Apilo\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Order;
use Modules\Apilo\Services\ApiloService;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceProvider implementation tworzacy faktury w Apilo (endpoint Finance Documents).
 *
 * Apilo API: POST /rest/api/v1/finance/document/ — tworzy fakture sprzedazy / paragon
 * powiazany z zamowieniem.
 *
 * Wywolywane gdy admin: a) wybierze 'apilo' jako Invoice provider w Settings,
 * b) zamknie zamowienie statusem 'completed' (auto, jezeli zamowienie idzie przez
 * ApiloOrderProvider) lub c) klika "Wystaw fakture" w UI zamowien.
 */
class ApiloInvoiceProvider implements InvoiceProvider
{
    public function __construct(protected ApiloService $apilo) {}

    public function key(): string { return 'apilo'; }
    public function label(): string { return 'Apilo (Finance Documents)'; }

    public function isAvailable(): bool
    {
        return $this->apilo->isConfigured();
    }

    public function createFromOrder(Order $order): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Apilo nie jest skonfigurowany. Włącz moduł i wpisz dane w Konfiguracji.');
        }

        $order->loadMissing(['items', 'client']);

        $payload = [
            'documentType'  => 'INVOICE',           // INVOICE | RECEIPT | PROFORMA
            'numberFromOrder' => true,
            'orderId'       => null,                // ustawiamy gdy znamy Apilo orderId
            'externalOrderId' => $order->number,
            'issueDate'     => now()->toDateString(),
            'saleDate'      => $order->order_date?->toDateString(),
            'paymentMethod' => 'transfer',
            'currency'      => 'PLN',
            'buyer' => [
                'companyName' => $order->client?->name,
                'taxId'       => $order->client?->nip,
                'address'     => $order->client?->address,
                'city'        => $order->client?->city,
                'postalCode'  => $order->client?->postal_code,
                'email'       => $order->client?->email,
            ],
            'items' => $order->items->map(fn ($item) => [
                'name'         => $item->name,
                'sku'          => $item->sku,
                'quantity'     => (float) $item->quantity,
                'unit'         => $item->unit,
                'priceWithTax' => (float) $item->price_net * (1 + $item->vat_rate / 100),
                'tax'          => $item->vat_rate,
            ])->toArray(),
        ];

        $response = $this->apilo->createFinanceDocument($payload);
        if (!$response || empty($response['id'])) {
            throw new \RuntimeException('Apilo nie utworzyło dokumentu — sprawdź Logi integracji');
        }

        $subdomain = config('services.apilo.subdomain') ?? \App\Models\Setting::get('apilo_subdomain', '');
        return [
            'id'           => $response['id'],
            'number'       => $response['number'] ?? $response['documentNumber'] ?? '?',
            'pdf_url'      => $response['pdfUrl'] ?? null,
            'external_url' => $subdomain ? "https://{$subdomain}.apilo.com/finance-document/show/{$response['id']}" : null,
        ];
    }

    /**
     * Pobiera dokumenty finansowe powiązane z zamówieniem Apilo.
     * Używane w UI admin/orders expandable row żeby pokazać linki do faktur.
     */
    public function listForApiloOrder(string $apiloOrderId): array
    {
        return $this->apilo->getFinanceDocuments(['orderId' => $apiloOrderId]);
    }

    public function listForClientByNip(string $nip): array
    {
        // Apilo nie ma endpointu listowania faktur po NIP — to po stronie Apilo
        // jest powiazane przez orderId, nie przez NIP. Caller (ProviderRegistry consumer)
        // dostaje pusty array, jesli potrzebuje faktur po NIP musi uzyc Fakturownia.
        return [];
    }
}
