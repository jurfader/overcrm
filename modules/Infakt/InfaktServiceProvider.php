<?php

namespace Modules\Infakt;

use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Infakt\Providers\InfaktInvoiceProvider;
use Modules\Infakt\Providers\InfaktProductProvider;

/**
 * Boot rejestruje 2 providery w ProviderRegistry — admin moze wybrac 'infakt'
 * w Settings -> Integracje (Magazyn produktow / Faktury). Konflikt z Fakturownia
 * — uzytkownik aktywuje jednego naraz.
 */
class InfaktServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(ProviderRegistry $registry): void
    {
        $registry->register('invoice', 'infakt', InfaktInvoiceProvider::class);
        $registry->register('product', 'infakt', InfaktProductProvider::class);
    }
}
