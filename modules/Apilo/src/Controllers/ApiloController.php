<?php

namespace Modules\Apilo\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ClientVisit;
use App\Models\Setting;
use Modules\Apilo\Services\ApiloService;
use Modules\Fakturownia\Services\FakturowniaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ApiloController extends Controller
{
    public function __construct(protected ApiloService $apilo) {}

    public function config(): Response
    {
        return Inertia::render('Apilo/Config', [
            'status' => $this->apilo->getTokenStatus(),
            'credentials' => [
                'subdomain'     => Setting::get('apilo_subdomain', '', 'core'),
                'client_id'     => Setting::get('apilo_client_id', '', 'core'),
                'client_secret' => $this->maskSecret(Setting::get('apilo_client_secret', '', 'core')),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subdomain'     => 'required|string|max:80',
            'client_id'     => 'required|string|max:120',
            'client_secret' => 'nullable|string|max:255', // pusta = nie zmieniaj
        ]);

        Setting::set('apilo_subdomain', trim($data['subdomain']), 'core');
        Setting::set('apilo_client_id', trim($data['client_id']), 'core');
        if (!empty($data['client_secret'])) {
            Setting::set('apilo_client_secret', trim($data['client_secret']), 'core');
        }

        return back()->with('success', 'Dane Apilo zapisane');
    }

    public function authorize(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'authorization_code' => 'required|string|min:8',
        ]);

        $result = $this->apilo->authorizeWithCode(trim($data['authorization_code']));

        if ($result['success'] ?? false) {
            $expiry = $result['expires_at'] ?? '?';
            return back()->with('success', "Autoryzacja udana. Token ważny do: {$expiry}");
        }

        return back()->with('error', $result['message'] ?? 'Autoryzacja nieudana');
    }

    public function refresh(): RedirectResponse
    {
        $result = $this->apilo->forceRefreshToken();
        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function test(): RedirectResponse
    {
        $result = $this->apilo->testConnection();

        if ($result['success'] ?? false) {
            $msg = "Połączenie OK";
            if (!empty($result['account']['name'])) $msg .= ' — konto: ' . $result['account']['name'];
            return back()->with('success', $msg);
        }

        return back()->with('error', "Błąd połączenia: " . ($result['message'] ?? 'unknown'));
    }

    public function orderOptions(Request $request): JsonResponse
    {
        try {
            $options = $this->apilo->getOrderOptions();
            $defaults = ['platform_id' => null, 'payment_type_id' => null];

            $user = $request->user();
            if ($user && $user->apilo_default_platform_id !== null) {
                $want = $user->apilo_default_platform_id;
                foreach ($options['platforms'] ?? [] as $p) {
                    if ((string) ($p['id'] ?? '') === (string) $want) {
                        $defaults['platform_id'] = $p['id'];
                        break;
                    }
                }
            }
            $codId = $this->apilo->findFirstCodPaymentTypeId($options['payment_types'] ?? []);
            if ($codId !== null && $codId !== '') {
                $defaults['payment_type_id'] = $codId;
            }
            $options['defaults'] = $defaults;

            return response()->json($options);
        } catch (\Throwable $e) {
            return response()->json([
                'platforms' => [],
                'payment_types' => [],
                'carriers' => [],
                'defaults' => ['platform_id' => null, 'payment_type_id' => null],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function orderTracking(string $orderId): JsonResponse
    {
        try {
            return response()->json(['links' => $this->apilo->getOrderTrackingLinks($orderId)]);
        } catch (\Throwable $e) {
            Log::warning('apilo.orderTracking error', ['orderId' => $orderId, 'message' => $e->getMessage()]);
            return response()->json(['links' => []], 500);
        }
    }

    public function createOrder(Request $request, ClientVisit $visit): JsonResponse
    {
        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.quantity' => 'required|numeric|min:1',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.product_id' => 'nullable',
            'products.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.nip' => 'nullable|string|max:50',
            'customer.street' => 'nullable|string|max:255',
            'customer.street_number' => 'nullable|string|max:50',
            'customer.zip' => 'nullable|string|max:20',
            'customer.city' => 'nullable|string|max:255',
            'customer.phone' => 'nullable|string|max:50',
            'customer.email' => 'nullable|string|max:255',
            'delivery' => 'nullable|array',
            'delivery.name' => 'nullable|string|max:255',
            'delivery.street' => 'nullable|string|max:255',
            'delivery.street_number' => 'nullable|string|max:50',
            'delivery.zip' => 'nullable|string|max:20',
            'delivery.city' => 'nullable|string|max:255',
            'delivery.phone' => 'nullable|string|max:50',
            'delivery.email' => 'nullable|string|max:255',
            'delivery.inpost_parcel_point' => 'nullable|string|max:20',
            'delivery.inpost_parcel_address' => 'nullable|string|max:255',
            'order_date' => 'nullable|date',
            'order_time' => 'nullable|date_format:H:i',
            'platform_id' => 'nullable',
            'payment_type' => 'nullable',
            'carrier_account' => 'nullable',
        ]);

        $visit->loadMissing('client');
        $nip = $visit->client?->nip ? trim((string) $visit->client->nip) : null;
        $paymentIsCod = $this->apilo->isPaymentTypeLikelyCod($request->input('payment_type'));

        // Blokada Fakturownia: zalegle przelewy (z karencja po terminie) przy zamowieniu nie-COD
        if ($nip && strlen(preg_replace('/\D/', '', $nip)) >= 10 && class_exists(FakturowniaService::class)) {
            try {
                $fakturownia = app(FakturowniaService::class);
                $stats = $fakturownia->getClientPaymentStats($nip);
                if (!$paymentIsCod && ($stats['overdue'] ?? 0) > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nie można dodać zamówienia – klient ma nieopłacone faktury z przekroczonym terminem płatności (powyżej dopuszczalnej karencji). Proszę uregulować zaległości przed dodaniem nowego zamówienia.',
                    ], 422);
                }
            } catch (\Throwable $e) {
                Log::warning('apilo.createOrder: Fakturownia check failed', ['visit_id' => $visit->id, 'message' => $e->getMessage()]);
            }
        }

        try {
            $delivery = $request->delivery ?? [];
            $parcelPoint = $delivery['inpost_parcel_point'] ?? null;
            if (!empty($parcelPoint)) {
                $delivery['inpost_parcel_point'] = strtoupper(trim($parcelPoint));
            }

            $order = $this->apilo->createOrder([
                'client' => $visit->client,
                'products' => $request->products,
                'customer' => $request->customer,
                'delivery' => $delivery,
                'order_date' => $request->order_date,
                'order_time' => $request->order_time,
                'visit_time' => $visit->visit_time,
                'platform_id' => $request->platform_id,
                'payment_type' => $request->payment_type,
                'carrier_account' => $request->carrier_account,
            ]);

            if ($order) {
                $visit->update([
                    'apilo_order_id' => $order['id'] ?? null,
                    'order_value' => $order['total'] ?? 0,
                ]);
                return response()->json(['success' => true, 'order' => $order]);
            }

            return response()->json(['success' => false, 'message' => 'Nie udało się utworzyć zamówienia w Apilo'], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    protected function maskSecret(?string $value): string
    {
        if (!$value) return '';
        if (strlen($value) <= 8) return str_repeat('•', strlen($value));
        return str_repeat('•', max(8, strlen($value) - 4)) . substr($value, -4);
    }
}
