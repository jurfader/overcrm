<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Abstrakcja wystawiania faktur z zamówień. Default = NullInvoiceProvider
 * (faktury wyłączone, tylko PDF zamówienia z Iteracji 2).
 *
 * Moduły: Fakturownia (POST do Fakturownia API), iFirma, własny generator faktur.
 */
interface InvoiceProvider
{
    public function key(): string;
    public function label(): string;

    /**
     * Czy provider jest aktywny i skonfigurowany? Null/disabled = false.
     */
    public function isAvailable(): bool;

    /**
     * Wystaw fakturę z zamówienia. Zwraca:
     * [
     *   'id'        => string|int,
     *   'number'    => string,            // np. "FV/2026/05/001"
     *   'pdf_url'   => string|null,
     *   'external_url' => string|null,    // panel Fakturownia / iFirma
     * ]
     *
     * Może rzucić wyjątkiem jeśli nie skonfigurowany — caller powinien obsłużyć.
     */
    public function createFromOrder(Order $order): array;
}
