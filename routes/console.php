<?php

use App\Services\LicenseService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

// Ringostat: sync połączeń + auto-analiza AI co 15 minut
Schedule::command('ringostat:sync-analyze --hours=1 --min-duration=60 --limit=5')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// License: walidacja co 24h (3:00 w nocy). Middleware ma własny lazy-refresh
// jako fallback gdy cron nie działa.
Schedule::call(fn () => app(LicenseService::class)->validate())
    ->dailyAt('03:00')
    ->name('license-validate')
    ->withoutOverlapping();
