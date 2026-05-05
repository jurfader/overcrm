<?php

namespace App\Support\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Order;

/**
 * Default invoice provider — wyłączony. Klient generuje tylko PDF zamówienia
 * z LocalOrderProvider, faktura nie wystawiana automatycznie.
 *
 * Zastąpione przez FakturowniaInvoiceProvider gdy aktywny jest moduł Fakturownia.
 */
class NullInvoiceProvider implements InvoiceProvider
{
    public function key(): string { return 'none'; }
    public function label(): string { return 'Brak (tylko PDF zamówienia)'; }
    public function isAvailable(): bool { return true; }

    public function createFromOrder(Order $order): array
    {
        throw new \RuntimeException(
            'Wystawianie faktur jest wyłączone. Włącz moduł Fakturownia (lub iFirma) ' .
            'i wybierz go w Ustawienia → Integracje → Faktury.'
        );
    }
}
