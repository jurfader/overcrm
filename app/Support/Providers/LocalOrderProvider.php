<?php

namespace App\Support\Providers;

use App\Contracts\OrderProvider;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Brand;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default core order provider — zapisuje do tabeli `orders` + generuje PDF
 * przez DomPDF z Blade template (Iteracja 2).
 */
class LocalOrderProvider implements OrderProvider
{
    public function key(): string { return 'local'; }
    public function label(): string { return 'Lokalne (CORE + PDF)'; }
    public function supportsPdf(): bool { return true; }

    public function create(array $data): array
    {
        $client = Client::findOrFail($data['client_id']);

        $order = DB::transaction(function () use ($data, $client) {
            $order = Order::create([
                'number'           => Order::nextNumber(),
                'client_id'        => $client->id,
                'user_id'          => auth()->id(),
                'status'           => $data['status'] ?? 'new',
                'order_date'       => $data['order_date'],
                'delivery_date'    => $data['delivery_date'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'total_net'        => 0,
                'total_vat'        => 0,
                'total_gross'      => 0,
                'snapshot_company' => $this->companySnapshot(),
                'snapshot_client'  => $this->clientSnapshot($client),
            ]);

            foreach ($data['items'] as $i => $row) {
                $item = new OrderItem([
                    'order_id'   => $order->id,
                    'product_id' => $row['product_id'] ?? null,
                    'name'       => $row['name'],
                    'sku'        => $row['sku'] ?? null,
                    'unit'       => $row['unit'],
                    'quantity'   => $row['quantity'],
                    'price_net'  => $row['price_net'],
                    'vat_rate'   => $row['vat_rate'],
                    'position'   => $i,
                ]);
                $item->recalc();
                $item->save();

                // Decrement stock dla pozycji z magazynu (gdy product ma track_stock=true)
                // i zamówienie nie jest anulowane (cancelled = nie zdejmujemy z magazynu).
                if (!empty($row['product_id']) && ($order->status ?? 'new') !== 'cancelled') {
                    $product = Product::find($row['product_id']);
                    if ($product && $product->track_stock) {
                        $product->decrement('stock', (float) $row['quantity']);
                    }
                }
            }

            $order->load('items');
            $order->recalcTotals();
            return $order;
        });

        return [
            'id'           => $order->id,
            'number'       => $order->number,
            'pdf_url'      => route('orders.pdf', $order->id),
            'external_url' => null,
        ];
    }

    public function find(string|int $id): ?array
    {
        $order = Order::with('items')->find($id);
        return $order?->toArray();
    }

    public function listForClient(int $clientId, int $limit = 50): Collection
    {
        return Order::where('client_id', $clientId)
            ->with(['items', 'user:id,name'])
            ->orderBy('order_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (Order $o) => [
                'id'           => $o->id,
                'number'       => $o->number,
                'status'       => $o->status,
                'status_label' => $o->statusLabel(),
                'order_date'   => $o->order_date?->format('Y-m-d'),
                'delivery_date'=> $o->delivery_date?->format('Y-m-d'),
                'total_net'    => (float) $o->total_net,
                'total_vat'    => (float) $o->total_vat,
                'total_gross'  => (float) $o->total_gross,
                'items_count'  => $o->items->count(),
                'user_name'    => $o->user?->name,
                'pdf_url'      => route('orders.pdf', $o->id),
                'external_url' => null,
            ]);
    }

    public function pdf(string|int $id): Response
    {
        $order = Order::with(['items', 'client', 'user:id,name'])->findOrFail($id);
        $pdf = Pdf::loadView('pdf.order', [
            'order' => $order,
            'brand' => Brand::all(),
        ])->setPaper('a4');

        $filename = 'zamowienie-' . str_replace('/', '-', $order->number) . '.pdf';
        return $pdf->stream($filename);
    }

    protected function companySnapshot(): array
    {
        return [
            'name'         => Setting::get('company_name', null) ?: Brand::get('company_name'),
            'nip'          => Setting::get('company_nip', null),
            'regon'        => Setting::get('company_regon', null),
            'address'      => Setting::get('company_address', null),
            'city'         => Setting::get('company_city', null),
            'postal'       => Setting::get('company_postal', null),
            'phone'        => Setting::get('company_phone', null),
            'email'        => Setting::get('company_email', null),
            'bank_account' => Setting::get('company_bank_account', null),
        ];
    }

    protected function clientSnapshot(Client $client): array
    {
        return [
            'id'             => $client->id,
            'name'           => $client->name,
            'short_name'     => $client->short_name,
            'nip'            => $client->nip,
            'address'        => $client->address,
            'city'           => $client->city,
            'postal'         => $client->postal_code ?? null,
            'phone'          => $client->phone,
            'email'          => $client->email,
            'contact_person' => $client->contact_person ?? null,
        ];
    }
}
