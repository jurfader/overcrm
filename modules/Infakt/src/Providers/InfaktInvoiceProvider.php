<?php

namespace Modules\Infakt\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Order;
use Modules\Infakt\Services\InfaktService;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceProvider z backendem inFakt.pl. Tworzy fakture VAT z Order
 * (snapshot_client + items mapowane na inFakt services z cenami w groszach).
 *
 * Flow:
 *  1. Lookup klienta w inFakt po NIP (findClientByNip)
 *  2. Jezeli nie istnieje — createClient z danych snapshot_client/Client
 *  3. POST faktury async z poll status (max 10s)
 *  4. Zapis invoice_external_id + number do Order (zeby webhooks mogly aktualizowac)
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
        $nip = InfaktService::normalizeNip($snapshot['nip'] ?? $order->client?->nip ?? '');

        // Auto-create klienta w inFakt jezeli ma NIP a klient jeszcze nie istnieje
        if (strlen($nip) >= 10) {
            $existing = $this->infakt->findClientByNip($nip);
            if (!$existing) {
                $this->infakt->createClient($this->buildClientPayload($order, $snapshot, $nip));
            }
        }

        $payload = [
            'sale_date'        => $order->order_date?->toDateString(),
            'invoice_date'     => now()->toDateString(),
            'payment_date'     => now()->addDays(14)->toDateString(),
            'payment_method'   => 'transfer',
            'currency'         => 'PLN',
            'status'           => 'printed',
            'client_company_name' => $snapshot['name'] ?? $order->client?->name ?? '—',
            'client_tax_code'     => $nip,
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
        $uuid = $invoice['uuid'] ?? null;
        $number = $invoice['number'] ?? '?';
        $subdomain = $this->infakt->isSandbox() ? 'sandbox-app' : 'app';

        // Zapis na Order — webhooks beda potem aktualizowac invoice_paid_at / ksef_status
        if ($uuid) {
            $order->update([
                'invoice_provider'    => 'infakt',
                'invoice_external_id' => $uuid,
                'invoice_number'      => $number,
            ]);
        }

        return [
            'id'           => $uuid ?? $result['task_ref'],
            'number'       => $number,
            'pdf_url'      => $uuid ? route('infakt.invoice.pdf', $uuid) : null,
            'external_url' => $uuid ? "https://{$subdomain}.infakt.pl/przychody/faktury/{$uuid}" : null,
        ];
    }

    public function listForClientByNip(string $nip): array
    {
        if (!$this->isAvailable()) return [];
        return $this->infakt->getInvoicesForClientByNip($nip);
    }

    /**
     * Buduje payload klienta do POST /clients.json.
     * Wymagane przez inFakt: business_activity_kind + (company_name lub first/last_name) + country.
     */
    protected function buildClientPayload(Order $order, array $snapshot, string $nip): array
    {
        $client = $order->client;
        $isCompany = !empty($snapshot['name']) || !empty($client?->name);

        return [
            'business_activity_kind' => $isCompany ? 'self_employed' : 'private_person',
            'company_name'           => $snapshot['name'] ?? $client?->name,
            'nip'                    => $nip,
            'email'                  => $snapshot['email'] ?? $client?->email,
            'phone_number'           => $client?->phone,
            'street'                 => $snapshot['address'] ?? $client?->street,
            'city'                   => $snapshot['city'] ?? $client?->city,
            'postal_code'            => $snapshot['postal'] ?? $client?->postal_code,
            'country'                => 'PL',
        ];
    }
}
