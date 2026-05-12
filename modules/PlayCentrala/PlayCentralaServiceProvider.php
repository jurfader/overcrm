<?php

namespace Modules\PlayCentrala;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class PlayCentralaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Services\RingostatService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\SyncRingostatCalls::class,
                Commands\MatchVisits::class,
            ]);
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            // Synchronizacja połączeń co godzinę (fallback jeśli webhook nie działa)
            $schedule->command('ringostat:sync-calls --hours=2')->hourly();
        });
    }
}
