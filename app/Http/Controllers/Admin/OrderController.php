<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin centrum zamówień — lista wszystkich zamówień (ze wszystkich klientów),
 * filtrowanie, edycja statusu, soft-delete. Działa NIEZALEŻNIE od OrderProvider —
 * zawsze czyta z lokalnej tabeli `orders` (bo zewnętrzne providery tworzą tam
 * snapshot/cache lub nie są aktywne dla raportowania historii).
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->with(['client:id,name,short_name', 'user:id,name', 'items'])
            ->when($request->get('q'), fn($q, $term) => $q
                ->where(fn($w) => $w
                    ->where('number', 'like', "%{$term}%")
                    ->orWhereHas('client', fn($c) => $c
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('nip', 'like', "%{$term}%")
                    )
                )
            )
            ->when($request->get('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->get('client_id'), fn($q, $c) => $q->where('client_id', $c))
            ->when($request->get('from'), fn($q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($request->get('to'),   fn($q, $d) => $q->whereDate('order_date', '<=', $d))
            ->orderBy('order_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Order $o) => [
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
                'items'        => $o->items->map(fn ($item) => [
                    'id'          => $item->id,
                    'name'        => $item->name,
                    'sku'         => $item->sku,
                    'unit'        => $item->unit,
                    'quantity'    => (float) $item->quantity,
                    'price_net'   => (float) $item->price_net,
                    'vat_rate'    => $item->vat_rate,
                    'total_net'   => (float) $item->total_net,
                    'total_vat'   => (float) $item->total_vat,
                    'total_gross' => (float) $item->total_gross,
                ]),
                'notes'        => $o->notes,
                'client'       => $o->client ? [
                    'id'   => $o->client->id,
                    'name' => $o->client->short_name ?: $o->client->name,
                ] : null,
                'user_name'    => $o->user?->name,
                'created_at'   => $o->created_at?->toIso8601String(),
            ]);

        $stats = [
            'all'         => Order::count(),
            'new'         => Order::where('status', 'new')->count(),
            'in_progress' => Order::where('status', 'in_progress')->count(),
            'completed'   => Order::where('status', 'completed')->count(),
            'gross_month' => (float) Order::whereMonth('order_date', now()->month)
                ->whereYear('order_date', now()->year)
                ->sum('total_gross'),
        ];

        return Inertia::render('Admin/Orders/Index', [
            'orders'  => $orders,
            'stats'   => $stats,
            'filters' => $request->only(['q', 'status', 'client_id', 'from', 'to']),
            'statuses' => [
                'draft'       => 'Szkic',
                'new'         => 'Nowe',
                'in_progress' => 'W realizacji',
                'completed'   => 'Zrealizowane',
                'cancelled'   => 'Anulowane',
            ],
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        License::guard('Edycja zamówienia wymaga ważnej licencji');
        $data = $request->validate([
            'status' => 'required|in:draft,new,in_progress,completed,cancelled',
        ]);

        $oldStatus = $order->status;
        $newStatus = $data['status'];

        DB::transaction(function () use ($order, $oldStatus, $newStatus) {
            $order->update(['status' => $newStatus]);

            // Stock adjustment przy zmianie statusu:
            // - aktywne → cancelled = restore (oddaj na magazyn)
            // - cancelled → aktywne = re-decrement (zdejmij z magazynu)
            if ($oldStatus !== 'cancelled' && $newStatus === 'cancelled') {
                $this->adjustStock($order, +1);
            } elseif ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
                $this->adjustStock($order, -1);
            }
        });

        return back()->with('success', 'Status zamówienia zaktualizowany');
    }

    public function destroy(Order $order): RedirectResponse
    {
        DB::transaction(function () use ($order) {
            // Soft-delete aktywnego zamówienia = oddaj stock na magazyn
            // (cancelled już oddało stock w updateStatus, nie podwajamy).
            if ($order->status !== 'cancelled') {
                $this->adjustStock($order, +1);
            }
            $order->delete();
        });
        return back()->with('success', 'Zamówienie usunięte (soft-delete)');
    }

    /**
     * Modyfikuje stock produktów z pozycji zamówienia.
     * @param int $direction +1 = restore (oddaj na magazyn), -1 = decrement (zdejmij)
     */
    protected function adjustStock(Order $order, int $direction): void
    {
        try {
            foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
                $product = Product::find($item->product_id);
                if ($product && $product->track_stock) {
                    $delta = $direction * (float) $item->quantity;
                    if ($delta >= 0) {
                        $product->increment('stock', $delta);
                    } else {
                        $product->decrement('stock', abs($delta));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Stock adjustment nie powinien blokowac glownej operacji (delete/status update).
            Log::warning('adjustStock failed', [
                'order_id' => $order->id,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
