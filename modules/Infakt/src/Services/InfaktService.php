<?php

namespace Modules\Infakt\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient inFakt.pl API v3.
 *
 * Dokumentacja: https://api.infakt.pl/api/v3
 * Auth: header X-inFakt-ApiKey (klucz z ustawien konta WWW).
 * Kwoty: integery w groszach.
 *
 * Sandbox: https://api.sandbox-infakt.pl/api/v3 (Setting 'infakt_sandbox' = true).
 *
 * Limity: 300 GET/60s, 150 POST/PUT/DELETE/60s per IP.
 * Tworzenie faktur jest asynchroniczne — POST zwraca invoice_task_reference_number,
 * status sprawdzamy przez /async/invoices/status/{ref}.json (kod 201 = sukces +
 * uuid faktury w odpowiedzi).
 */
class InfaktService
{
    protected string $apiKey;
    protected string $apiBase;

    public function __construct()
    {
        $this->apiKey = (string) Setting::get('infakt_api_key', '', 'core');
        $sandbox = (bool) Setting::get('infakt_sandbox', false, 'core');
        $this->apiBase = $sandbox
            ? 'https://api.sandbox-infakt.pl/api/v3'
            : 'https://api.infakt.pl/api/v3';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function isSandbox(): bool
    {
        return str_contains($this->apiBase, 'sandbox');
    }

    // ===================================================================
    // Test connection
    // ===================================================================

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Brak klucza API'];
        }

