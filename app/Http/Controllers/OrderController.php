<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Brand;
use App\Support\License;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    /** Lista zamówień klienta — JSON dla zakładki w ClientModal */
    public function listByClient(Client $client): JsonResponse
    {
        $orders = $client->orders()
            ->with(['items', 'user:id,name'])
            ->orderBy('order_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(50)
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
                'created_at'   => $o->created_at?->toIso8601String(),
            ]);

        return response()->json(['orders' => $orders]);
    }

    /** Szczegóły jednego zamówienia (do podglądu) */
    public function show(Order $order): JsonResponse
    {
        $order->load(['items.product:id,name,sku', 'client:id,name,short_name', 'user:id,name']);
        return response()->json(['order' => $order]);
    }

    public function store(Request $request): JsonResponse
    {
        License::guard('Tworzenie zamówienia wymaga ważnej licencji');

        $data = $request->validate([
            'client_id'     => 'required|exists:clients,id',
            'order_date'    => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            'status'        => 'nullable|in:draft,new,in_progress,completed,cancelled',
            'notes'         => 'nullable|string|max:5000',
            'items'         => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.name'       => 'required|string|max:200',
            'items.*.sku'        => 'nullable|string|max:60',
            'items.*.unit'       => 'required|string|max:20',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.price_net'  => 'required|numeric|min:0',
            'items.*.vat_rate'   => 'required|integer|in:0,5,8,23',
        ]);

        $client = Client::findOrFail($data['client_id']);

        $order = DB::transaction(function () use ($data, $client, $request) {
            $order = Order::create([
                'number'        => Order::nextNumber(),
                'client_id'     => $client->id,
                'user_id'       => $request->user()?->id,
                'status'        => $data['status'] ?? 'new',
                'order_date'    => $data['order_date'],
                'delivery_date' => $data['delivery_date'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'total_net'     => 0,
                'total_vat'     => 0,
                'total_gross'   => 0,
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
            }

            $order->load('items');
            $order->recalcTotals();

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Zamówienie ' . $order->number . ' utworzone',
            'order'   => $order->fresh(['items']),
        ]);
    }

    /** Generuj PDF dokumentu zamówienia, stream inline (przeglądarka pokaże/pobierze) */
    public function pdf(Order $order): Response
    {
        $order->load(['items', 'client', 'user:id,name']);

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
            'name'    => Setting::get('company_name', null) ?: Brand::get('company_name'),
            'nip'     => Setting::get('company_nip', null),
            'regon'   => Setting::get('company_regon', null),
            'address' => Setting::get('company_address', null),
            'city'    => Setting::get('company_city', null),
            'postal'  => Setting::get('company_postal', null),
            'phone'   => Setting::get('company_phone', null),
            'email'   => Setting::get('company_email', null),
            'bank_account' => Setting::get('company_bank_account', null),
        ];
    }

    protected function clientSnapshot(Client $client): array
    {
        return [
            'id'      => $client->id,
            'name'    => $client->name,
            'short_name' => $client->short_name,
            'nip'     => $client->nip,
            'address' => $client->address,
            'city'    => $client->city,
            'postal'  => $client->postal_code ?? null,
            'phone'   => $client->phone,
            'email'   => $client->email,
            'contact_person' => $client->contact_person ?? null,
        ];
    }
}
