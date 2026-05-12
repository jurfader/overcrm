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
 *
 * ApiloService zyje w modules/Apilo/src/Services. class_alias zapewnia kompatybilnosc
 * z legacy core'em (UserController, CalendarController) ktore still importuja
 * App\Services\ApiloService — to zostanie wyciete w Iteracji E (cleanup core).
 */
class ApiloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!class_exists(\App\Services\ApiloService::class, false)) {
            class_alias(
                \Modules\Apilo\Services\ApiloService::class,
                \App\Services\ApiloService::class
            );
        }
    }

    public function boot(ProviderRegistry $registry): void
    {
        $registry->register('product', 'apilo', ApiloProductProvider::class);
        $registry->register('order',   'apilo', ApiloOrderProvider::class);
        $registry->register('invoice', 'apilo', ApiloInvoiceProvider::class);
    }
}
