<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Traits\LogsApiCalls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiloService
{
    use LogsApiCalls;

    private string $subdomain;

    private string $clientId;

    private string $clientSecret;

    private string $accessToken;

    private string $refreshToken;

    private string $baseUrl;

    public function __construct()
    {
        // Najpierw sprawdź env, potem ustawienia z bazy (jak Fakturownia)
        $this->subdomain = config('services.apilo.subdomain', '');
        $this->clientId = config('services.apilo.client_id', '');
        $this->clientSecret = config('services.apilo.client_secret', '');

        // Fallback do admin settings
        if (empty($this->subdomain)) {
            $this->subdomain = Setting::get('apilo_subdomain', '', 'core') ?? '';
        }
        if (empty($this->clientId)) {
            $this->clientId = Setting::get('apilo_client_id', '', 'core') ?? '';
        }
        if (empty($this->clientSecret)) {
            $this->clientSecret = Setting::get('apilo_client_secret', '', 'core') ?? '';
        }

        // Access token i refresh token — zawsze z bazy (bo się odświeżają)
        $this->accessToken = Setting::get('apilo_access_token', '', 'core') ?? '';
        $this->refreshToken = Setting::get('apilo_refresh_token', '', 'core') ?? '';

        // URL bazowy
        $this->baseUrl = "https://{$this->subdomain}.apilo.com";
    }

    /**
     * Czy integracja jest skonfigurowana
     */
    public function isConfigured(): bool
    {
        return ! empty($this->subdomain) && ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Pobierz ważny access token (odśwież jeśli wygasł)
     */
    private function getAccessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // Sprawdź czy token jest ważny
        $expiresAt = Setting::get('apilo_access_token_expires_at', '', 'core');
        if (! empty($this->accessToken) && ! empty($expiresAt) && now()->lt($expiresAt)) {
            return $this->accessToken;
        }

        // Token wygasł lub brak — odśwież za pomocą refresh token
        if (! empty($this->refreshToken)) {
            return $this->refreshAccessToken();
        }

        Log::warning('Apilo: Brak ważnego access token i refresh token. Skonfiguruj ponownie w ustawieniach.');

        return null;
    }

    /**
     * Odśwież access token za pomocą refresh token
     */
    private function refreshAccessToken(): ?string
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->acceptJson()
                ->post("{$this->baseUrl}/rest/auth/token/", [
                    'grantType' => 'refresh_token',
                    'token' => $this->refreshToken,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                $this->accessToken = $data['accessToken'];
                $this->refreshToken = $data['refreshToken'];

                // Zapisz nowe tokeny do bazy
                Setting::set('apilo_access_token', $this->accessToken, 'core');
                Setting::set('apilo_refresh_token', $this->refreshToken, 'core');
                Setting::set('apilo_access_token_expires_at', $data['accessTokenExpireAt'] ?? '', 'core');

                Log::info('Apilo: Access token odświeżony pomyślnie.');

                return $this->accessToken;
            }

            Log::error('Apilo Auth Refresh Error: '.$response->status().' - '.$response->body());
        } catch (\Exception $e) {
            Log::error('Apilo Auth Refresh Exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Pierwsza autoryzacja za pomocą authorization_code
     * Wywoływane z panelu admin po podaniu kodu
     */
    public function authorizeWithCode(string $authorizationCode): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->acceptJson()
                ->post("{$this->baseUrl}/rest/auth/token/", [
                    'grantType' => 'authorization_code',
                    'token' => $authorizationCode,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Zapisz tokeny
                Setting::set('apilo_access_token', $data['accessToken'], 'core');
                Setting::set('apilo_refresh_token', $data['refreshToken'], 'core');
                Setting::set('apilo_access_token_expires_at', $data['accessTokenExpireAt'] ?? '', 'core');

                $this->accessToken = $data['accessToken'];
                $this->refreshToken = $data['refreshToken'];

                return [
                    'success' => true,
                    'message' => 'Autoryzacja Apilo zakończona pomyślnie.',
                    'expires_at' => $data['accessTokenExpireAt'] ?? '',
                ];
            }

            return [
                'success' => false,
                'message' => 'Błąd autoryzacji: '.($response->json()['message'] ?? $response->body()),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Błąd połączenia: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Test połączenia z Apilo
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Brak konfiguracji Apilo (subdomena, client_id, client_secret)',
            ];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return [
                'success' => false,
                'message' => 'Nie udało się uzyskać access tokenu. Sprawdź refresh token.',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/whoami/");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Połączenie z Apilo aktywne.',
                    'info' => $data['content'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => 'Błąd: '.$response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Błąd połączenia: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Pobierz zamówienia dla klienta (znormalizowane + info o trackingu)
     * Pobiera po wszystkich adresach email (email + contact_email) i scala wyniki – nowe zamówienia mogły być na inny adres
     */
    public function getOrdersForClient(int $clientId): array
    {
        if (! $this->isConfigured()) {
            return $this->getMockOrders($clientId);
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }

        try {
            $client = Client::find($clientId);
            if (! $client) {
                return [];
            }

            $params = [
                'limit' => 100,
                'sort' => 'orderedAtDesc',
            ];

            $seenIds = [];
            $rawOrders = [];

            // Priorytet 1: szukaj po NIP
            $nip = $client->nip ? preg_replace('/\D/', '', trim($client->nip)) : '';
            if (strlen($nip) >= 10) {
                $fetchParams = array_merge($params, ['nip' => $nip]);
                $orders = $this->loggedRequest('apilo', 'GET', '/rest/api/orders/', function () use ($token, $fetchParams) {
                    $response = Http::withToken($token)
                        ->acceptJson()
                        ->get("{$this->baseUrl}/rest/api/orders/", $fetchParams);
                    if ($response->successful()) {
                        return $response->json()['orders'] ?? [];
                    }
                    return [];
                }, ['nip' => $nip]);

                foreach ($orders as $order) {
                    $id = $order['id'] ?? null;
                    if ($id && ! isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        $rawOrders[] = $order;
                    }
                }
            }

            // Priorytet 2 (fallback): szukaj po email gdy brak NIP lub brak wyników
            if (empty($rawOrders)) {
                $emailsToFetch = array_unique(array_filter(array_map(function ($e) {
                    return $e ? trim((string) $e) : null;
                }, [$client->email, $client->contact_email ?? null])));

                foreach ($emailsToFetch as $email) {
                    $fetchParams = array_merge($params, ['email' => $email]);
                    $orders = $this->loggedRequest('apilo', 'GET', '/rest/api/orders/', function () use ($token, $fetchParams) {
                        $response = Http::withToken($token)
                            ->acceptJson()
                            ->get("{$this->baseUrl}/rest/api/orders/", $fetchParams);
                        if ($response->successful()) {
                            return $response->json()['orders'] ?? [];
                        }
                        return [];
                    }, ['email' => $email]);

                    foreach ($orders as $order) {
                        $id = $order['id'] ?? null;
                        if ($id && ! isset($seenIds[$id])) {
                            $seenIds[$id] = true;
                            $rawOrders[] = $order;
                        }
                    }
                }
            }

            $rawOrders = $this->sortOrdersByDateDesc($rawOrders);

            return $this->normalizeOrdersWithTracking($rawOrders, $token);
        } catch (\Exception $e) {
            Log::error('Apilo Orders Error: '.$e->getMessage());
        }

        return [];
    }

    /**
     * Sortuj zamówienia od najnowszych
     */
    private function sortOrdersByDateDesc(array $orders): array
    {
        usort($orders, function ($a, $b) {
            $dateA = $a['orderedAt'] ?? $a['createdAt'] ?? '';
            $dateB = $b['orderedAt'] ?? $b['createdAt'] ?? '';

            return strcmp($dateB, $dateA);
        });

        return $orders;
    }

    /**
     * Normalizuj zamówienia z Apilo i dodaj info o trackingu (max 25 sprawdzeń – najnowsze)
     */
    private function normalizeOrdersWithTracking(array $rawOrders, string $token): array
    {
        $documentTypes = $this->getDocumentTypesMap($token);
        $parcelTypeIds = $this->getParcelDocumentTypeIds($documentTypes);
        $orders = [];
        $checkTrackingLimit = 25;
        $checked = 0;

        foreach ($rawOrders as $raw) {
            $order = $this->normalizeOrder($raw);
            $order['has_tracking_sent'] = false;

            if ($checked < $checkTrackingLimit && ! empty($order['id'])) {
                $order['has_tracking_sent'] = $this->orderHasTrackingSent($order['id'], $token, $parcelTypeIds);
                $checked++;
            }

            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * Mapuj surowe zamówienie Apilo na format UI
     */
    private function normalizeOrder(array $raw): array
    {
        $date = $raw['createdAt'] ?? $raw['orderedAt'] ?? $raw['date'] ?? null;
        if ($date) {
            $date = (new \DateTime($date))->format('Y-m-d');
        }

        $total = $raw['originalAmountTotalWithTax'] ?? $raw['originalAmountTotal'] ?? null;
        if ($total === null && ! empty($raw['orderItems'])) {
            $total = 0;
            foreach ($raw['orderItems'] as $item) {
                if (($item['type'] ?? 1) === 1) {
                    $total += floatval($item['originalPriceWithTax'] ?? 0) * ($item['quantity'] ?? 1);
                }
            }
        }
        $total = $total !== null ? (float) $total : 0;

        $status = $this->mapOrderStatus($raw['status'] ?? null);

        $paymentTypeId = $raw['paymentType'] ?? $raw['payment_type'] ?? null;
        $paymentMethod = $this->resolvePaymentTypeLabel($paymentTypeId);

        return [
            'id' => $raw['id'] ?? '',
            'date' => $date,
            'total' => $total,
            'status' => $status,
            'products' => $raw['orderItems'] ?? [],
            'payment_type_id' => $paymentTypeId,
            'payment_method' => $paymentMethod,
        ];
    }

    /**
     * Nazwa sposobu płatności po ID z mapy Apilo (dla listy zamówień).
     */
    private function resolvePaymentTypeLabel(mixed $paymentTypeId): ?string
    {
        if ($paymentTypeId === null || $paymentTypeId === '') {
            return null;
        }
        try {
            $options = $this->getOrderOptions();
            foreach ($options['payment_types'] ?? [] as $pt) {
                if ((string) ($pt['id'] ?? '') === (string) $paymentTypeId) {
                    $name = trim((string) ($pt['name'] ?? ''));

                    return $name !== '' ? $name : null;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Apilo resolvePaymentTypeLabel: '.$e->getMessage());
        }

        return null;
    }

    private function mapOrderStatus($status): string
    {
        $map = [
            1 => 'pending',
            2 => 'processing',
            3 => 'processing',
            4 => 'shipped',
            5 => 'completed',
            6 => 'cancelled',
        ];

        return $map[$status] ?? 'pending';
    }

    /**
     * Pobierz mapę typów dokumentów (cache 1h)
     */
    private function getDocumentTypesMap(string $token): array
    {
        $cacheKey = 'apilo_document_types_map';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/documents/map/");
            if ($response->successful()) {
                $data = $response->json();
                Cache::put($cacheKey, $data ?? [], now()->addHour());

                return $data ?? [];
            }
        } catch (\Exception $e) {
            Log::debug('Apilo document types map: '.$e->getMessage());
        }

        return [];
    }

    /**
     * Identyfikatory typów dokumentów związanych z przesyłką/trackingiem
     */
    private function getParcelDocumentTypeIds(array $documentTypes): array
    {
        $ids = [];
        $keywords = ['parcel', 'przesyłk', 'shipment', 'tracking', 'etykiet', 'label', 'dostaw'];
        foreach ($documentTypes as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }
            $name = strtolower($item['name'] ?? $item['description'] ?? '');
            $id = $item['id'];
            foreach ($keywords as $kw) {
                if (str_contains($name, $kw)) {
                    $ids[] = $id;
                    break;
                }
            }
        }

        return array_unique($ids);
    }

    /**
     * Sprawdź czy do zamówienia wysłano tracking (przesyłki, dokumenty, szczegóły zamówienia)
     */
    private function orderHasTrackingSent(string $orderId, string $token, array $parcelTypeIds): bool
    {
        if ($this->orderHasShipments($orderId, $token)) {
            return true;
        }
        if ($this->orderHasParcelDocuments($orderId, $token, $parcelTypeIds)) {
            return true;
        }

        return $this->orderHasParcelsInDetail($orderId, $token);
    }

    /**
     * Sprawdź czy zamówienie ma przesyłki (dedykowany endpoint GET /rest/api/orders/{id}/shipment/)
     */
    private function orderHasShipments(string $orderId, string $token): bool
    {
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/{$orderId}/shipment/");
            if (! $response->successful()) {
                return false;
            }
            $data = $response->json();
            $shipments = $data['list'] ?? $data['shipments'] ?? $data['results'] ?? $data['data'] ?? (is_array($data) ? $data : []);

            return is_array($shipments) && count($shipments) > 0;
        } catch (\Exception $e) {
            Log::debug("Apilo order {$orderId} shipments: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Pobierz linki do śledzenia przesyłek dla zamówienia (do kliknięcia w badge)
     * Zwraca [{url, number}, ...]
     */
    public function getOrderTrackingLinks(string $orderId): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/{$orderId}/shipment/");
            if (! $response->successful()) {
                return [];
            }
            $data = $response->json();
            $list = $data['list'] ?? $data['shipments'] ?? $data['results'] ?? $data['data'] ?? [];
            if (! is_array($list) || empty($list)) {
                return [];
            }
            $links = [];
            foreach ($list as $item) {
                $shipmentId = $item['id'] ?? null;
                if ($shipmentId === null) {
                    continue;
                }
                $detail = $this->fetchShipmentDetail($orderId, (string) $shipmentId, $token);
                if ($detail && ! empty($detail['tracking'])) {
                    $tracking = trim($detail['tracking']);
                    $url = $this->buildTrackingUrl($tracking, $detail['carrierProviderId'] ?? null);
                    $links[] = ['url' => $url, 'number' => $tracking];
                }
            }

            return $links;
        } catch (\Exception $e) {
            Log::debug("Apilo getOrderTrackingLinks {$orderId}: ".$e->getMessage());

            return [];
        }
    }

    private function fetchShipmentDetail(string $orderId, string $shipmentId, string $token): ?array
    {
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/{$orderId}/shipment/{$shipmentId}/");

            return $response->successful() ? ($response->json() ?? null) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function buildTrackingUrl(string $tracking, $carrierProviderId): string
    {
        $tracking = trim($tracking);
        if (empty($tracking)) {
            return '';
        }
        // InPost: 24 znaki, często kończy się literą (np. T)
        if (preg_match('/^[0-9]{20,24}[A-Za-z]?$/', $tracking)) {
            return 'https://inpost.pl/sledzenie-przesylek?number='.urlencode($tracking);
        }
        // DPD: format 14 cyfr
        if (preg_match('/^\d{14}$/', $tracking)) {
            return 'https://tracktrace.dpd.com.pl/status/pl_PL/'.urlencode($tracking);
        }

        // Uniwersalny fallback – 17track obsługuje wiele kurierów
        return 'https://t.17track.net/pl#nums='.urlencode($tracking);
    }

    private function orderHasParcelDocuments(string $orderId, string $token, array $parcelTypeIds): bool
    {
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/{$orderId}/documents/", ['limit' => 50]);
            if (! $response->successful()) {
                return false;
            }
            $data = $response->json();
            $documents = $data['documents'] ?? $data['results'] ?? (is_array($data) ? $data : []);
            if (! is_array($documents)) {
                return false;
            }
            foreach ($documents as $doc) {
                if (! is_array($doc)) {
                    continue;
                }
                $type = $doc['type'] ?? null;
                if ($type !== null && in_array($type, $parcelTypeIds, true)) {
                    return true;
                }
                if (empty($parcelTypeIds) && (! empty($doc['number']) || ! empty($doc['idExternal']))) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::debug("Apilo order {$orderId} documents: ".$e->getMessage());
        }

        return false;
    }

    private function orderHasParcelsInDetail(string $orderId, string $token): bool
    {
        try {
            $order = $this->getOrder($orderId);
            if (! $order) {
                return false;
            }
            $parcels = $order['parcels'] ?? $order['shipments'] ?? [];

            return is_array($parcels) && count($parcels) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Pobierz opcje do formularza zamówienia (platformy, płatności, dostawy)
     * Używa endpointów mapowań Apilo: /rest/api/orders/{type}/map/
     */
    public function getOrderOptions(): array
    {
        if (! $this->isConfigured()) {
            return [
                'platforms' => [['id' => 1, 'name' => 'Platforma demo']],
                'payment_types' => [['id' => 1, 'name' => 'Przelew']],
                'carriers' => [['id' => 1, 'name' => 'Kurier']],
            ];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['platforms' => [], 'payment_types' => [], 'carriers' => []];
        }

        $cacheKey = 'apilo_order_options';
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $platforms = [];
        $paymentTypes = [];
        $carriers = [];

        // Platformy (kanały sprzedaży) — /rest/api/orders/platform/map/
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/platform/map/");
            if ($response->successful()) {
                $items = $response->json() ?? [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $platforms[] = [
                            'id' => $item['id'],
                            'name' => $item['description'] ?? $item['name'] ?? ('Platforma #'.$item['id']),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Apilo: Błąd pobierania platform: '.$e->getMessage());
        }

        // Typy płatności — /rest/api/orders/payment/map/
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/payment/map/");
            if ($response->successful()) {
                $items = $response->json() ?? [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $paymentTypes[] = [
                            'id' => $item['id'],
                            'name' => $item['name'] ?? ('Płatność #'.$item['id']),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Apilo: Błąd pobierania typów płatności: '.$e->getMessage());
        }

        // Konta kurierskie (dostawy) — /rest/api/orders/carrier-account/map/
        try {
            $response = Http::withToken($token)->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/carrier-account/map/");
            if ($response->successful()) {
                $items = $response->json() ?? [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $carriers[] = [
                            'id' => $item['id'],
                            'name' => $item['name'] ?? ('Kurier #'.$item['id']),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Apilo: Błąd pobierania kurierów: '.$e->getMessage());
        }

        $result = [
            'platforms' => $platforms,
            'payment_types' => $paymentTypes,
            'carriers' => $carriers,
        ];

        // KRYTYCZNE: NIE cache'uj jeśli kluczowe dane (payment_types) są puste — to znaczy że
        // Apilo padło i nie chcemy przez 30 min zwracać pustych list. Cache tylko sukces.
        if (!empty($paymentTypes) && !empty($platforms)) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, now()->addMinutes(30));
        } else {
            \Log::warning('Apilo: getOrderOptions zwróciło puste dane — pomijam cache', [
                'platforms_count' => count($platforms),
                'payment_types_count' => count($paymentTypes),
                'carriers_count' => count($carriers),
            ]);
        }

        return $result;
    }

    /**
     * Czy nazwa typu płatności z mapy Apilo wskazuje na pobranie.
     */
    public function paymentTypeNameLooksLikeCod(string $name): bool
    {
        $lower = mb_strtolower($name);

        return str_contains($lower, 'pobran')
            || str_contains($lower, 'pobrani')
            || str_contains($lower, 'cod')
            || (str_contains($lower, 'gotów') && str_contains($lower, 'odbior'))
            || (str_contains($lower, 'gotowk') && str_contains($lower, 'odbior'));
    }

    /**
     * Pierwszy z listy typ płatności rozpoznawany jako pobranie (domyślny w formularzu zamówienia).
     */
    public function findFirstCodPaymentTypeId(?array $paymentTypes): mixed
    {
        if (empty($paymentTypes)) {
            return null;
        }
        foreach ($paymentTypes as $pt) {
            if (! is_array($pt)) {
                continue;
            }
            $name = (string) ($pt['name'] ?? '');
            if ($name !== '' && $this->paymentTypeNameLooksLikeCod($name)) {
                return $pt['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Czy wybrany typ płatności Apilo to „za pobraniem” (nie blokujemy zaległych faktur).
     */
    public function isPaymentTypeLikelyCod(mixed $paymentTypeId): bool
    {
        if ($paymentTypeId === null || $paymentTypeId === '') {
            return false;
        }
        $options = $this->getOrderOptions();
        foreach ($options['payment_types'] ?? [] as $pt) {
            if ((string) ($pt['id'] ?? '') !== (string) $paymentTypeId) {
                continue;
            }
            $name = (string) ($pt['name'] ?? '');

            return $name !== '' && $this->paymentTypeNameLooksLikeCod($name);
        }

        return false;
    }

    /**
     * Parsuj kwotę z Apilo (liczba lub string z przecinkiem / separatorem tysięcy).
     */
    private function parseApiloMoneyScalar(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 4);
        }
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace("\xc2\xa0", '', $s);
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        // Format PL: 1.234,56
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        return round((float) $s, 4);
    }

    /**
     * Parsuj adres paczkomatu InPost (np. "ul. Testowa 12, 00-000 Kraków") na składowe
     */
    private function parseInpostAddress(?string $address): array
    {
        $result = ['street' => '', 'street_number' => '', 'city' => '', 'zip' => ''];
        if (empty($address)) {
            return $result;
        }
        // Kod pocztowy XX-XXX
        if (preg_match('/(\d{2}-\d{3})/', $address, $m)) {
            $result['zip'] = $m[1];
        }
        // Miasto – często po kodzie lub na końcu
        if (preg_match('/\d{2}-\d{3}\s+([^,]+)/', $address, $m)) {
            $result['city'] = trim($m[1]);
        } elseif (preg_match('/,\s*([^,0-9-]+)$/', $address, $m)) {
            $result['city'] = trim($m[1]);
        }
        // Ulica i numer – początek do kodu lub przecinka
        $streetPart = preg_replace('/\d{2}-\d{3}.*/', '', $address);
        $streetPart = preg_replace('/,\s*[^,]+$/', '', $streetPart);
        $streetPart = trim($streetPart, ", \t\n\r");
        if ($streetPart) {
            if (preg_match('/^(.+?)\s+(\d+[a-zA-Z]?(?:\/\d+)?)\s*$/', $streetPart, $m)) {
                $result['street'] = trim($m[1]);
                $result['street_number'] = $m[2];
            } else {
                $result['street'] = $streetPart;
            }
        }

        return $result;
    }

    /**
     * Zbuduj datę/czas zamówienia (orderedAt) – aktualna godzina dodania zamówienia
     */
    private function buildOrderedAt(array $data): string
    {
        return now()->toIso8601String();
    }

    /**
     * Utwórz zamówienie w Apilo
     */
    public function createOrder(array $data): ?array
    {
        if (! $this->isConfigured()) {
            return $this->createMockOrder($data);
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        try {
            $client = $data['client'];
            $customer = $data['customer'] ?? [];
            $delivery = $data['delivery'] ?? [];

            // Użyj wartości z formularza lub auto-detect
            $platformId = ! empty($data['platform_id']) ? $data['platform_id'] : $this->getFirstPlatformId($token);
            $carrierAccount = ! empty($data['carrier_account']) ? $data['carrier_account'] : $this->getFirstCarrierAccountId($token);
            $paymentType = ! empty($data['payment_type']) ? $data['payment_type'] : $this->getFirstPaymentTypeId($token);

            // Oblicz sumy
            $totalWithTax = 0;
            $totalWithoutTax = 0;
            $orderItems = [];
            foreach ($data['products'] as $index => $product) {
                $priceWithTax = round($product['price'], 2);
                $taxRate = floatval($product['tax_rate'] ?? 23);
                $priceWithoutTax = $taxRate > 0
                    ? round($priceWithTax / (1 + $taxRate / 100), 2)
                    : $priceWithTax;
                $totalWithTax += round($priceWithTax * $product['quantity'], 2);

                $orderItems[] = [
                    'id' => $index + 1,
                    'originalName' => $product['name'],
                    'sku' => $product['sku'] ?? null,
                    'ean' => $product['ean'] ?? null,
                    'originalPriceWithTax' => (string) $priceWithTax,
                    'originalPriceWithoutTax' => (string) $priceWithoutTax,
                    'quantity' => $product['quantity'],
                    'tax' => sprintf('%.2f', $taxRate),
                    'status' => 1,
                    'unit' => 'Szt.',
                    'type' => 1, // produkt
                    'productId' => $product['product_id'] ?? null,
                ];
                $totalWithoutTax += round($priceWithoutTax * $product['quantity'], 2);
            }

            $totalWithoutTax = round($totalWithoutTax, 2);

            // Dane zamawiającego — priorytet: formularz → klient → placeholder
            $custName = ! empty($customer['name']) ? $customer['name'] : ($client?->name ?? 'Klient');
            $custPhone = ! empty($customer['phone']) ? $customer['phone'] : (! empty($client?->phone) ? $client->phone : '-');
            $custEmail = ! empty($customer['email']) ? $customer['email'] : ($client?->email ?? '');
            $custStreet = ! empty($customer['street']) ? $customer['street'] : (! empty($client?->street) ? $client->street : '-');
            $custStreetNum = ! empty($customer['street_number']) ? $customer['street_number'] : (! empty($client?->street_number) ? $client->street_number : '-');
            $custCity = ! empty($customer['city']) ? $customer['city'] : (! empty($client?->city) ? $client->city : '-');
            $custZip = ! empty($customer['zip']) ? $customer['zip'] : ($client?->postal_code ?? '00-000');
            $custNip = ! empty($customer['nip']) ? $customer['nip'] : ($client?->nip ?? '');

            // companyName tylko gdy firma (NIP) i osoba kontaktowa różna od nazwy firmy — unikamy duplikatu
            $companyName = '';
            if (! empty($custNip) && $client && ! empty(trim($client->contact_person ?? '')) && trim($client->name ?? '') !== trim($client->contact_person ?? '')) {
                $companyName = trim($client->name);
                if (empty($customer['name'])) {
                    $custName = trim($client->contact_person);
                }
            }

            // Dane wysyłki — priorytet: formularz → zamawiający
            $parcelPoint = ! empty($delivery['inpost_parcel_point']) ? trim($delivery['inpost_parcel_point']) : null;
            $parcelAddress = ! empty($delivery['inpost_parcel_address']) ? trim($delivery['inpost_parcel_address']) : null;
            $delName = ! empty($delivery['name']) ? $delivery['name'] : $custName;
            $delPhone = ! empty($delivery['phone']) ? $delivery['phone'] : $custPhone;
            $delEmail = ! empty($delivery['email']) ? $delivery['email'] : $custEmail;

            $addressDelivery = [
                'name' => $delName,
                'phone' => $delPhone,
                'email' => $delEmail,
                'country' => 'PL',
            ];

            if ($parcelPoint) {
                // Paczkomat InPost – punkt odbioru: kod, adres, informacja o wyborze
                $parcelName = $parcelAddress ?: ('Paczkomat InPost '.strtoupper($parcelPoint));
                $addressDelivery['class'] = 'parcel'; // Jawna informacja dla Apilo: to punkt odbioru
                $addressDelivery['parcelIdExternal'] = strtoupper($parcelPoint);
                $addressDelivery['parcelName'] = $parcelName;
                // Adres: street, streetNumber, city, zipCode – parsuj z parcel_address lub użyj domyślnych
                $parsed = $this->parseInpostAddress($parcelAddress);
                $addressDelivery['streetName'] = $parsed['street'] ?: 'Paczkomat InPost';
                $addressDelivery['streetNumber'] = $parsed['street_number'] ?: strtoupper($parcelPoint);
                $addressDelivery['city'] = $parsed['city'] ?: ($delivery['city'] ?? $custCity ?: '-');
                $addressDelivery['zipCode'] = $parsed['zip'] ?: ($delivery['zip'] ?? $custZip ?: '-');
            } else {
                $addressDelivery['streetName'] = ! empty($delivery['street']) ? $delivery['street'] : $custStreet;
                $addressDelivery['streetNumber'] = ! empty($delivery['street_number']) ? $delivery['street_number'] : $custStreetNum;
                $addressDelivery['city'] = ! empty($delivery['city']) ? $delivery['city'] : $custCity;
                $addressDelivery['zipCode'] = ! empty($delivery['zip']) ? $delivery['zip'] : $custZip;
            }

            $orderData = [
                'platformId' => $platformId,
                'paymentStatus' => 0, // brak płatności
                'paymentType' => $paymentType,
                'originalCurrency' => 'PLN',
                'originalAmountTotalWithoutTax' => (string) $totalWithoutTax,
                'originalAmountTotalWithTax' => (string) $totalWithTax,
                'originalAmountTotalPaid' => '0',
                'orderItems' => $orderItems,
                'addressCustomer' => array_filter([
                    'name' => $custName,
                    'phone' => $custPhone,
                    'email' => $custEmail,
                    'streetName' => $custStreet,
                    'streetNumber' => $custStreetNum,
                    'city' => $custCity,
                    'zipCode' => $custZip,
                    'country' => 'PL',
                    'companyTaxNumber' => $custNip ?: null,
                    'companyName' => $companyName ?: null,
                ], fn ($v) => $v !== null && $v !== ''),
                'addressDelivery' => $addressDelivery,
                'carrierAccount' => $carrierAccount,
                'orderedAt' => $this->buildOrderedAt($data),
                'status' => 1, // nowe zamówienie
                'orderNotes' => [], // Nie przekazujemy notatek klienta do Apilo
            ];

            return $this->loggedRequest('apilo', 'POST', '/rest/api/orders/', function () use ($token, $orderData) {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->contentType('application/json')
                    ->post("{$this->baseUrl}/rest/api/orders/", $orderData);

                if ($response->successful()) {
                    $result = $response->json();

                    return [
                        'id' => $result['id'] ?? null,
                        'date' => now()->format('Y-m-d'),
                        'status' => 'pending',
                        'total' => $orderData['originalAmountTotalWithTax'],
                        'has_tracking_sent' => false,
                        'products' => $orderData['orderItems'],
                    ];
                }

                $errorBody = $response->json();
                $errorDetails = '';
                if (isset($errorBody['errors']) && is_array($errorBody['errors'])) {
                    $errorDetails = collect($errorBody['errors'])->pluck('message')->implode(', ');
                }
                Log::error('Apilo Create Order Error: '.$response->status().' '.$response->body());

                // Zwróć szczegółowy błąd zamiast null
                throw new \Exception('Apilo: '.($errorBody['description'] ?? 'Błąd').($errorDetails ? ' — '.$errorDetails : ''));

                return null;
            }, ['items_count' => count($orderItems)]);
        } catch (\Exception $e) {
            Log::error('Apilo Create Order Exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Pobierz szczegóły zamówienia
     */
    public function getOrder(string $orderId): ?array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/{$orderId}/");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('Apilo Get Order Error: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Pobierz produkty (opcjonalnie filtruj po prefixie nazwy)
     * Strategia: Warehouse API -> fallback do unikalnych produktów z historii zamówień
     */
    public function getProducts(array $filters = [], ?string $namePrefix = null, bool $forceRefresh = false): array
    {
        if (! $this->isConfigured()) {
            $products = $this->getMockProducts();

            if ($namePrefix) {
                $pl = mb_strtolower($namePrefix);
                $products = array_values(array_filter($products, function ($product) use ($pl) {
                    return str_starts_with(mb_strtolower($product['name'] ?? ''), $pl);
                }));
            }

            return $products;
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }

        // Cache produkty na 10 minut (pustej listy nie zapisujemy — unikamy „zatrzaśnięcia” po błędzie)
        $cacheKey = 'apilo_products_v11_'.md5(json_encode($filters).$namePrefix);
        Log::info('Apilo DEBUG getProducts: forceRefresh='.($forceRefresh?'1':'0').', cacheHas='.(Cache::has($cacheKey)?'1':'0'));
        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        try {
            $start = microtime(true);

            // 1. Próba: Warehouse API
            $products = $this->fetchProductsFromWarehouse($token, $filters);

            // 2. Fallback: zbierz unikalne produkty z historii zamówień
            if (empty($products)) {
                Log::info('Apilo Products: Warehouse API niedostępne, pobieram produkty z historii zamówień...');
                $products = $this->fetchProductsFromOrders($token, $namePrefix);
                // Magazyn pusty + brak pozycji z prefiksem w ostatnich zamówieniach — pełna lista z zamówień i filtr prefiksu (np. th_ vs TH_)
                if (empty($products) && $namePrefix) {
                    $fromOrders = $this->fetchProductsFromOrders($token, null);
                    $prefixLower = mb_strtolower($namePrefix);
                    $products = array_values(array_filter(
                        $fromOrders,
                        fn (array $p) => str_starts_with(mb_strtolower((string) ($p['name'] ?? '')), $prefixLower)
                    ));
                }
            } else {
                // Filtruj po prefixie jeśli dane z Warehouse
                if ($namePrefix) {
                    $pl = mb_strtolower($namePrefix);
                    $products = array_values(array_filter($products, function ($product) use ($pl) {
                        return str_starts_with(mb_strtolower($product['name'] ?? ''), $pl);
                    }));
                }
            }

            // Nadpisz ceny z cennika — działa niezależnie od źródła (warehouse lub orders fallback)
            $priceListId = $this->resolveApiloCatalogPriceListId($token);
            if (! empty($products)) {
                $calculatedMap = $priceListId !== null
                    ? $this->fetchPriceCalculatedMap($token, $priceListId)
                    : $this->fetchPriceCalculatedMapAuto($token);
                if ($calculatedMap !== []) {
                    Log::info('Apilo: nadpisano ceny z cennika #'.$priceListId.' ('.count($calculatedMap).' pozycji).');
                    $products = array_map(function (array $p) use ($calculatedMap): array {
                        $pid = isset($p['id']) ? (int) $p['id'] : 0;
                        if ($pid > 0 && isset($calculatedMap[$pid])) {
                            $cv = $this->parseApiloMoneyScalar($calculatedMap[$pid]);
                            if ($cv >= 0.5) {
                                $taxRate = floatval($p['tax_rate'] ?? 23);
                                $p['price'] = round($cv, 2);
                                $p['price_net'] = $taxRate > 0 ? round($cv / (1 + $taxRate / 100), 2) : $cv;
                            }
                        }
                        return $p;
                    }, $products);
                }
            }

            $durationMs = (int) ((microtime(true) - $start) * 1000);

            \App\Models\IntegrationLog::logCall(
                service: 'apilo',
                method: 'GET',
                endpoint: 'products (warehouse+orders fallback)',
                requestData: $filters,
                responseStatus: 200,
                responseSummary: count($products).' products',
                durationMs: $durationMs,
                status: 'success',
            );

            Log::info('Apilo Products: Znaleziono '.count($products).' produktów (prefiks: '.($namePrefix ?? 'brak').')');

            if ($products !== []) {
                Cache::put($cacheKey, $products, 600);
            }

            return $products;
        } catch (\Exception $e) {
            Log::error('Apilo Products Error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Mapuj surowy produkt Apilo na standardową strukturę (z VAT, netto, brutto)
     *
     * Cena z cennika (_plannerCalculatedPriceWithTax) jest nadrzędna — to ona jest widoczna
     * w zakładce Cennik w Apilo i reprezentuje aktualną cenę sprzedaży.
     * Ceny magazynowe (priceWithTax) służą jako fallback gdy produkt nie ma wpisu w cenniku.
     * Netto zawsze z wybranego brutto + stawki VAT.
     */
    private function mapApiloProductToStandard(array $p): array
    {
        $taxRate = floatval($p['tax'] ?? $p['taxRate'] ?? 23);

        // Cennik jest nadrzędny — gdy istnieje, używamy go bezpośrednio
        $plannerCalc = $p['_plannerCalculatedPriceWithTax'] ?? null;
        if ($plannerCalc !== null) {
            $cv = $this->parseApiloMoneyScalar($plannerCalc);
            if ($cv >= 0.5) {
                $priceBrutto = $cv;
                goto resolve_net;
            }
        }

        // Fallback: ceny z magazynu
        $grossCatalogKeys = [
            'priceWithTax',
            'originalPriceWithTax',
            'retailPriceWithTax',
            'salePriceWithTax',
            'priceGross',
            'grossPrice',
            'totalPriceGross',
        ];
        $priceBrutto = 0.0;
        foreach ($grossCatalogKeys as $key) {
            if (! array_key_exists($key, $p)) {
                continue;
            }
            $val = $this->parseApiloMoneyScalar($p[$key]);
            if ($val >= 0.5) {
                $priceBrutto = $val;
                break;
            }
        }
        if ($priceBrutto < 0.5 && array_key_exists('price', $p)) {
            $v = $this->parseApiloMoneyScalar($p['price']);
            if ($v >= 0.5) {
                $priceBrutto = $v;
            }
        }

        resolve_net:

        $priceNetto = 0.0;
        if ($priceBrutto >= 0.5) {
            $priceNetto = $taxRate > 0
                ? round($priceBrutto / (1 + $taxRate / 100), 2)
                : $priceBrutto;
        } else {
            $netKeys = ['priceWithoutTax', 'originalPriceWithoutTax', 'retailPriceWithoutTax', 'priceNet', 'netPrice'];
            foreach ($netKeys as $key) {
                if (! array_key_exists($key, $p)) {
                    continue;
                }
                $val = $this->parseApiloMoneyScalar($p[$key]);
                if ($val >= 0.5) {
                    $priceNetto = $val;
                    break;
                }
            }
            if ($priceNetto >= 0.5) {
                $priceBrutto = $taxRate > 0
                    ? round($priceNetto * (1 + $taxRate / 100), 2)
                    : $priceNetto;
            }
        }

        return [
            'id' => $p['id'] ?? $p['productId'] ?? null,
            'name' => $p['name'] ?? $p['originalName'] ?? $p['groupName'] ?? '',
            'sku' => $p['sku'] ?? '',
            'ean' => $p['ean'] ?? '',
            'price' => round($priceBrutto, 2),
            'price_net' => round($priceNetto, 2),
            'tax_rate' => $taxRate,
        ];
    }

    /**
     * Odpowiedź JSON z Warehouse — zabezpieczenie przed null / nie-tablicą (inaczej wyjątek i pusta lista).
     *
     * @return array<string, mixed>
     */
    private function apiloJsonBody(\Illuminate\Http\Client\Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Z JSON magazynu — zawsze lista tablic asocjowanych (tak jak wcześniej w planerze; bez „zgadywania” struktur).
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWarehouseProductBatch(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $out[] = $row;
            } elseif (is_object($row)) {
                $decoded = json_decode(json_encode($row), true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    /**
     * ID cennika magazynowego: opcjonalnie ustawienie apilo_warehouse_price_list_id, inaczej pierwszy z GET /warehouse/price/.
     */
    private function resolveApiloCatalogPriceListId(string $token): ?int
    {
        $configured = Setting::get('apilo_warehouse_price_list_id', '', 'core');
        if ($configured !== null && $configured !== '') {
            $id = (int) $configured;

            return $id > 0 ? $id : null;
        }
        $ids = $this->fetchWarehousePriceListIds($token);

        return $ids[0] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private function fetchWarehousePriceListIds(string $token): array
    {
        $seen = [];
        $offset = 0;
        $limit = 2000;
        $pages = 0;

        while ($pages < 30) {
            $pages++;
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($this->baseUrl.'/rest/api/warehouse/price/', [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if (! $response->successful()) {
                break;
            }

            $data = $this->apiloJsonBody($response);
            $list = $data['list'] ?? [];
            if (! is_array($list) || $list === []) {
                break;
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($id > 0) {
                    $seen[$id] = $id;
                }
            }

            $totalCount = (int) ($data['totalCount'] ?? 0);
            if ($totalCount <= 0) {
                $totalCount = $offset + count($list);
            }

            $offset += $limit;
            if ($offset >= $totalCount) {
                break;
            }
        }

        return array_values($seen);
    }

    /**
     * Mapa productId => brutto z cennika (customPriceWithTax).
     *
     * @return array<int, float>
     */
    /**
     * Próbuje pobrać cenę z price-calculated bez znajomości ID cennika.
     * Najpierw bez parametru price, potem ID 1–10.
     */
    private function fetchPriceCalculatedMapAuto(string $token): array
    {
        // Próba bez parametru price
        $response = Http::withToken($token)->acceptJson()
            ->get($this->baseUrl.'/rest/api/warehouse/price-calculated/', ['limit' => 1]);
        if ($response->successful()) {
            $list = $response->json('list') ?? [];
            if (! empty($list) && is_array($list[0] ?? null)) {
                $foundId = (int) ($list[0]['price'] ?? 0);
                if ($foundId > 0) {
                    Log::info('Apilo: auto-wykryto ID cennika: '.$foundId);
                    return $this->fetchPriceCalculatedMap($token, $foundId);
                }
            }
        }
        Log::info('Apilo: price-calculated bez ID — HTTP '.$response->status());

        // Próba z ID 1–20
        for ($id = 1; $id <= 20; $id++) {
            $r = Http::withToken($token)->acceptJson()
                ->get($this->baseUrl.'/rest/api/warehouse/price-calculated/', ['price' => $id, 'limit' => 1]);
            if ($r->successful() && ! empty($r->json('list'))) {
                Log::info('Apilo: auto-wykryto ID cennika przez brute-force: '.$id);
                return $this->fetchPriceCalculatedMap($token, $id);
            }
        }
        Log::info('Apilo: nie udało się auto-wykryć ID cennika (sprawdzone ID 1-20).');
        return [];
    }

    private function fetchPriceCalculatedMap(string $token, int $priceListId): array
    {
        $map = [];
        $offset = 0;
        $limit = 100;
        $pages = 0;

        while ($pages < 30) {
            $pages++;
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($this->baseUrl.'/rest/api/warehouse/price-calculated/', [
                    'price' => $priceListId,
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if (! $response->successful()) {
                if ($offset === 0) {
                    Log::info('Apilo: price-calculated dla cennika #'.$priceListId.' — HTTP '.$response->status());
                }

                break;
            }

            $data = $this->apiloJsonBody($response);
            $list = $data['list'] ?? [];
            if (! is_array($list)) {
                break;
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $pid = isset($row['product']) ? (int) $row['product'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                $gross = $this->parseApiloMoneyScalar($row['customPriceWithTax'] ?? 0);
                if ($gross >= 0.5) {
                    $map[$pid] = max($map[$pid] ?? 0.0, $gross);
                }
            }

            $totalCount = (int) ($data['totalCount'] ?? 0);
            if ($totalCount <= 0 && $list !== []) {
                $totalCount = $offset + count($list);
            }

            $offset += $limit;
            if ($offset >= $totalCount || $list === []) {
                break;
            }
        }

        return $map;
    }

    /**
     * Lista produktów z magazynu — endpoint /warehouse/products/ + opcjonalnie ceny z /warehouse/price-calculated/.
     * Apilo często zwraca totalCount=0 mimo niepustej listy — nie wolno odrzucać takiej odpowiedzi.
     */
    private function fetchProductsFromWarehouse(string $token, array $filters): array
    {
        $allRaw = [];
        $offset = 0;
        $limit = 500;
        $path = '/rest/api/warehouse/products/';

        $response = Http::withToken($token)
            ->acceptJson()
            ->get($this->baseUrl.$path, array_merge(['limit' => $limit, 'offset' => $offset], $filters));

        if ($response->status() === 404) {
            Log::info('Apilo: Warehouse API niedostępne (404) — klucz API nie ma uprawnień do magazynu.');

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Apilo Warehouse: Błąd '.$response->status());

            return [];
        }

        $data = $this->apiloJsonBody($response);
        $rawProducts = $this->normalizeWarehouseProductBatch(
            $data['products'] ?? $data['content'] ?? $data['list'] ?? []
        );
        foreach ($rawProducts as $p) {
            $allRaw[] = $p;
        }

        $totalCount = (int) ($data['totalCount'] ?? 0);
        if ($totalCount <= 0 && $rawProducts !== []) {
            $totalCount = count($allRaw);
        }

        while ($offset + $limit < $totalCount && $offset < 5000) {
            $offset += $limit;
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($this->baseUrl.$path, array_merge(['limit' => $limit, 'offset' => $offset], $filters));

            if (! $response->successful()) {
                break;
            }

            $data = $this->apiloJsonBody($response);
            $rawProducts = $this->normalizeWarehouseProductBatch(
                $data['products'] ?? $data['content'] ?? $data['list'] ?? []
            );
            foreach ($rawProducts as $p) {
                $allRaw[] = $p;
            }
        }

        $calculatedMap = [];
        $priceListId = $this->resolveApiloCatalogPriceListId($token);
        Log::info('Apilo DEBUG cennik: priceListId='.(string)$priceListId);
        if ($priceListId !== null) {
            $calculatedMap = $this->fetchPriceCalculatedMap($token, $priceListId);
            Log::info('Apilo DEBUG cennik: pozycji='.count($calculatedMap).', sample='.json_encode(array_slice($calculatedMap, 0, 5, true)));
            // Pokaż konkretne ID z problemem
            foreach ([195331, 195325, 195085] as $debugId) {
                Log::info('Apilo DEBUG cennik ID '.$debugId.': '.($calculatedMap[$debugId] ?? 'BRAK'));
            }
        }

        $allProducts = [];
        foreach ($allRaw as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pid = isset($p['id']) ? (int) $p['id'] : 0;
            if ($pid > 0 && isset($calculatedMap[$pid])) {
                $p['_plannerCalculatedPriceWithTax'] = $calculatedMap[$pid];
            }
            $allProducts[] = $this->mapApiloProductToStandard($p);
        }

        return $allProducts;
    }

    /**
     * Pobierz unikalne produkty z historii zamówień (fallback gdy Warehouse API niedostępne)
     * Sortuje po najnowszych zamówieniach, zbiera unikalne nazwy z prefiksem
     */
    private function fetchProductsFromOrders(string $token, ?string $namePrefix = null): array
    {
        $uniqueProducts = [];
        $seenNames = [];
        $offset = 0;
        $limit = 512; // max limit wg dokumentacji
        $maxOrders = 5000; // zabezpieczenie
        $fetched = 0;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/", [
                    'limit' => $limit,
                    'offset' => $offset,
                    'sort' => 'createdAtDesc',
                ]);

            if (! $response->successful()) {
                Log::warning('Apilo Orders for products: Błąd '.$response->status());
                break;
            }

            $data = $response->json();
            $orders = $data['orders'] ?? [];
            $totalCount = $data['totalCount'] ?? 0;

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                foreach ($order['orderItems'] ?? [] as $item) {
                    $name = $item['originalName'] ?? '';
                    if (empty($name)) {
                        continue;
                    }

                    // Filtruj po prefixie (jeśli podany) — bez rozróżniania wielkości liter
                    if ($namePrefix !== null && $namePrefix !== '' && ! str_starts_with(mb_strtolower($name), mb_strtolower($namePrefix))) {
                        continue;
                    }

                    // Pomijaj wysyłki/przesyłki (type=2 to shipping)
                    if (($item['type'] ?? 1) == 2) {
                        continue;
                    }

                    // Unikalna nazwa — zbieramy najwyższą cenę ze wszystkich zamówień
                    $nameKey = mb_strtolower($name);
                    $itemPrice = $this->parseApiloMoneyScalar($item['originalPriceWithTax'] ?? 0);

                    if (isset($seenNames[$nameKey])) {
                        // Aktualizuj cenę jeśli wyższa niż dotychczas znaleziona
                        $idx = $seenNames[$nameKey];
                        if ($itemPrice > ($uniqueProducts[$idx]['price'] ?? 0)) {
                            $uniqueProducts[$idx] = $this->mapApiloProductToStandard(array_merge($item, [
                                'productId' => $item['productId'] ?? null,
                                'originalName' => $name,
                                'originalPriceWithTax' => $item['originalPriceWithTax'] ?? 0,
                                'originalPriceWithoutTax' => $item['originalPriceWithoutTax'] ?? null,
                                'tax' => $item['tax'] ?? null,
                            ]));
                        }
                        continue;
                    }
                    $seenNames[$nameKey] = count($uniqueProducts);

                    $uniqueProducts[] = $this->mapApiloProductToStandard(array_merge($item, [
                        'productId' => $item['productId'] ?? null,
                        'originalName' => $name,
                        'originalPriceWithTax' => $item['originalPriceWithTax'] ?? 0,
                        'originalPriceWithoutTax' => $item['originalPriceWithoutTax'] ?? null,
                        'tax' => $item['tax'] ?? null,
                    ]));
                }
            }

            $fetched += count($orders);
            $offset += $limit;

            // Jeśli mamy wystarczająco dużo unikalnych produktów, zakończ
            if (count($uniqueProducts) >= 200) {
                break;
            }

        } while ($fetched < $totalCount && $fetched < $maxOrders);

        Log::info('Apilo: Pobrano '.count($uniqueProducts).' unikalnych produktów z '.$fetched.' zamówień');

        // Sortuj po nazwie
        usort($uniqueProducts, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $uniqueProducts;
    }

    // ==================== HELPERY ====================

    /**
     * Pobierz pierwszy ID platformy sprzedażowej
     */
    private function getFirstPlatformId(string $token): int
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/platforms/");

            if ($response->successful()) {
                $platforms = $response->json()['platforms'] ?? [];

                return $platforms[0]['id'] ?? 1;
            }
        } catch (\Exception $e) {
            Log::warning('Apilo: Nie udało się pobrać platform: '.$e->getMessage());
        }

        return 1;
    }

    /**
     * Pobierz pierwszy ID konta przewoźnika
     */
    private function getFirstCarrierAccountId(string $token): int
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/carrier-accounts/");

            if ($response->successful()) {
                $accounts = $response->json()['carrierAccounts'] ?? [];

                return $accounts[0]['id'] ?? 1;
            }
        } catch (\Exception $e) {
            Log::warning('Apilo: Nie udało się pobrać carrier accounts: '.$e->getMessage());
        }

        return 1;
    }

    /**
     * Pobierz pierwszy ID typu płatności
     */
    private function getFirstPaymentTypeId(string $token): int
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/rest/api/orders/payment-types/");

            if ($response->successful()) {
                $types = $response->json()['paymentTypes'] ?? [];

                return $types[0]['id'] ?? 1;
            }
        } catch (\Exception $e) {
            Log::warning('Apilo: Nie udało się pobrać payment types: '.$e->getMessage());
        }

        return 1;
    }

    // ==================== MOCK DATA ====================

    private function getMockOrders(int $clientId): array
    {
        return [
            [
                'id' => 'ORD-001',
                'date' => now()->subDays(5)->format('Y-m-d'),
                'status' => 'completed',
                'total' => 1250.00,
                'has_tracking_sent' => true,
                'payment_method' => 'Przelew',
                'payment_type_id' => 1,
                'products' => [
                    ['name' => 'TH_Panierka klasyczna 5kg', 'quantity' => 10, 'price' => 125.00],
                ],
            ],
            [
                'id' => 'ORD-002',
                'date' => now()->subDays(12)->format('Y-m-d'),
                'status' => 'completed',
                'total' => 2500.00,
                'has_tracking_sent' => false,
                'payment_method' => 'Za pobraniem',
                'payment_type_id' => 2,
                'products' => [
                    ['name' => 'TH_Panierka pikantna 5kg', 'quantity' => 20, 'price' => 125.00],
                ],
            ],
        ];
    }

    private function createMockOrder(array $data): array
    {
        $total = array_reduce($data['products'], function ($sum, $product) {
            return $sum + ($product['quantity'] * $product['price']);
        }, 0);

        $ptId = $data['payment_type'] ?? null;

        return [
            'id' => 'ORD-'.strtoupper(substr(md5(time()), 0, 6)),
            'date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'total' => $total,
            'products' => $data['products'],
            'payment_type_id' => $ptId,
            'payment_method' => $this->resolvePaymentTypeLabel($ptId),
        ];
    }

    private function getMockProducts(): array
    {
        $items = [
            ['id' => 1, 'name' => 'TH_Panierka klasyczna 5kg', 'price' => 125.00, 'sku' => 'TH-PAN-KLA-5', 'ean' => ''],
            ['id' => 2, 'name' => 'TH_Panierka pikantna 5kg', 'price' => 125.00, 'sku' => 'TH-PAN-PIK-5', 'ean' => ''],
            ['id' => 3, 'name' => 'TH_Panierka złocista 5kg', 'price' => 130.00, 'sku' => 'TH-PAN-ZLO-5', 'ean' => ''],
            ['id' => 4, 'name' => 'TH_Marynata uniwersalna 5L', 'price' => 89.00, 'sku' => 'TH-MAR-UNI-5', 'ean' => ''],
            ['id' => 5, 'name' => 'TH_Sos BBQ 5L', 'price' => 95.00, 'sku' => 'TH-SOS-BBQ-5', 'ean' => ''],
            ['id' => 6, 'name' => 'TH_Panierka chrupiąca 5kg', 'price' => 135.00, 'sku' => 'TH-PAN-CHR-5', 'ean' => ''],
            ['id' => 7, 'name' => 'TH_Przyprawa do kurczaka 1kg', 'price' => 45.00, 'sku' => 'TH-PRZ-KUR-1', 'ean' => ''],
            ['id' => 8, 'name' => 'TH_Sos czosnkowy 5L', 'price' => 85.00, 'sku' => 'TH-SOS-CZO-5', 'ean' => ''],
            ['id' => 100, 'name' => 'Opakowanie kartonowe', 'price' => 5.00, 'sku' => 'OPK-KAR', 'ean' => ''],
            ['id' => 101, 'name' => 'Etykieta naklejka', 'price' => 0.50, 'sku' => 'ETK-NAK', 'ean' => ''],
        ];

        return array_map(fn ($p) => $this->mapApiloProductToStandard($p), $items);
    }
}
