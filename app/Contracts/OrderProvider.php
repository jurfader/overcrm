<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * Abstrakcja zaplecza zamówień. Local provider ('local') zapisuje do tabeli
 * `orders` + generuje PDF z DomPDF. Moduły mogą zastąpić: Apilo wysyła zamówienia
 * do Apilo orders API, BaseLinker do BaseLinker etc.
 *
 * Wybór providera w Settings → Integracje → "Zamówienia: [Lokalny / Apilo / …]".
 */
interface OrderProvider
{
    public function key(): string;
    public function label(): string;

    /**
     * Utwórz zamówienie. $data zawiera: client_id, order_date, delivery_date,
     * status, notes, items[] (każda pozycja: product_id?, name, sku?, unit,
     * quantity, price_net, vat_rate).
     *
     * Zwraca array reprezentujący utworzone zamówienie:
     * [
     *   'id' => string|int,           // ID w systemie providera (lokalna PK lub Apilo ID)
     *   'number' => string,           // Numer dokumentu (ZAM/2026/05/001 lub Apilo orderId)
     *   'pdf_url' => string|null,     // URL do PDF (jeśli provider obsługuje)
     *   'external_url' => string|null,// URL do panelu providera (np. Apilo dashboard)
     *   ...                           // pozostałe pola w razie potrzeby
     * ]
     */
    public function create(array $data): array;

    /**
     * Pojedyncze zamówienie po ID providera.
     */
    public function find(string|int $id): ?array;

    /**
     * Lista zamówień klienta (50 ostatnich, sortowane od najnowszych).
     */
    public function listForClient(int $clientId, int $limit = 50): Collection;

    /**
     * Czy provider potrafi zwrócić PDF dokumentu zamówienia?
     * Local = true (Blade + DomPDF). Apilo = depends (Apilo ma własne PDF).
     */
    public function supportsPdf(): bool;

    /**
     * Strumieniuje PDF (Response inline). Wywoływane tylko gdy supportsPdf() === true.
     */
    public function pdf(string|int $id): \Symfony\Component\HttpFoundation\Response;
}
