<?php

namespace App\Services;

use App\Services\Traits\LogsApiCalls;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FakturowniaService
{
    use LogsApiCalls;

    /** Dni po terminie płatności (przelew), po których faktura blokuje nowe zamówienia */
    private const INVOICE_OVERDUE_GRACE_DAYS = 10;

    private string $apiToken;
    private string $subdomain;
    private string $baseUrl;
    private ?CurrencyService $currencyService = null;

    public function __construct(?CurrencyService $currencyService = null)
    {
        $this->currencyService = $currencyService ?? app(CurrencyService::class);
        // Najpierw sprawdź env, potem ustawienia z bazy
        $this->apiToken = config('services.fakturownia.api_token', '');
        $this->subdomain = config('services.fakturownia.subdomain', '');
        
        // Jeśli brak w env, pobierz z ustawień
        if (empty($this->apiToken)) {
            $this->apiToken = \App\Models\Setting::get('fakturownia_api_token', '', 'core');
        }
        if (empty($this->subdomain)) {
            $this->subdomain = \App\Models\Setting::get('fakturownia_subdomain', '', 'core');
        }
        
        $this->baseUrl = "https://{$this->subdomain}.fakturownia.pl/";
    }

    /**
     * Zwraca klient Http z retry na błędy sieci i 5xx (2 próby, 400ms backoff).
     * Używać TYLKO dla idempotentnych żądań (GET) — nie używać dla POST (retry może zdublować fakturę).
     * throw: false → zwraca response z błędem zamiast rzucać wyjątkiem, zachowuje legacy behavior kontrolny przez $response->successful().
     */
    private function withRetry()
    {
        return Http::retry(2, 400, function ($exception) {
            if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                return true;
            }
            $response = method_exists($exception, 'response') ? $exception->response() : null;
            return $response && $response->status() >= 500;
        }, throw: false);
    }

    /**
     * Przelicz kwotę z faktury na PLN (uwzględnia walutę i kurs).
     */
    private function invoiceAmountToPln(array $invoice, string $field = 'price_gross'): float
    {
        return $this->toPlnFromInvoice($invoice, floatval($invoice[$field] ?? 0));
    }

    /**
     * Przelicz dowolną kwotę (w walucie faktury) na PLN.
     */
    private function toPlnFromInvoice(array $invoice, float $amount): float
    {
        $currency = strtoupper(trim($invoice['currency'] ?? 'PLN'));
        $exchangeRate = isset($invoice['exchange_currency_rate']) && $invoice['exchange_currency_rate'] !== ''
            ? floatval(str_replace(',', '.', (string) $invoice['exchange_currency_rate']))
            : null;

        return $this->currencyService->toPln($amount, $currency, $exchangeRate > 0 ? $exchangeRate : null);
    }

    /**
     * Czy integracja jest skonfigurowana
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiToken) && !empty($this->subdomain);
    }

    /**
     * Znormalizuj NIP (tylko cyfry) – Fakturownia może zwracać różne formaty
     */
    public static function normalizeNip(string $nip): string
    {
        return preg_replace('/\D/', '', $nip);
    }

    /**
     * Pobierz faktury dla klienta po NIP
     * 1. Szybka ścieżka: clients.json?query=NIP → client_id → invoices.json?client_id (1–2 req)
     * 2. Fallback: pobierz bez filtra, filtruj po NIP (gdy client_id nie działa)
     * - timeout 8s, connectTimeout 3s – szybsze wykrywanie wolnych odpowiedzi
     */
    public function getInvoicesForClient(string $nip): array
    {
        $nip = self::normalizeNip($nip);
        if (strlen($nip) < 10) {
            return [];
        }

        if (empty($this->apiToken)) {
            return $this->getMockInvoices($nip);
        }

        $cacheKey = "fakturownia_invoices_v5_{$nip}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $dateFrom = now()->subYears(2)->format('Y-m-d');
        $dateTo = now()->format('Y-m-d');

        // 1. Szybka ścieżka: znajdź client_id po NIP, potem faktury po client_id
        try {
            $clientId = $this->findClientIdByNip($nip);
            if ($clientId !== null) {
                $result = $this->loggedRequest('fakturownia', 'GET', 'invoices.json (client_id)', function () use ($clientId, $dateFrom, $dateTo) {
                    return $this->fetchInvoicesByClientId($clientId, $dateFrom, $dateTo);
                }, ['client_id' => $clientId]);

                $ttl = !empty($result) ? 300 : 60;
                $this->safeCachePut($cacheKey, $result, $ttl);
                return $result;
            }
        } catch (\Exception $e) {
            Log::warning('Fakturownia client_id path failed', ['nip' => $nip, 'message' => $e->getMessage()]);
        }

        // 2. Fallback: pobierz bez filtra, filtruj po NIP
        try {
            $result = $this->loggedRequest('fakturownia', 'GET', 'invoices.json (fallback)', function () use ($nip, $dateFrom, $dateTo) {
                return $this->fetchInvoicesWithoutNipFilter($nip, $dateFrom, $dateTo);
            }, ['nip' => $nip]);

            $ttl = !empty($result) ? 300 : 60;
            $this->safeCachePut($cacheKey, $result, $ttl);
            return $result;
        } catch (\Exception $e) {
            Log::error('Fakturownia getInvoicesForClient failed', ['nip' => $nip, 'message' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Znajdź client_id w Fakturowni po NIP (clients.json?query=...)
     */
    private function findClientIdByNip(string $nip): ?int
    {
        $response = $this->withRetry()->withHeaders(['Accept' => 'application/json'])
            ->timeout(8)
            ->connectTimeout(3)
            ->get("{$this->baseUrl}clients.json", [
                'api_token' => $this->apiToken,
                'query' => $nip,
                'per_page' => 20,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $clients = $response->json() ?? [];
        foreach ($clients as $client) {
            $clientNip = self::normalizeNip((string) ($client['tax_no'] ?? $client['nip'] ?? ''));
            if ($clientNip === $nip) {
                $id = (int) ($client['id'] ?? 0);
                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    /**
     * Pobierz faktury po client_id (szybsze niż buyer_tax_no)
     */
    private function fetchInvoicesByClientId(int $clientId, string $dateFrom, string $dateTo): array
    {
        $all = [];
        $page = 1;
        $perPage = 100;
        $maxPages = 20;

        do {
            $response = $this->withRetry()->withHeaders(['Accept' => 'application/json'])
                ->timeout(8)
                ->connectTimeout(3)
                ->get("{$this->baseUrl}invoices.json", [
                    'api_token' => $this->apiToken,
                    'client_id' => $clientId,
                    'period' => 'more',
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if (!$response->successful()) {
                break;
            }

            $invoices = $response->json() ?? [];
            $all = array_merge($all, $invoices);
            $page++;
        } while (count($invoices) >= $perPage && $page <= $maxPages);

        return $all;
    }

    /**
     * Fallback: pobierz faktury bez filtra i filtruj po NIP po stronie serwera.
     * Timeout 8s, max 10 stron – ograniczenie czasu pobierania.
     */
    private function fetchInvoicesWithoutNipFilter(string $nip, string $dateFrom, string $dateTo): array
    {
        $allFiltered = [];
        $page = 1;
        $perPage = 100;
        $maxPages = 10;

        do {
            $response = $this->withRetry()->withHeaders(['Accept' => 'application/json'])
                ->timeout(8)
                ->connectTimeout(3)
                ->get("{$this->baseUrl}invoices.json", [
                    'api_token' => $this->apiToken,
                    'period' => 'more',
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if (!$response->successful()) {
                break;
            }

            $invoices = $response->json() ?? [];
            foreach ($invoices as $inv) {
                $buyerNip = self::normalizeNip((string) ($inv['buyer_tax_no'] ?? ''));
                if ($buyerNip === $nip) {
                    $allFiltered[] = $inv;
                }
            }

            $page++;
        } while (count($invoices) >= $perPage && $page <= $maxPages);

        return $allFiltered;
    }

    /**
     * Pobierz szczegóły faktury (z retry i normalizacją pozycji)
     */
    public function getInvoice(int $invoiceId): ?array
    {
        if (empty($this->apiToken)) {
            return $this->getMockInvoiceDetail($invoiceId);
        }

        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->withRetry()->withHeaders([
                    'Accept' => 'application/json',
                ])->timeout(15)
                  ->connectTimeout(5)
                  ->get("{$this->baseUrl}invoices/{$invoiceId}.json", [
                      'api_token' => $this->apiToken,
                  ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if ($data) {
                        return $this->normalizeInvoiceForFrontend($data);
                    }
                } elseif ($response->status() >= 500 && $attempt < $maxAttempts) {
                    usleep(300000 * $attempt); // 300ms, 600ms backoff
                    continue;
                }
            } catch (\Exception $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    usleep(300000 * $attempt);
                }
            }
        }

        Log::error('Fakturownia Get Invoice Error', [
            'invoice_id' => $invoiceId,
            'attempts' => $maxAttempts,
            'message' => $lastException?->getMessage(),
        ]);

        return null;
    }

    /**
     * Normalizuj odpowiedź faktury – Fakturownia może zwracać positions, invoice_positions, products
     */
    private function normalizeInvoiceForFrontend(array $data): array
    {
        $positions = $data['positions'] ?? $data['invoice_positions'] ?? $data['products'] ?? [];
        if (!is_array($positions)) {
            $positions = [];
        }
        $normalized = [];
        foreach ($positions as $p) {
            if (!is_array($p)) continue;
            $normalized[] = [
                'name' => $p['name'] ?? $p['description'] ?? $p['title'] ?? '—',
                'quantity' => $p['quantity'] ?? $p['qty'] ?? $p['count'] ?? 0,
                'price_net' => $p['price_net'] ?? $p['price'] ?? $p['net_price'] ?? 0,
                'total_price_net' => $p['total_price_net'] ?? $p['total_net'] ?? $p['total_price'] ?? 0,
            ];
        }
        $data['positions'] = $normalized;
        return $data;
    }

    /**
     * Pobierz PDF faktury
     */
    public function getInvoicePdf(int $invoiceId): ?string
    {
        if (empty($this->apiToken)) {
            return null;
        }

        try {
            $response = $this->withRetry()->get("{$this->baseUrl}invoices/{$invoiceId}.pdf", [
                'api_token' => $this->apiToken,
            ]);

            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Exception $e) {
            Log::error('Fakturownia PDF Error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Utwórz fakturę
     */
    public function createInvoice(array $data): ?array
    {
        if (empty($this->apiToken)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}invoices.json", [
                'api_token' => $this->apiToken,
                'invoice' => $data,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('Fakturownia Create Invoice Error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Pobierz klientów z Fakturowni
     */
    /**
     * Pobierz unikalnych nabywców z faktur sprzedażowych (ostatnie 2 lata).
     * Zwraca: [{name, phone, email, nip}]
     */
    public function getSalesClients(): array
    {
        $cacheKey = 'fakturownia_sales_clients_v1_' . md5($this->subdomain);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $dateFrom = now()->subYears(2)->format('Y-m-d');
        $dateTo = now()->format('Y-m-d');
        $clients = [];
        $seenNips = [];
        $seenNames = [];
        $page = 1;

        while ($page <= 30) {
            try {
                $response = $this->withRetry()->timeout(10)->connectTimeout(3)->get("{$this->baseUrl}invoices.json", [
                    'api_token' => $this->apiToken,
                    'per_page' => 100,
                    'page' => $page,
                    'period' => 'more',
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'kind' => 'vat',  // Tylko faktury VAT (sprzedażowe)
                ]);
                if (!$response->successful()) break;
                $invoices = $response->json();
                if (!is_array($invoices) || empty($invoices)) break;

                foreach ($invoices as $inv) {
                    $name = trim($inv['buyer_name'] ?? '');
                    $nip = self::normalizeNip($inv['buyer_tax_no'] ?? '');
                    $phone = trim($inv['buyer_phone'] ?? '');
                    $email = trim($inv['buyer_email'] ?? '');

                    if (empty($name)) continue;
                    $nameKey = mb_strtolower($name);

                    // Deduplikacja po NIP lub nazwie
                    if ($nip && isset($seenNips[$nip])) continue;
                    if (isset($seenNames[$nameKey])) continue;

                    if ($nip) $seenNips[$nip] = true;
                    $seenNames[$nameKey] = true;

                    $clients[] = [
                        'name' => $name,
                        'nip' => $nip,
                        'phone' => $phone,
                        'email' => $email,
                    ];
                }

                if (count($invoices) < 100) break;
                $page++;
            } catch (\Throwable $e) {
                Log::warning('Fakturownia getSalesClients error', ['page' => $page, 'error' => $e->getMessage()]);
                break;
            }
        }

        Cache::put($cacheKey, $clients, now()->addHours(2));
        Log::info('Fakturownia: pobrano ' . count($clients) . ' klientów z faktur sprzedażowych');

        return $clients;
    }

    public function getClients(array $filters = []): array
    {
        if (empty($this->apiToken)) {
            return [];
        }

        try {
            $response = $this->withRetry()->withHeaders([
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}clients.json", array_merge([
                'api_token' => $this->apiToken,
                'per_page' => 100,
            ], $filters));

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Fakturownia Clients Error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Pobierz działy z Fakturowni
     */
    public function getDepartments(): array
    {
        $cacheKey = 'fakturownia_departments';
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (empty($this->apiToken)) {
            return $this->getMockDepartments();
        }

        try {
            $departments = $this->loggedRequest('fakturownia', 'GET', 'departments.json', function () {
                $response = $this->withRetry()->withHeaders([
                    'Accept' => 'application/json',
                ])->get("{$this->baseUrl}departments.json", [
                    'api_token' => $this->apiToken,
                ]);

                if ($response->successful()) {
                    return $response->json() ?? [];
                }
                return null;
            });

            if ($departments !== null) {
                Cache::put($cacheKey, $departments, 3600);
                return $departments;
            }
        } catch (\Exception $e) {
            Log::error('Fakturownia Departments Error: ' . $e->getMessage());
        }

        return $this->getMockDepartments();
    }

    /**
     * Mock departments dla developmentu
     */
    private function getMockDepartments(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Dział sprzedaży',
                'shortcut' => 'DS',
                'kind' => 'selling',
            ],
            [
                'id' => 2,
                'name' => 'Dział zakupów',
                'shortcut' => 'DZ',
                'kind' => 'buying',
            ],
            [
                'id' => 3,
                'name' => 'Dział serwisu',
                'shortcut' => 'SRV',
                'kind' => 'selling',
            ],
        ];
    }

    /**
     * Sprawdź połączenie z Fakturownia
     */
    /**
     * Pobierz produkty z Fakturowni (opcjonalnie filtrowane po prefiksie nazwy).
     * Cache: 1 godzina. Wynik: tablica [{id, name, sku, ean, price, price_net, tax_rate}].
     */
    public function getProducts(?string $namePrefix = null, bool $forceRefresh = false): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $cacheKey = 'fakturownia_products_v1_' . md5($this->subdomain . ($namePrefix ?? ''));

        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $products = [];
        $page = 1;

        while (true) {
            $response = $this->withRetry()->timeout(15)->get("{$this->baseUrl}products.json", [
                'api_token' => $this->apiToken,
                'per_page'  => 100,
                'page'      => $page,
            ]);

            if (! $response->successful()) {
                Log::warning('Fakturownia getProducts: HTTP ' . $response->status() . " (page {$page})");
                break;
            }

            $list = $response->json();
            if (! is_array($list) || empty($list)) {
                break;
            }

            foreach ($list as $p) {
                if (! is_array($p) || ($p['deleted'] ?? false) || ($p['disabled'] ?? false)) {
                    continue;
                }
                $name = $p['name'] ?? '';
                if ($namePrefix !== null && $namePrefix !== '' && ! str_starts_with($name, $namePrefix)) {
                    continue;
                }
                $priceGross = round((float) ($p['price_gross'] ?? 0), 2);
                $priceNet   = round((float) ($p['price_net']   ?? 0), 2);
                $taxRate    = (float) ($p['tax'] ?? 23);
                $products[] = [
                    'id'        => $p['id'] ?? null,
                    'name'      => $name,
                    'sku'       => $p['code'] ?? '',
                    'ean'       => $p['ean_code'] ?? '',
                    'price'     => $priceGross,
                    'price_net' => $priceNet,
                    'tax_rate'  => $taxRate,
                ];
            }

            if (count($list) < 100) {
                break;
            }
            $page++;
        }

        usort($products, fn ($a, $b) => strcmp($a['name'], $b['name']));

        Log::info('Fakturownia: pobrano ' . count($products) . ' produktów (prefix: ' . ($namePrefix ?? 'brak') . ')');
        Cache::put($cacheKey, $products, now()->addHour());

        return $products;
    }

    public function testConnection(): array
    {
        if (empty($this->apiToken) || empty($this->subdomain)) {
            return [
                'success' => false,
                'message' => 'Brak konfiguracji API (api_token lub subdomain)',
            ];
        }

        try {
            $response = $this->withRetry()->withHeaders([
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}account.json", [
                'api_token' => $this->apiToken,
            ]);

            if ($response->successful()) {
                $account = $response->json();
                return [
                    'success' => true,
                    'message' => 'Połączenie aktywne',
                    'account' => [
                        'name' => $account['name'] ?? 'Nieznane',
                        'email' => $account['email'] ?? '',
                    ],
                ];
            }

            return [
                'success' => false,
                'message' => 'Błąd autoryzacji - sprawdź token API',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Błąd połączenia: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Czy faktura jest pobraniowa (płatność przy odbiorze).
     * Nie wlicza się do zaległości przelewowych; nieopłacona pobraniowa blokuje kolejne zamówienie za pobraniem.
     */
    private function isInvoiceCod(array $invoice): bool
    {
        $candidates = [
            $invoice['payment_type'] ?? null,
            $invoice['payment_type_name'] ?? null,
            $invoice['payment_kind'] ?? null,
            $invoice['payment'] ?? null,
            $invoice['description'] ?? null,
            $invoice['description_footer'] ?? null,
            $invoice['additional_description'] ?? null,
        ];
        foreach ($candidates as $paymentType) {
            $name = null;
            if (is_string($paymentType)) {
                $name = $paymentType;
            } elseif (is_array($paymentType) && isset($paymentType['name'])) {
                $name = (string) $paymentType['name'];
            }
            if ($name === null || $name === '') {
                continue;
            }
            $lower = mb_strtolower($name);
            if (stripos($lower, 'pobran') !== false) {
                return true;
            }
            if (str_contains($lower, 'cash_on_delivery') || $lower === 'cod') {
                return true;
            }
        }

        return false;
    }

    /**
     * Statystyki płatności dla klienta
     */
    public function getClientPaymentStats(string $nip): array
    {
        $invoices = $this->getInvoicesForClient($nip);

        $total = 0;
        $paid = 0;
        $unpaid = 0;
        $overdue = 0;
        $unpaidCod = 0;

        foreach ($invoices as $invoice) {
            $amount = $this->invoiceAmountToPln($invoice, 'price_gross');
            $total += $amount;

            if ($invoice['status'] === 'paid') {
                $paid += $amount;
            } else {
                $unpaid += $amount;

                if ($this->isInvoiceCod($invoice)) {
                    $unpaidCod += $amount;

                    continue;
                }

                $dueDate = $invoice['payment_to'] ?? null;
                if ($dueDate) {
                    $dueStr = substr((string) $dueDate, 0, 10);
                    $graceEnd = \Carbon\Carbon::parse($dueStr)
                        ->addDays(self::INVOICE_OVERDUE_GRACE_DAYS)
                        ->format('Y-m-d');
                    $today = now()->format('Y-m-d');
                    if ($today > $graceEnd) {
                        $overdue += $amount;
                    }
                }
            }
        }

        return [
            'total' => $total,
            'paid' => $paid,
            'unpaid' => $unpaid,
            'overdue' => $overdue,
            'unpaid_cod' => $unpaidCod,
            'count' => count($invoices),
        ];
    }

    /**
     * Pobierz statystyki przychodów dla dashboardu
     * @param string $period - 'day', 'week', 'month', 'year'
     * @param int|null $departmentId - ID działu w Fakturowni
     */
    public function getRevenueStats(string $period = 'month', ?int $departmentId = null): array
    {
        // Określ daty
        $endDate = now();
        $startDate = match($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        // Cache key zawiera dział i okres
        $cacheKey = "fakturownia_revenue_{$period}_" . ($departmentId ?? 'all') . "_{$startDate->format('Y-m-d')}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Dla developmentu - generuj mockowe dane
        if (!$this->isConfigured()) {
            $mockData = $this->getMockRevenueStats($period, $startDate, $endDate, $departmentId);
            Cache::put($cacheKey, $mockData, 300); // Cache na 5 minut
            return $mockData;
        }

        try {
            // Fakturownia API wymaga period=more aby date_from/date_to działały
            // Mapowanie okresów na parametry API Fakturowni
            $apiPeriod = match($period) {
                'month' => 'this_month',
                'year' => 'this_year',
                default => 'more', // day, week - użyj custom date range
            };

            $params = [
                'api_token' => $this->apiToken,
                'period' => $apiPeriod,
                'per_page' => 100,
                'page' => 1,
            ];

            // Dla period=more dodaj date_from/date_to
            if ($apiPeriod === 'more') {
                $params['date_from'] = $startDate->format('Y-m-d');
                $params['date_to'] = $endDate->format('Y-m-d');
            }

            if ($departmentId) {
                $params['department_id'] = $departmentId;
            }

            $logParams = array_diff_key($params, ['api_token' => true]);

            $allInvoices = $this->loggedRequest('fakturownia', 'GET', 'invoices.json (revenue)', function () use ($params) {
                $results = [];
                for ($page = 1; $page <= 50; $page++) {
                    $params['page'] = $page;
                    $response = $this->withRetry()->withHeaders([
                        'Accept' => 'application/json',
                    ])->get("{$this->baseUrl}invoices.json", $params);

                    if (!$response->successful()) break;
                    $invoices = $response->json() ?? [];
                    if (empty($invoices)) break;
                    $results = array_merge($results, $invoices);
                    if (count($invoices) < $params['per_page']) break;
                }
                return $results;
            }, $logParams);

            $stats = $this->aggregateRevenueData($allInvoices, $period, $startDate, $endDate);
            $this->safeCachePut($cacheKey, $stats, 300);
            return $stats;
        } catch (\Exception $e) {
            Log::error('Fakturownia Revenue Stats Error: ' . $e->getMessage());
        }

        $mockData = $this->getMockRevenueStats($period, $startDate, $endDate, $departmentId);
        return $mockData;
    }

    /**
     * Agreguj dane przychodów
     */
    private function aggregateRevenueData(array $invoices, string $period, $startDate, $endDate): array
    {
        $data = [];
        $labels = [];
        $totalRevenue = 0;
        $paidRevenue = 0;
        $unpaidRevenue = 0;
        
        // Generuj etykiety i inicjalizuj dane
        $current = clone $startDate;
        while ($current <= $endDate) {
            $key = match($period) {
                'day' => $current->format('H:00'),
                'week' => $current->format('D'),
                'month' => $current->format('d'),
                'year' => $current->format('M'),
            };
            
            $labels[] = $key;
            $data[$key] = ['revenue' => 0, 'paid' => 0, 'unpaid' => 0];
            
            $current = match($period) {
                'day' => $current->addHour(),
                'week' => $current->addDay(),
                'month' => $current->addDay(),
                'year' => $current->addMonth(),
            };
        }

        // Agreguj faktury (kwoty przeliczane na PLN)
        foreach ($invoices as $invoice) {
            $amount = $this->invoiceAmountToPln($invoice, 'price_gross');
            $totalRevenue += $amount;
            
            $date = $invoice['issue_date'] ?? null;
            if ($date) {
                $dateObj = \Carbon\Carbon::parse($date);
                $key = match($period) {
                    'day' => $dateObj->format('H:00'),
                    'week' => $dateObj->format('D'),
                    'month' => $dateObj->format('d'),
                    'year' => $dateObj->format('M'),
                };
                
                if (isset($data[$key])) {
                    $data[$key]['revenue'] += $amount;
                    
                    if (($invoice['status'] ?? '') === 'paid') {
                        $data[$key]['paid'] += $amount;
                        $paidRevenue += $amount;
                    } else {
                        $data[$key]['unpaid'] += $amount;
                        $unpaidRevenue += $amount;
                    }
                }
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                'revenue' => array_column(array_values($data), 'revenue'),
                'paid' => array_column(array_values($data), 'paid'),
                'unpaid' => array_column(array_values($data), 'unpaid'),
            ],
            'totals' => [
                'revenue' => $totalRevenue,
                'paid' => $paidRevenue,
                'unpaid' => $unpaidRevenue,
                'invoiceCount' => count($invoices),
            ],
            'period' => $period,
        ];
    }

    /**
     * Mock data dla statystyk przychodów
     * Używa seeded random dla spójnych danych
     */
    private function getMockRevenueStats(string $period, $startDate, $endDate, ?int $departmentId = null): array
    {
        $data = [];
        $labels = [];
        $totalRevenue = 0;
        $paidRevenue = 0;
        
        // Użyj seeded random dla spójnych danych (na podstawie daty i działu)
        $seed = crc32($startDate->format('Y-m-d') . $period . ($departmentId ?? 0));
        mt_srand($seed);
        
        // Mnożnik dla działu (mniejsze dane gdy filtrowane po dziale)
        $multiplier = $departmentId ? 0.35 : 1.0; // Dział = 35% całości
        
        $current = clone $startDate;
        $index = 0;
        while ($current <= $endDate) {
            $key = match($period) {
                'day' => $current->format('H:00'),
                'week' => $current->format('D'),
                'month' => $current->format('d'),
                'year' => $current->format('M'),
            };
            
            // Generuj dane z seeded random
            $baseRevenue = mt_rand(500, 5000) * 10;
            $revenue = round($baseRevenue * $multiplier);
            $paidPercent = mt_rand(60, 95) / 100;
            $paid = round($revenue * $paidPercent);
            
            $labels[] = $key;
            $data[] = [
                'revenue' => $revenue,
                'paid' => $paid,
                'unpaid' => $revenue - $paid,
            ];
            
            $totalRevenue += $revenue;
            $paidRevenue += $paid;
            
            $current = match($period) {
                'day' => $current->addHour(),
                'week' => $current->addDay(),
                'month' => $current->addDay(),
                'year' => $current->addMonth(),
            };
            $index++;
        }
        
        // Reset random seed
        mt_srand();
        
        $invoiceCount = $departmentId ? mt_rand(5, 20) : mt_rand(15, 50);

        return [
            'labels' => $labels,
            'datasets' => [
                'revenue' => array_column($data, 'revenue'),
                'paid' => array_column($data, 'paid'),
                'unpaid' => array_column($data, 'unpaid'),
            ],
            'totals' => [
                'revenue' => $totalRevenue,
                'paid' => $paidRevenue,
                'unpaid' => $totalRevenue - $paidRevenue,
                'invoiceCount' => $invoiceCount,
            ],
            'period' => $period,
        ];
    }

    /**
     * Pobierz faktury dla okresu (z paginacją)
     */
    public function fetchInvoicesForPeriod(string $period = 'month', ?int $departmentId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            $startDate = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $endDate = \Carbon\Carbon::parse($dateTo)->endOfDay();
        } else {
            $endDate = now();
            $startDate = match ($period) {
                'day' => now()->startOfDay(),
                'week' => now()->startOfWeek(),
                'month' => now()->startOfMonth(),
                'quarter' => now()->subMonths(3)->startOfMonth(),
                'year' => now()->startOfYear(),
                default => now()->startOfMonth(),
            };
        }

        $cacheKey = "fakturownia_invoices_list_{$period}_" . ($departmentId ?? 'all') . "_{$startDate->format('Y-m-d')}_{$endDate->format('Y-m-d')}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (!$this->isConfigured()) {
            $data = $this->getMockInvoicesForPeriod($period, $startDate, $endDate, $departmentId);
            $data = $this->excludeProformaInvoices($data);
            $this->safeCachePut($cacheKey, $data, 300);
            return $data;
        }

        $apiPeriod = match ($period) {
            'month' => 'this_month',
            'year' => 'this_year',
            default => 'more',
        };

        $params = [
            'api_token' => $this->apiToken,
            'period' => $apiPeriod,
            'per_page' => 100,
            'page' => 1,
        ];

        if ($apiPeriod === 'more') {
            $params['date_from'] = $startDate->format('Y-m-d');
            $params['date_to'] = $endDate->format('Y-m-d');
        }

        if ($departmentId) {
            $params['department_id'] = $departmentId;
        }

        $logParams = array_diff_key($params, ['api_token' => true]);

        $allInvoices = [];
        try {
            $allInvoices = $this->loggedRequest('fakturownia', 'GET', 'invoices.json (period)', function () use ($params) {
                $results = [];
                for ($page = 1; $page <= 50; $page++) {
                    $params['page'] = $page;
                    $response = $this->withRetry()->withHeaders(['Accept' => 'application/json'])
                        ->get("{$this->baseUrl}invoices.json", $params);

                    if (!$response->successful()) break;
                    $invoices = $response->json() ?? [];
                    if (empty($invoices)) break;
                    $results = array_merge($results, $invoices);
                    if (count($invoices) < $params['per_page']) break;
                }
                return $results;
            }, $logParams);
        } catch (\Exception $e) {
            Log::error('Fakturownia fetchInvoicesForPeriod error: ' . $e->getMessage());
        }

        $allInvoices = $this->excludeProformaInvoices($allInvoices);

        $this->safeCachePut($cacheKey, $allInvoices, 300);
        return $allInvoices;
    }

    /**
     * Wyklucz faktury proforma z listy
     */
    private function excludeProformaInvoices(array $invoices): array
    {
        return array_values(array_filter($invoices, function ($inv) {
            $kind = $inv['kind'] ?? '';
            return !in_array($kind, ['proforma', 'Proforma']);
        }));
    }

    /**
     * Statystyki marżowości
     */
    public function getMarginStats(string $period = 'month', ?int $departmentId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $cacheKey = "fakturownia_margin_{$period}_" . ($departmentId ?? 'all') . "_{$dateFrom}_{$dateTo}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $invoices = $this->fetchInvoicesForPeriod($period, $departmentId, $dateFrom, $dateTo);
        $deptLookup = $this->buildDepartmentLookup();
        $stats = $this->aggregateMarginData($invoices, $deptLookup);

        $this->safeCachePut($cacheKey, $stats, 300);
        return $stats;
    }

    /**
     * Statystyki produktów (ranking wg przychodu)
     */
    public function getProductStats(string $period = 'month', ?int $departmentId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $cacheKey = "fakturownia_products_{$period}_" . ($departmentId ?? 'all') . "_{$dateFrom}_{$dateTo}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $invoices = $this->fetchInvoicesForPeriod($period, $departmentId, $dateFrom, $dateTo);

        if (!$this->isConfigured()) {
            $stats = $this->aggregateProductsFromMock($invoices);
            $this->safeCachePut($cacheKey, $stats, 300);
            return $stats;
        }

        $invoiceIds = array_filter(array_column($invoices, 'id'));

        // Sort by value descending - analyze highest-value invoices first (kwoty w PLN)
        $invoiceValues = [];
        foreach ($invoices as $inv) {
            $id = $inv['id'] ?? null;
            if ($id) {
                $invoiceValues[$id] = $this->invoiceAmountToPln($inv, 'price_net');
            }
        }
        arsort($invoiceValues);
        $invoiceIds = array_keys($invoiceValues);

        $maxInvoices = 300;
        $invoiceIds = array_slice($invoiceIds, 0, $maxInvoices);

        $details = $this->batchFetchInvoiceDetails($invoiceIds);

        $products = [];
        foreach ($details as $detail) {
            if (!isset($detail['positions'])) continue;
            foreach ($detail['positions'] as $pos) {
                $name = $pos['name'] ?? 'Nieznany';
                $qty = floatval($pos['quantity'] ?? 0);
                $totalNet = floatval($pos['total_price_net'] ?? 0);
                $totalNetPln = $this->toPlnFromInvoice($detail, $totalNet);

                if (!isset($products[$name])) {
                    $products[$name] = [
                        'name' => $name,
                        'quantity' => 0,
                        'revenue' => 0,
                        'invoice_count' => 0,
                    ];
                }
                $products[$name]['quantity'] += $qty;
                $products[$name]['revenue'] += $totalNetPln;
                $products[$name]['invoice_count']++;
            }
        }

        usort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $stats = [
            'products' => array_slice(array_values($products), 0, 50),
            'analyzed_count' => count($details),
            'total_count' => count($invoices),
        ];

        $this->safeCachePut($cacheKey, $stats, 3600);
        return $stats;
    }

    private function batchFetchInvoiceDetails(array $invoiceIds): array
    {
        $results = [];
        $uncachedIds = [];

        foreach ($invoiceIds as $id) {
            $cached = Cache::get("fakturownia_inv_{$id}");
            if ($cached) {
                $results[$id] = $cached;
            } else {
                $uncachedIds[] = $id;
            }
        }

        if (empty($uncachedIds)) {
            return $results;
        }

        try {
            $fetched = $this->loggedRequest('fakturownia', 'GET', 'invoices/{id}.json (batch)', function () use ($uncachedIds) {
                $batchResults = [];
                $batches = array_chunk($uncachedIds, 20);

                foreach ($batches as $batch) {
                    $responses = Http::pool(fn (Pool $pool) =>
                        array_map(fn ($id) =>
                            $pool->as("inv_{$id}")
                                ->withHeaders(['Accept' => 'application/json'])
                                ->get("{$this->baseUrl}invoices/{$id}.json", ['api_token' => $this->apiToken]),
                            $batch
                        )
                    );

                    foreach ($batch as $id) {
                        $response = $responses["inv_{$id}"] ?? null;
                        if ($response && $response->successful()) {
                            $detail = $response->json();
                            $this->safeCachePut("fakturownia_inv_{$id}", $detail, 86400);
                            $batchResults[$id] = $detail;
                        }
                    }
                }
                return $batchResults;
            }, ['count' => count($uncachedIds)]);

            $results = array_replace($results, $fetched);
        } catch (\Exception $e) {
            Log::error('Fakturownia batchFetchInvoiceDetails error: ' . $e->getMessage());
        }

        return $results;
    }

    private function aggregateProductsFromMock(array $invoices): array
    {
        $productNames = [
            'Filet z kurczaka 1kg', 'Udko kurczaka 2kg', 'Skrzydełka BBQ 1kg',
            'Pierś kurczaka 5kg', 'Kurczak cały 1.5kg', 'Stripsy panierowane 1kg',
            'Nuggetsy 0.5kg', 'Sos chipotle 0.3L', 'Frytki mrożone 2.5kg',
            'Produkt A', 'Produkt B', 'Produkt C',
        ];

        $products = [];
        $seed = crc32(json_encode(array_column($invoices, 'id')));
        mt_srand($seed);

        foreach ($productNames as $name) {
            $products[] = [
                'name' => $name,
                'quantity' => mt_rand(50, 800),
                'revenue' => mt_rand(5000, 80000),
                'invoice_count' => mt_rand(10, 200),
            ];
        }

        mt_srand();
        usort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'products' => $products,
            'analyzed_count' => count($invoices),
            'total_count' => count($invoices),
        ];
    }

    private function buildDepartmentLookup(): array
    {
        $departments = $this->getDepartments();
        $lookup = [];
        foreach ($departments as $dept) {
            $id = $dept['id'] ?? 0;
            $shortcut = $dept['shortcut'] ?? '';
            $name = $dept['name'] ?? '';
            $lookup[$id] = $shortcut ?: $name ?: ('Dział #' . $id);
        }
        return $lookup;
    }

    private function aggregateMarginData(array $invoices, array $deptLookup = []): array
    {
        $clients = [];
        $departments = [];
        $totalRevenue = 0;
        $totalRevenueGross = 0;
        $totalCost = 0;
        $correctionCount = 0;
        $correctionNetTotal = 0;
        $correctionGrossTotal = 0;
        $regularCount = 0;

        foreach ($invoices as $inv) {
            $kind = $inv['kind'] ?? 'vat';
            $isCorrection = in_array($kind, ['correction', 'correction_note']);

            $buyerName = $inv['buyer_name'] ?? 'Nieznany';
            $buyerNip = $inv['buyer_tax_no'] ?? '';
            $deptId = $inv['department_id'] ?? 0;
            $deptName = $deptLookup[$deptId] ?? ($inv['seller_name'] ?? 'Główny');
            $revenue = $this->invoiceAmountToPln($inv, 'price_net');
            $revenueGross = $this->invoiceAmountToPln($inv, 'price_gross');

            if ($isCorrection) {
                $correctionCount++;
                $correctionNetTotal += $revenue;
                $correctionGrossTotal += $revenueGross;
            } else {
                $regularCount++;
            }

            $productsMargin = isset($inv['products_margin']) ? floatval($inv['products_margin']) : null;
            $hasCost = $productsMargin !== null;
            $margin = $hasCost ? $this->toPlnFromInvoice($inv, $productsMargin) : $revenue;
            $cost = $hasCost ? $revenue - $margin : 0.0;

            $totalRevenue += $revenue;
            $totalRevenueGross += $revenueGross;
            $totalCost += $cost;

            $clientKey = $buyerNip ?: $buyerName;
            if (!isset($clients[$clientKey])) {
                $clients[$clientKey] = [
                    'name' => $buyerName,
                    'nip' => $buyerNip,
                    'revenue' => 0,
                    'revenue_gross' => 0,
                    'cost' => 0,
                    'margin' => 0,
                    'invoice_count' => 0,
                    'has_cost_data' => false,
                ];
            }
            $clients[$clientKey]['revenue'] += $revenue;
            $clients[$clientKey]['revenue_gross'] += $revenueGross;
            $clients[$clientKey]['cost'] += $cost;
            $clients[$clientKey]['margin'] += $margin;
            $clients[$clientKey]['invoice_count']++;
            if ($hasCost) $clients[$clientKey]['has_cost_data'] = true;

            if (!isset($departments[$deptId])) {
                $departments[$deptId] = [
                    'id' => $deptId,
                    'name' => $deptName,
                    'revenue' => 0,
                    'revenue_gross' => 0,
                    'cost' => 0,
                    'margin' => 0,
                    'invoice_count' => 0,
                ];
            }
            $departments[$deptId]['revenue'] += $revenue;
            $departments[$deptId]['revenue_gross'] += $revenueGross;
            $departments[$deptId]['cost'] += $cost;
            $departments[$deptId]['margin'] += $margin;
            $departments[$deptId]['invoice_count']++;
        }

        foreach ($clients as &$c) {
            $c['margin_percent'] = $c['revenue'] > 0
                ? round(($c['margin'] / $c['revenue']) * 100, 1)
                : 0;
        }
        unset($c);

        foreach ($departments as &$d) {
            $d['margin_percent'] = $d['revenue'] > 0
                ? round(($d['margin'] / $d['revenue']) * 100, 1)
                : 0;
        }
        unset($d);

        usort($clients, fn($a, $b) => $b['margin'] <=> $a['margin']);
        $departments = array_values($departments);
        usort($departments, fn($a, $b) => $b['margin'] <=> $a['margin']);

        $totalMargin = $totalRevenue - $totalCost;

        return [
            'topClients' => array_slice(array_values($clients), 0, 30),
            'allClients' => array_values($clients),
            'departments' => $departments,
            'totals' => [
                'revenue' => round($totalRevenue, 2),
                'revenue_gross' => round($totalRevenueGross, 2),
                'cost' => round($totalCost, 2),
                'margin' => round($totalMargin, 2),
                'margin_percent' => $totalRevenue > 0
                    ? round(($totalMargin / $totalRevenue) * 100, 1) : 0,
                'invoice_count' => count($invoices),
                'regular_count' => $regularCount,
                'correction_count' => $correctionCount,
                'correction_net' => round($correctionNetTotal, 2),
                'correction_gross' => round($correctionGrossTotal, 2),
                'client_count' => count($clients),
            ],
        ];
    }

    private function getMockInvoicesForPeriod(string $period, $startDate, $endDate, ?int $departmentId): array
    {
        $seed = crc32($startDate->format('Y-m-d') . $period . ($departmentId ?? 0));
        mt_srand($seed);

        $mockClients = [
            ['name' => 'Restauracja Pod Lwem', 'nip' => '5213456789'],
            ['name' => 'Bar u Wojtka', 'nip' => '7891234560'],
            ['name' => 'Pizza Roma', 'nip' => '1234567890'],
            ['name' => 'Kebab King', 'nip' => '9876543210'],
            ['name' => 'Bistro Smaczne', 'nip' => '5678901234'],
            ['name' => 'Food Truck Maro', 'nip' => '3456789012'],
            ['name' => 'Grill Master', 'nip' => '6789012345'],
            ['name' => 'Kuchnia Babci', 'nip' => '2345678901'],
            ['name' => 'Street Food Corner', 'nip' => '8901234567'],
            ['name' => 'Smaki Orientu', 'nip' => '4567890123'],
        ];

        $mockDepts = [
            ['id' => 1, 'name' => 'Dział sprzedaży'],
            ['id' => 2, 'name' => 'Dział zakupów'],
            ['id' => 3, 'name' => 'Dział serwisu'],
        ];

        $invoices = [];
        $current = clone $startDate;
        while ($current <= $endDate) {
            $invoicesPerDay = mt_rand(1, 4);
            for ($j = 0; $j < $invoicesPerDay; $j++) {
                $client = $mockClients[mt_rand(0, count($mockClients) - 1)];
                $dept = $mockDepts[mt_rand(0, count($mockDepts) - 1)];

                if ($departmentId && $dept['id'] !== $departmentId) continue;

                $posCount = mt_rand(1, 4);
                $positions = [];
                $totalNet = 0;
                $totalCost = 0;
                for ($k = 0; $k < $posCount; $k++) {
                    $qty = mt_rand(5, 100);
                    $priceNet = mt_rand(8, 30) + mt_rand(0, 99) / 100;
                    $buyingNet = round($priceNet * (mt_rand(40, 75) / 100), 2);
                    $posTotal = round($priceNet * $qty, 2);
                    $totalNet += $posTotal;
                    $totalCost += $buyingNet * $qty;
                    $positions[] = [
                        'name' => ['Filet z kurczaka', 'Udko kurczaka', 'Skrzydełka', 'Pierś kurczaka', 'Kurczak cały'][mt_rand(0, 4)] . ' ' . mt_rand(1, 5) . 'kg',
                        'quantity' => (string) $qty,
                        'price_net' => number_format($priceNet, 2, '.', ''),
                        'total_price_net' => number_format($posTotal, 2, '.', ''),
                        'total_price_gross' => number_format($posTotal * 1.23, 2, '.', ''),
                        'buying_net_price' => number_format($buyingNet, 2, '.', ''),
                    ];
                }

                $invoices[] = [
                    'id' => mt_rand(10000, 99999),
                    'number' => 'FV/' . $current->format('Y/m') . '/' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT),
                    'issue_date' => $current->format('Y-m-d'),
                    'buyer_name' => $client['name'],
                    'buyer_tax_no' => $client['nip'],
                    'price_net' => number_format($totalNet, 2, '.', ''),
                    'price_gross' => number_format($totalNet * 1.23, 2, '.', ''),
                    'products_margin' => number_format($totalNet - $totalCost, 2, '.', ''),
                    'status' => mt_rand(0, 10) > 2 ? 'paid' : 'sent',
                    'department_id' => $dept['id'],
                    'department_name' => $dept['name'],
                ];
            }
            $current->addDay();
        }

        mt_srand();
        return $invoices;
    }

    private function safeCachePut(string $key, mixed $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Exception $e) {
            Log::warning("Cache write failed for key {$key}: " . $e->getMessage());
        }
    }

    private function getMockInvoiceDetail(int $invoiceId): ?array
    {
        $mockIds = [1001, 1002, 1003];
        if (!in_array($invoiceId, $mockIds, true)) {
            return null;
        }
        $positions = [
            ['name' => 'Filet z kurczaka 2kg', 'quantity' => '10', 'price_net' => '25.50', 'total_price_net' => '255.00'],
            ['name' => 'Udko kurczaka 1kg', 'quantity' => '20', 'price_net' => '18.00', 'total_price_net' => '360.00'],
            ['name' => 'Skrzydełka 3kg', 'quantity' => '5', 'price_net' => '32.00', 'total_price_net' => '160.00'],
        ];
        return [
            'id' => $invoiceId,
            'number' => 'FV/2026/01/' . str_pad((string) array_search($invoiceId, $mockIds) + 1, 3, '0', STR_PAD_LEFT),
            'positions' => $positions,
        ];
    }

    private function getMockInvoices(string $nip): array
    {
        return [
            [
                'id' => 1001,
                'number' => 'FV/2026/01/001',
                'issue_date' => now()->subDays(30)->format('Y-m-d'),
                'payment_to' => now()->subDays(16)->format('Y-m-d'),
                'price_net' => 1500.00,
                'price_gross' => 1845.00,
                'status' => 'paid',
                'paid_date' => now()->subDays(20)->format('Y-m-d'),
                'buyer_name' => 'Klient ' . substr($nip, 0, 3),
                'buyer_tax_no' => $nip,
            ],
            [
                'id' => 1002,
                'number' => 'FV/2026/01/015',
                'issue_date' => now()->subDays(15)->format('Y-m-d'),
                'payment_to' => now()->subDays(1)->format('Y-m-d'),
                'price_net' => 2800.00,
                'price_gross' => 3444.00,
                'status' => 'sent',
                'paid_date' => null,
                'buyer_name' => 'Klient ' . substr($nip, 0, 3),
                'buyer_tax_no' => $nip,
            ],
            [
                'id' => 1003,
                'number' => 'FV/2026/02/003',
                'issue_date' => now()->subDays(5)->format('Y-m-d'),
                'payment_to' => now()->addDays(9)->format('Y-m-d'),
                'price_net' => 980.00,
                'price_gross' => 1205.40,
                'status' => 'sent',
                'paid_date' => null,
                'buyer_name' => 'Klient ' . substr($nip, 0, 3),
                'buyer_tax_no' => $nip,
            ],
        ];
    }
}
