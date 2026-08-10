<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\InvoiceProvider;
use App\Contracts\OrderProvider;
use App\Contracts\ProductProvider;
use App\Contracts\StorageProvider;
use App\Contracts\TelephonyProvider;
use App\Support\Notifications\Notifier;
use App\Support\Providers\LocalOrderProvider;
use App\Support\Providers\LocalProductProvider;
use App\Support\Providers\LocalStorageProvider;
use App\Support\Providers\MailNotificationChannel;
use App\Support\Providers\NullInvoiceProvider;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Zarządza pluggable providerami (provider pattern).
 *
 * Rejestruje:
 *   - ProviderRegistry singleton
 *   - Providery rdzenia per kategoria (product, order, invoice, storage, notification)
 *   - Container bindings dla interfejsów: app(ProductProvider::class) zwraca aktywnego
 *
 * Moduły dorzucają swoje providery w boot() własnego ServiceProvider:
 *   $registry = app(ProviderRegistry::class);
 *   $registry->register('product',   'apilo',        ApiloProductProvider::class);
 *   $registry->register('telephony', 'playcentrala', PlayTelephonyProvider::class);
 *
 * KTÓRE KATEGORIE MAJĄ PROVIDER RDZENIA
 * product / order / storage / notification — tak, więc zdolność istnieje zawsze.
 * invoice — provider „brak" (świadoma decyzja: fakturowanie bywa wyłączone).
 * telephony / ai / ai_audio — NIE. Bez modułu tych zdolności po prostu nie ma
 * i kod musi pytać `$registry->has('telephony')`, a nie zakładać obecność.
 */
class ProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(Notifier::class);
    }

    public function boot(ProviderRegistry $registry): void
    {
        // Providery rdzenia
        $registry->register('product',      'local', LocalProductProvider::class);
        $registry->register('order',        'local', LocalOrderProvider::class);
        $registry->register('invoice',      'none',  NullInvoiceProvider::class);
        $registry->register('storage',      'local', LocalStorageProvider::class);
        $registry->register('notification', 'mail',  MailNotificationChannel::class);

        // Container bindings — kontroler robi `app(ProductProvider::class)` i dostaje aktywnego.
        // Closure, żeby resolve odbywał się PRZY KAŻDYM zapytaniu (nie cached) — pozwala
        // adminowi przełączyć providera bez restartu.
        $this->app->bind(ProductProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->active('product'));
        $this->app->bind(OrderProvider::class,   fn ($app) => $app->make(ProviderRegistry::class)->active('order'));
        $this->app->bind(InvoiceProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->active('invoice'));
        $this->app->bind(StorageProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->active('storage'));

        // Kategorie opcjonalne: binding zwraca null zamiast rzucać, bo brak modułu
        // telefonii czy AI to normalny stan instalacji, a nie błąd konfiguracji.
        // Kod woła `app(TelephonyProvider::class)` i sprawdza null, albo pyta
        // rejestr przez has('telephony') — jedno i drugie jest bezpieczne.
        $this->app->bind(TelephonyProvider::class, fn ($app) => $app->make(ProviderRegistry::class)->activeOrNull('telephony'));
        $this->app->bind(AiProvider::class,        fn ($app) => $app->make(ProviderRegistry::class)->activeOrNull('ai'));
    }
}
