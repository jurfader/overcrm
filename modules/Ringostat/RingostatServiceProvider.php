<?php

namespace Modules\Ringostat;

use Illuminate\Support\ServiceProvider;

/**
 * Skeleton modułu Ringostat. Implementacja API+webhooks w kolejnej iteracji.
 *
 * Po włączeniu: admin widzi 'Ringostat — konfiguracja' w sidebarze, wpisuje
 * Auth-key (z panelu Ringostat → Integracja → API/Webhooks) i URL endpoints.
 * Webhooks Ringostat.net trafiają do /ringostat/webhook/{event} i zapisują
 * call data do tabeli ringostat_calls_v2 (osobnej od play_centrala calls).
 */
class RingostatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Services\RingostatNetService::class);
    }

    public function boot(): void
    {
        // Migracje w database/migrations/ — auto-loadowane przez ModuleServiceProvider
    }
}
