<?php

namespace Modules\Apilo;

use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Apilo\Providers\ApiloInvoiceProvider;
use Modules\Apilo\Providers\ApiloOrderProvider;
use Modules\Apilo\Providers\ApiloProductProvider;

/**
 * Boot rejestruje 3 providery w ProviderRegistry — admin może wybrać 'apilo'
 * w Settings → Integracje (Magazyn produktów / Zamówienia / Faktury).
 */
class ApiloServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(ProviderRegistry $registry): void
    {
        $registry->register('product', 'apilo', ApiloProductProvider::class);
        $registry->register('order',   'apilo', ApiloOrderProvider::class);
        $registry->register('invoice', 'apilo', ApiloInvoiceProvider::class);
    }
}