        try {
            $response = $this->client()->get("{$this->apiBase}/account/details.json");

            if ($response->successful()) {
                $data = $response->json();
                $accountName = $data['account_data']['company_name']
                    ?? $data['account_data']['full_name']
                    ?? null;
                return [
                    'success' => true,
                    'message' => 'Polaczenie OK',
                    'account' => ['name' => $accountName],
                    'sandbox' => $this->isSandbox(),
                ];
            }

            return [
                'success' => false,
                'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================================
    // Invoices (VAT — wersja MVP)
    // ===================================================================

    /**
     * Listuje faktury VAT. Filtry: client_tax_code, number, paid_date_null, etc.
     */
    public function listInvoices(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];

        try {
            $query = ['limit' => $limit, 'offset' => $offset];
            foreach ($filters as $key => $value) {
                $query["q[{$key}]"] = $value;
            }

            $response = $this->client()->get("{$this->apiBase}/invoices.json", $query);

            if ($response->successful()) {
                return $response->json()['entities'] ?? [];
            }
            Log::warning('InFakt listInvoices error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return [];
        } catch (\Throwable $e) {
            Log::warning('InFakt listInvoices exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getInvoice(string $uuid): ?array
    {
        if (!$this->isConfigured()) return null;
        try {
            $response = $this->client()->get("{$this->apiBase}/invoices/{$uuid}.json");
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('InFakt getInvoice exception', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getInvoicesForClientByNip(string $nip): array
    {
        $cleanNip = preg_replace('/[^0-9]/', '', $nip);
        if (strlen($cleanNip) < 10) return [];
        return $this->listInvoices(['client_tax_code_eq' => $cleanNip], 100);
    }

    /**
     * Tworzy fakture asynchronicznie. Zwraca:
     *  - na sukces: ['success' => true, 'task_ref' => '...', 'invoice' => array|null]
     *    (gdy poll=true czekamy max 10s na nadanie uuid faktury)
     *  - na blad: ['success' => false, 'message' => ...]
     */
    public function createInvoice(array $payload, bool $sendToKsef = false, bool $poll = true): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }

        try {
            $body = ['invoice' => $payload];
            if ($sendToKsef) $body['send_to_ksef'] = true;

            $response = $this->client()->post("{$this->apiBase}/async/invoices.json", $body);

            if (!$response->successful()) {
                Log::warning('InFakt createInvoice failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
                return [
                    'success' => false,
                    'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 300),
                ];
            }

            $taskRef = $response->json()['invoice_task_reference_number'] ?? null;
            if (!$taskRef) {
                return ['success' => false, 'message' => 'Brak invoice_task_reference_number'];
            }

            if (!$poll) {
                return ['success' => true, 'task_ref' => $taskRef, 'invoice' => null];
            }

            // Poll status (max ~10s, co 1s)
            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                $status = $this->checkInvoiceStatus($taskRef);
                $code = $status['processing_code'] ?? null;
                if ($code === 201) {
                    return [
                        'success' => true,
                        'task_ref' => $taskRef,
                        'invoice'  => $status['invoice'] ?? null,
                    ];
                }
                if ($code === 422) {
                    return [
                        'success' => false,
                        'task_ref' => $taskRef,
                        'message' => $status['processing_description'] ?? 'Nie udalo sie stworzyc faktury',
                    ];
                }
            }

            return ['success' => true, 'task_ref' => $taskRef, 'invoice' => null, 'pending' => true];
        } catch (\Throwable $e) {
            Log::warning('InFakt createInvoice exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sprawdza status async tworzenia faktury (kod 100/120/140 = w trakcie, 201 = sukces, 422 = blad).
     */
    public function checkInvoiceStatus(string $taskRef): array
    {
        try {
            $response = $this->client()->get("{$this->apiBase}/async/invoices/status/{$taskRef}.json");
            return $response->json() ?: [];
        } catch (\Throwable $e) {
            Log::warning('InFakt checkInvoiceStatus exception', ['ref' => $taskRef, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Pobiera PDF faktury jako string (binary).
     * document_type: original_copy | original | copy | duplicate | regular | double_regular
     */
    public function getInvoicePdf(string $uuid, string $documentType = 'original', string $locale = 'pl'): ?string
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = $this->client()->get("{$this->apiBase}/invoices/{$uuid}/pdf.json", [
                'document_type' => $documentType,
                'locale'        => $locale,
            ]);
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning('InFakt getInvoicePdf exception', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Oznacza fakture jako zaplacona (async).
     */
    public function markInvoicePaid(string $uuid, ?string $paidDate = null, bool $allowCorrection = false): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }

        try {
            $body = [];
            if ($paidDate) $body['paid_date'] = $paidDate;
            if ($allowCorrection) $body['allow_correction'] = true;

            $response = $this->client()->post("{$this->apiBase}/async/invoices/{$uuid}/paid.json", $body);

            if ($response->successful()) {
                return ['success' => true, 'task_ref' => $response->json()['invoice_task_reference_number'] ?? null];
            }
            return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('InFakt markInvoicePaid exception', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Wysyla fakture mailem do klienta.
     */
    public function sendInvoiceEmail(string $uuid, ?string $recipient = null, string $printType = 'original', string $locale = 'pl'): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }

        try {
            $body = ['print_type' => $printType, 'locale' => $locale];
            if ($recipient) $body['recipient'] = $recipient;

            $response = $this->client()->post("{$this->apiBase}/invoices/{$uuid}/deliver_via_email.json", $body);

            if ($response->successful()) {
                return ['success' => true];
            }
            return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('InFakt sendInvoiceEmail exception', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getNextInvoiceNumber(?string $date = null, string $kind = 'vat'): ?string
    {
        if (!$this->isConfigured()) return null;
        try {
            $query = ['kind' => $kind];
            if ($date) $query['date'] = $date;
            $response = $this->client()->get("{$this->apiBase}/invoices/next_number.json", $query);
            return $response->successful() ? ($response->json()['next_number'] ?? null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Statystyki platnosci klienta (zaleglosci) — uzywane przy blokadzie nowych zamowien.
     * Liczone z faktur VAT nieoplaconych po terminie.
     */
    public function getClientPaymentStats(string $nip): array
    {
        $invoices = $this->getInvoicesForClientByNip($nip);
        $overdue = 0;
        $totalUnpaid = 0;
        $today = now()->toDateString();

        foreach ($invoices as $inv) {
            $paid = !empty($inv['paid_date']);
            if ($paid) continue;
            $unpaidGross = (int) ($inv['gross_price'] ?? 0) - (int) ($inv['paid_price'] ?? 0);
            if ($unpaidGross <= 0) continue;
            $totalUnpaid += $unpaidGross;
            $dueDate = $inv['payment_date'] ?? null;
            if ($dueDate && $dueDate < $today) {
                $overdue += $unpaidGross;
            }
        }

        return [
            'overdue'      => $overdue,       // grosze przeterminowane
            'total_unpaid' => $totalUnpaid,
        ];
    }

    // ===================================================================
    // Clients
    // ===================================================================

    public function listClients(int $limit = 100, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/clients.json", compact('limit', 'offset'));
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('InFakt listClients exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function findClientByNip(string $nip): ?array
    {
        $cleanNip = preg_replace('/[^0-9]/', '', $nip);
        if (strlen($cleanNip) < 10 || !$this->isConfigured()) return null;
        try {
            $response = $this->client()->get("{$this->apiBase}/clients.json", [
                'q[clean_nip_eq]' => $cleanNip,
                'limit' => 1,
            ]);
            if ($response->successful()) {
                $entities = $response->json()['entities'] ?? [];
                return $entities[0] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('InFakt findClientByNip exception', ['nip' => $nip, 'error' => $e->getMessage()]);
        }
        return null;
    }

    public function createClient(array $clientData): ?array
    {
        if (!$this->isConfigured()) return null;
        try {
            $response = $this->client()->post("{$this->apiBase}/clients.json", ['client' => $clientData]);
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('InFakt createClient exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ===================================================================
    // Products
    // ===================================================================

    /**
     * Wszystkie produkty (z 5-min cache). Optional namePrefix do filtrowania.
     */
    public function getProducts(?string $namePrefix = null, bool $forceRefresh = false): array
    {
        if (!$this->isConfigured()) return [];

        $cacheKey = 'infakt.products.' . md5($this->apiKey . '|' . ($namePrefix ?? ''));
        if (!$forceRefresh && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $all = [];
        $offset = 0;
        $limit = 100;

        try {
            for ($i = 0; $i < 20; $i++) { // max 2000 produktow
                $query = compact('limit', 'offset');
                if ($namePrefix) $query['q[name_cont]'] = $namePrefix;
                $response = $this->client()->get("{$this->apiBase}/products.json", $query);

                if (!$response->successful()) break;

                $entities = $response->json()['entities'] ?? [];
                if (empty($entities)) break;

                $all = array_merge($all, $entities);
                if (count($entities) < $limit) break;
                $offset += $limit;
            }

            Cache::put($cacheKey, $all, 300);
            return $all;
        } catch (\Throwable $e) {
            Log::warning('InFakt getProducts exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ===================================================================
    // Costs (faktury kosztowe)
    // ===================================================================

    public function listCosts(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $query = ['limit' => $limit, 'offset' => $offset, 'order' => 'created_at desc'];
            foreach ($filters as $key => $value) {
                $query["q[{$key}]"] = $value;
            }
            $response = $this->client()->get("{$this->apiBase}/documents/costs.json", $query);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('InFakt listCosts exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function uploadCostFile(string $filePath, string $filename): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }
        try {
            $response = Http::withHeaders(['X-inFakt-ApiKey' => $this->apiKey])
                ->timeout(30)
                ->attach('uploads[]', file_get_contents($filePath), $filename)
                ->post("{$this->apiBase}/documents/costs/upload.json");

            if ($response->successful()) {
                return ['success' => true, 'response' => $response->json()];
            }
            return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('InFakt uploadCostFile exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function markCostPaid(array $uuids, ?string $paidDate = null, bool $allowCorrection = false): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }
        try {
            $body = [];
            if ($paidDate) $body['paid_on'] = $paidDate;
            $response = $this->client()
                ->put("{$this->apiBase}/documents/costs/paid_many.json?uuids=" . implode(',', $uuids)
                    . ($allowCorrection ? '&allow_correction=true' : ''), $body);
            return $response->successful()
                ? ['success' => true, 'response' => $response->json()]
                : ['success' => false, 'message' => 'HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================================================================
    // KSeF (status integracji)
    // ===================================================================

    public function getKsefStatus(): array
    {
        if (!$this->isConfigured()) return ['active' => false];
        try {
            $response = $this->client()->get("{$this->apiBase}/ksef/integration.json");
            return $response->successful() ? $response->json() : ['active' => false];
        } catch (\Throwable $e) {
            return ['active' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendInvoiceToKsef(string $uuid): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }
        try {
            $response = $this->client()->post("{$this->apiBase}/ksef/documents/{$uuid}/send.json");
            return $response->successful()
                ? ['success' => true, 'response' => $response->json()]
                : ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getKsefStatusForInvoice(string $uuid): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/ksef/documents/{$uuid}/status.json");
            return $response->successful() ? $response->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ===================================================================
    // Slowniki (cache 24h — stawki VAT, kraje, kody GTU)
    // ===================================================================

    public function getVatRates(): array
    {
        return Cache::remember('infakt.vat_rates', 86400, function () {
            try {
                $response = $this->client()->get("{$this->apiBase}/vat_rates.json");
                return $response->successful() ? ($response->json()['entities'] ?? []) : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public function getGtus(): array
    {
        return Cache::remember('infakt.gtus', 86400, function () {
            try {
                $response = $this->client()->get("{$this->apiBase}/gtus.json");
                return $response->successful() ? ($response->json()['entities'] ?? []) : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    // ===================================================================
    // HTTP client (DRY)
    // ===================================================================

    protected function client(): PendingRequest
    {
        return Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-inFakt-ApiKey' => $this->apiKey]);
    }

    public static function normalizeNip(?string $nip): string
    {
        return preg_replace('/[^0-9]/', '', (string) $nip);
    }
}
