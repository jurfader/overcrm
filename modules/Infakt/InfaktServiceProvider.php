<?php

namespace Modules\Infakt;

use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Infakt\Providers\InfaktInvoiceProvider;
use Modules\Infakt\Providers\InfaktProductProvider;
use Modules\Infakt\Services\InfaktService;

/**
 * Boot rejestruje 2 providery + 1 dashboard widget. Admin moze wybrac 'infakt'
 * w Settings -> Integracje (Magazyn produktow / Faktury). Konflikt z Fakturownia
 * — uzytkownik aktywuje jednego naraz.
 */
class InfaktServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(ProviderRegistry $registry, WidgetRegistry $widgets): void
    {
        $registry->register('invoice', 'infakt', InfaktInvoiceProvider::class);
        $registry->register('product', 'infakt', InfaktProductProvider::class);

        $widgets->register(new Widget(
            key: 'infakt.tax_deadlines',
            title: 'Terminy płatności (inFakt)',
            icon: 'calendar',
            component: 'InfaktTaxDeadlines',
            defaultWidth: 4,
            minWidth: 3,
            roles: ['admin'],
            handler: fn ($user) => ['deadlines' => app(InfaktService::class)->getUpcomingTaxDeadlines()],
            description: 'Nadchodzące terminy płatności podatkowych: ZUS, PIT, JPK V7. Cache 1h.',
            module: 'infakt',
        ));
    }
}
