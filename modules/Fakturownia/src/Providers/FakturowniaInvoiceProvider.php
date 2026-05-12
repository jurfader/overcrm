<?php

namespace Modules\Fakturownia\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Order;
use App\Models\Setting;
use Modules\Fakturownia\Services\FakturowniaService;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceProvider implementation tworzacy faktury w Fakturownia.pl z Order.
 *
 * Mapowanie:
 *  Order.snapshot_client → Fakturownia buyer
 *  Order.items → Fakturownia positions (kind:'invoice', tax z VAT %)
 *  Order.notes → Fakturownia description
 */
class FakturowniaInvoiceProvider implements InvoiceProvider
{
    public function __construct(protected FakturowniaService $fakturownia) {}

    public function key(): string { return 'fakturownia'; }
    public function label(): string { return 'Fakturownia.pl'; }

    public function isAvailable(): bool
    {
        return $this->fakturownia->isConfigured();
    }

    public function createFromOrder(Order $order): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Fakturownia nie skonfigurowana. Wpisz API token w Modułach → Fakturownia.');
        }

        $order->loadMissing(['items', 'client']);
        $snapshot = $order->snapshot_client ?? [];

        // Fakturownia format — patrz docs https://app.fakturownia.pl/api
        $payload = [
            'invoice' => [
                'kind'           => 'vat',
                'number'         => null, // auto-numeracja w Fakturownia
                'sell_date'      => $order->order_date?->toDateString(),
                'issue_date'     => now()->toDateString(),
                'payment_to'     => now()->addDays(14)->toDateString(),
                'seller_name'    => Setting::get('company_name', null, 'core') ?: 'OVERCRM',
                'seller_tax_no'  => Setting::get('company_nip', null, 'core'),
                'buyer_name'     => $snapshot['name'] ?? $order->client?->name ?? '—',
                'buyer_tax_no'   => $snapshot['nip']  ?? $order->client?->nip,
                'buyer_email'    => $snapshot['email'] ?? $order->client?->email,
                'buyer_post_code'=> $snapshot['postal']  ?? null,
                'buyer_city'     => $snapshot['city']    ?? null,
                'buyer_street'   => $snapshot['address'] ?? null,
                'description'    => $order->notes ? "Zamówienie {$order->number}\n\n{$order->notes}" : "Zamówienie {$order->number}",
                'positions'      => $order->items->map(fn ($item) => [
                    'name'          => $item->name,
                    'quantity'      => (float) $item->quantity,
                    'price_net'     => (float) $item->price_net,
                    'total_price_gross' => null,        // wyliczane przez Fakturownia z price_net + tax
                    'tax'           => (int) $item->vat_rate,
                    'kind'          => 'service',
                    'code'          => $item->sku,
                ])->toArray(),
            ],
        ];

        try {
            $invoice = $this->fakturownia->createInvoice($payload);
        } catch (\Throwable $e) {
            Log::warning('FakturowniaInvoiceProvider createFromOrder exception', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            throw new \RuntimeException('Fakturownia zwróciła błąd: ' . $e->getMessage());
        }

        if (!$invoice || empty($invoice['id'])) {
            throw new \RuntimeException('Fakturownia nie zwróciła ID faktury — sprawdź Logi integracji');
        }

        $subdomain = Setting::get('fakturownia_subdomain', '', 'core');
        return [
            'id'           => $invoice['id'],
            'number'       => $invoice['number'] ?? '?',
            'pdf_url'      => $invoice['view_url'] ?? null,
            'external_url' => $subdomain ? "https://{$subdomain}.fakturownia.pl/invoices/{$invoice['id']}" : null,
        ];
    }
}
