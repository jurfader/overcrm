<?php

namespace App\Http\Controllers;

use App\Contracts\OrderProvider;
use App\Models\Client;
use App\Models\Order;
use App\Support\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cienki controller — deleguje całą logikę do aktywnego OrderProvider
 * (LocalOrderProvider domyślnie, Apilo/Baselinker po wyborze w Settings).
 */
class OrderController extends Controller
{
    public function __construct(protected OrderProvider $orders) {}

    public function listByClient(Client $client): JsonResponse
    {
        return response()->json([
            'orders' => $this->orders->listForClient($client->id),
            'provider' => [
                'key'           => $this->orders->key(),
                'label'         => $this->orders->label(),
                'supports_pdf'  => $this->orders->supportsPdf(),
            ],
        ]);
    }

    public function show(string|int $id): JsonResponse
    {
        $order = $this->orders->find($id);
        abort_if(!$order, 404);
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
            'items.*.product_id' => 'nullable',
            'items.*.name'       => 'required|string|max:200',
            'items.*.sku'        => 'nullable|string|max:60',
            'items.*.unit'       => 'required|string|max:20',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.price_net'  => 'required|numeric|min:0',
            'items.*.vat_rate'   => 'required|integer|in:0,5,8,23',
        ]);

        try {
            $result = $this->orders->create($data);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Provider zwrócił błąd: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zamówienie ' . ($result['number'] ?? '?') . ' utworzone w: ' . $this->orders->label(),
            'order'   => $result,
        ]);
    }

    public function pdf(string|int $id): Response
    {
        if (!$this->orders->supportsPdf()) {
            abort(404, 'Aktywny provider zamówień (' . $this->orders->label() . ') nie obsługuje PDF');
        }
        return $this->orders->pdf($id);
    }
}
