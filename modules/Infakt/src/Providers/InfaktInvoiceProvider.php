<?php

namespace Modules\Infakt\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Order;
use App\Models\Setting;
use Modules\Infakt\Services\InfaktService;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceProvider z backendem inFakt.pl. Tworzy fakture VAT z Order
 * (snapshot_client + items mapowane na inFakt services z cenami w groszach).
 */
class InfaktInvoiceProvider implements InvoiceProvider
{
    public function __construct(protected InfaktService $infakt) {}

    public function key(): string { return 'infakt'; }
    public function label(): string { return 'inFakt.pl'; }

    public function isAvailable(): bool
    {
        return $this->infakt->isConfigured();
    }

    public function createFromOrder(Order $order): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('inFakt nie skonfigurowany. Wpisz API key w Modulach -> inFakt.');
        }

        $order->loadMissing(['items', 'client']);
        $snapshot = $order->snapshot_client ?? [];

        $payload = [
            'sale_date'        => $order->order_date?->toDateString(),
            'invoice_date'     => now()->toDateString(),
            'payment_date'     => now()->addDays(14)->toDateString(),
            'payment_method'   => 'transfer',
            'currency'         => 'PLN',
            'status'           => 'printed',
            'client_company_name' => $snapshot['name'] ?? $order->client?->name ?? '—',
            'client_tax_code'     => InfaktService::normalizeNip($snapshot['nip'] ?? $order->client?->nip ?? ''),
            'client_email'        => $snapshot['email'] ?? $order->client?->email,
            'client_post_code'    => $snapshot['postal'] ?? null,
            'client_city'         => $snapshot['city'] ?? null,
            'client_street'       => $snapshot['address'] ?? null,
            'client_country'      => 'PL',
            'notes'               => $order->notes ? "Zamowienie {$order->number}\n\n{$order->notes}" : "Zamowienie {$order->number}",
            'services'            => $order->items->map(fn ($item) => [
                'name'           => $item->name,
                'pkwiu'          => null,
                'quantity'       => (float) $item->quantity,
                'unit'           => $item->unit ?: 'szt',
                'unit_net_price' => (int) round(((float) $item->price_net) * 100), // grosze
                'tax_symbol'     => (string) (int) $item->vat_rate,
            ])->toArray(),
        ];

        $result = $this->infakt->createInvoice($payload, false, true);
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('inFakt: ' . ($result['message'] ?? 'unknown error'));
        }

        $invoice = $result['invoice'] ?? [];
        $subdomain = $this->infakt->isSandbox() ? 'sandbox-app' : 'app';
        $uuid = $invoice['uuid'] ?? null;

        return [
            'id'           => $uuid ?? $result['task_ref'],
            'number'       => $invoice['number'] ?? '?',
            'pdf_url'      => $uuid ? route('infakt.invoice.pdf', $uuid) : null,
            'external_url' => $uuid ? "https://{$subdomain}.infakt.pl/przychody/faktury/{$uuid}" : null,
        ];
    }

    public function listForClientByNip(string $nip): array
    {
        if (!$this->isAvailable()) return [];
        return $this->infakt->getInvoicesForClientByNip($nip);
    }
}
