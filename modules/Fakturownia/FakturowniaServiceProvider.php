<?php

namespace Modules\Fakturownia;

use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Fakturownia\Providers\FakturowniaInvoiceProvider;
use Modules\Fakturownia\Providers\FakturowniaProductProvider;

class FakturowniaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!class_exists(\App\Services\FakturowniaService::class, false)) {
            class_alias(
                \Modules\Fakturownia\Services\FakturowniaService::class,
                \App\Services\FakturowniaService::class
            );
        }
    }

    public function boot(ProviderRegistry $registry): void
    {
        $registry->register('invoice', 'fakturownia', FakturowniaInvoiceProvider::class);
        $registry->register('product', 'fakturownia', FakturowniaProductProvider::class);
    }
}
