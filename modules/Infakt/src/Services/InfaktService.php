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
    // Corrective invoices (faktury korygujace)
    // ===================================================================

    public function listCorrectiveInvoices(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->listGeneric('corrective_invoices', $filters, $limit, $offset);
    }

    public function getCorrectiveInvoice(string $uuid): ?array
    {
        return $this->getGeneric('corrective_invoices', $uuid);
    }

    public function createCorrectiveInvoice(array $payload, bool $poll = true): array
    {
        return $this->createGeneric('corrective_invoices', 'corrective_invoice', $payload, $poll);
    }

    public function getCorrectiveInvoicePdf(string $uuid, string $documentType = 'original', string $locale = 'pl'): ?string
    {
        return $this->getPdfGeneric('corrective_invoices', $uuid, $documentType, $locale);
    }

    public function markCorrectiveInvoicePaid(string $uuid, ?string $paidDate = null): array
    {
        return $this->markPaidGeneric('corrective_invoices', $uuid, $paidDate);
    }

    // ===================================================================
    // Advance invoices (zaliczkowe)
    // ===================================================================

    public function listAdvanceInvoices(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->listGeneric('advance_invoices', $filters, $limit, $offset);
    }

    public function getAdvanceInvoice(string $uuid): ?array
    {
        return $this->getGeneric('advance_invoices', $uuid);
    }

    /**
     * Tworzy fakture zaliczkowa. Pierwsza w grupie — z services[]; kolejne (do tej samej
     * transakcji) — z previous_advance_id (bez services jezeli identyczne).
     */
    public function createAdvanceInvoice(array $payload, bool $poll = true): array
    {
        return $this->createGeneric('advance_invoices', 'advance_invoice', $payload, $poll);
    }

    public function getAdvanceInvoicePdf(string $uuid, string $documentType = 'original', string $locale = 'pl'): ?string
    {
        return $this->getPdfGeneric('advance_invoices', $uuid, $documentType, $locale);
    }

    public function markAdvanceInvoicePaid(string $uuid, ?string $paidDate = null): array
    {
        return $this->markPaidGeneric('advance_invoices', $uuid, $paidDate);
    }

    // ===================================================================
    // Final invoices (koncowe / rozliczeniowe — domyka serie zaliczek)
    // ===================================================================

    public function listFinalInvoices(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->listGeneric('final_invoices', $filters, $limit, $offset);
    }

    public function getFinalInvoice(string $uuid): ?array
    {
        return $this->getGeneric('final_invoices', $uuid);
    }

    /**
     * Tworzy fakture koncowa. Payload wymaga previous_advance_id (uuid zaliczki).
     */
    public function createFinalInvoice(array $payload, bool $poll = true): array
    {
        return $this->createGeneric('final_invoices', 'final_invoice', $payload, $poll);
    }

    public function getFinalInvoicePdf(string $uuid, string $documentType = 'original', string $locale = 'pl'): ?string
    {
        return $this->getPdfGeneric('final_invoices', $uuid, $documentType, $locale);
    }

    public function markFinalInvoicePaid(string $uuid, ?string $paidDate = null): array
    {
        return $this->markPaidGeneric('final_invoices', $uuid, $paidDate);
    }

    // ===================================================================
    // Accounting (read-only) — JPK V7, podatek dochodowy, VAT-UE, ZUS, KPiR
    // ===================================================================

    /** JPK V7 — pliki podatkowe per okres */
    public function listSafV7Files(int $limit = 12, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/saf_v7_files.json", [
                'limit' => $limit, 'offset' => $offset, 'order' => 'period desc',
            ]);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) { return []; }
    }

    /** Podatek dochodowy — zaliczki per okres */
    public function listIncomeTaxes(int $limit = 12, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/income_taxes.json", [
                'limit' => $limit, 'offset' => $offset, 'order' => 'period desc',
            ]);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) { return []; }
    }

    /** Podatek VAT-UE — informacje podsumowujace */
    public function listVatEuTaxes(int $limit = 12, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/vat_eu_taxes.json", [
                'limit' => $limit, 'offset' => $offset, 'order' => 'period desc',
            ]);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) { return []; }
    }

    /** Skladki ZUS — per okres */
    public function listInsuranceFees(int $limit = 12, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/insurance_fees.json", [
                'limit' => $limit, 'offset' => $offset, 'order' => 'payment_date desc',
            ]);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) { return []; }
    }

    /** Ksiega Przychodow i Rozchodow (KPiR) — sumy per okres */
    public function listBooks(int $limit = 12, int $offset = 0): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $response = $this->client()->get("{$this->apiBase}/books.json", [
                'limit' => $limit, 'offset' => $offset, 'order' => 'period desc',
            ]);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Zwraca nadchodzace terminy platnosci podatkowych (cache 1h).
     * Used by Dashboard widget. Filtruje status='draft' (nieoplacone).
     */
    public function getUpcomingTaxDeadlines(): array
    {
        if (!$this->isConfigured()) return [];

        return Cache::remember('infakt.tax_deadlines.' . md5($this->apiKey), 3600, function () {
            $today = now()->toDateString();
            $deadlines = [];

            foreach ($this->listSafV7Files(6) as $item) {
                if (($item['status'] ?? null) !== 'paid' && !empty($item['payment_date']) && $item['payment_date'] >= $today) {
                    $deadlines[] = [
                        'kind'         => 'JPK V7',
                        'period'       => $item['period_name'] ?? null,
                        'payment_date' => $item['payment_date'],
                        'amount'       => (int) ($item['tax_to_pay_price'] ?? 0),
                    ];
                }
            }
            foreach ($this->listIncomeTaxes(6) as $item) {
                if (($item['status'] ?? null) !== 'paid' && !empty($item['payment_date']) && $item['payment_date'] >= $today) {
                    $deadlines[] = [
                        'kind'         => 'PIT (zaliczka)',
                        'period'       => $item['period_name'] ?? null,
                        'payment_date' => $item['payment_date'],
                        'amount'       => (int) ($item['period_proceeds_price'] ?? 0),
                    ];
                }
            }
            foreach ($this->listInsuranceFees(6) as $item) {
                if (!empty($item['payment_date']) && $item['payment_date'] >= $today) {
                    $unpaid = (int) ($item['sum_amount_price'] ?? 0)
                        - (int) ($item['social_amount_paid'] ?? 0)
                        - (int) ($item['health_amount_paid'] ?? 0)
                        - (int) ($item['work_amount_paid'] ?? 0);
                    if ($unpaid <= 0) continue;
                    $deadlines[] = [
                        'kind'         => 'ZUS',
                        'period'       => $item['period_name'] ?? null,
                        'payment_date' => $item['payment_date'],
                        'amount'       => $unpaid,
                    ];
                }
            }

            usort($deadlines, fn ($a, $b) => strcmp($a['payment_date'], $b['payment_date']));
            return $deadlines;
        });
    }

    // ===================================================================
    // Generic helpers (DRY dla typow faktur)
    // ===================================================================

    protected function listGeneric(string $resource, array $filters, int $limit, int $offset): array
    {
        if (!$this->isConfigured()) return [];
        try {
            $query = ['limit' => $limit, 'offset' => $offset];
            foreach ($filters as $key => $value) $query["q[{$key}]"] = $value;
            $response = $this->client()->get("{$this->apiBase}/{$resource}.json", $query);
            return $response->successful() ? ($response->json()['entities'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning("InFakt list {$resource} exception", ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function getGeneric(string $resource, string $uuid): ?array
    {
        if (!$this->isConfigured()) return null;
        try {
            $response = $this->client()->get("{$this->apiBase}/{$resource}/{$uuid}.json");
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Async create + poll status — wspolne dla VAT/corrective/advance/final.
     * $bodyKey to klucz w body (np. 'corrective_invoice', 'advance_invoice').
     */
    protected function createGeneric(string $resource, string $bodyKey, array $payload, bool $poll): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }
        try {
            $response = $this->client()->post("{$this->apiBase}/async/{$resource}.json", [$bodyKey => $payload]);
            if (!$response->successful()) {
                Log::warning("InFakt create {$resource} failed", ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 300)];
            }
            $taskRef = $response->json()['invoice_task_reference_number'] ?? null;
            if (!$taskRef) return ['success' => false, 'message' => 'Brak invoice_task_reference_number'];

            if (!$poll) {
                return ['success' => true, 'task_ref' => $taskRef, 'invoice' => null];
            }

            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                try {
                    $status = $this->client()->get("{$this->apiBase}/async/{$resource}/status/{$taskRef}.json")->json() ?: [];
                } catch (\Throwable $e) { $status = []; }
                $code = $status['processing_code'] ?? null;
                if ($code === 201) {
                    return ['success' => true, 'task_ref' => $taskRef, 'invoice' => $status['invoice'] ?? null];
                }
                if ($code === 422) {
                    return ['success' => false, 'task_ref' => $taskRef, 'message' => $status['processing_description'] ?? 'Nie udalo sie stworzyc'];
                }
            }
            return ['success' => true, 'task_ref' => $taskRef, 'invoice' => null, 'pending' => true];
        } catch (\Throwable $e) {
            Log::warning("InFakt create {$resource} exception", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function getPdfGeneric(string $resource, string $uuid, string $documentType, string $locale): ?string
    {
        if (!$this->isConfigured()) return null;
        try {
            $response = $this->client()->get("{$this->apiBase}/{$resource}/{$uuid}/pdf.json", [
                'document_type' => $documentType,
                'locale'        => $locale,
            ]);
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) { return null; }
    }

    protected function markPaidGeneric(string $resource, string $uuid, ?string $paidDate): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'inFakt nie skonfigurowany'];
        }
        try {
            $body = $paidDate ? ['paid_date' => $paidDate] : [];
            $response = $this->client()->post("{$this->apiBase}/{$resource}/{$uuid}/paid.json", $body);
            return $response->successful()
                ? ['success' => true]
                : ['success' => false, 'message' => 'HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
