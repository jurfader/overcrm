<?php

namespace App\Providers;

use App\Contracts\InvoiceProvider;
use App\Contracts\OrderProvider;
use App\Contracts\ProductProvider;
use App\Support\Providers\LocalOrderProvider;
use App\Support\Providers\LocalProductProvider;
use App\Support\Providers\NullInvoiceProvider;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Zarządza pluggable providerami (Iteracja 3 — provider pattern).
 *
 * Rejestruje:
 *   - ProviderRegistry singleton
 *   - Default Local providers per kategoria (product, order, invoice)
 *   - Container bindings dla interfejsów: app(ProductProvider::class) zwraca aktywnego
 *
 * Moduły dorzucają swoje providers w boot() własnego ServiceProvider:
 *   $registry = app(ProviderRegistry::class);
 *   $registry->register('product', 'apilo', ApiloProductProvider::class);
 *   $registry->register('order',   'apilo', ApiloOrderProvider::class);
 */
class ProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
    }

    public function boot(ProviderRegistry $registry): void
    {
        // Rejestracja core providers
        $registry->register('product', 'local', LocalProductProvider::class);
        $registry->register('order',   'local', LocalOrderProvider::class);
        $registry->register('invoice', 'none',  NullInvoiceProvider::class);

        // Container bindings — kontroller robi `app(ProductProvider::class)` i dostaje aktywnego.
        // Closure żeby resolve odbywał się PRZY KAŻDYM zapytaniu (nie cached) — pozwala
        // adminowi przełączyć providera bez restartu.
        $this->app->bind(ProductProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->active('product'));
        $this->app->bind(OrderProvider::class,   fn ($app) => $app->make(ProviderRegistry::class)->active('order'));
        $this->app->bind(InvoiceProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->active('invoice'));
    }
}
