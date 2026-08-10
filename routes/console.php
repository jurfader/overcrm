<?php

use App\Services\LicenseService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

// UWAGA: nie harmonogramuj tu komend modułów. Moduł może być wyłączony albo
// odinstalowany, a wtedy wpis wskazuje na nieistniejącą komendę i cron cicho
// wykłada się co kilkanaście minut. Każdy moduł rejestruje swój harmonogram
// we własnym ServiceProviderze — patrz PlayCentralaServiceProvider::boot().
//
// (Stał tu wpis 'ringostat:sync-analyze' wołający komendę, której nigdy nie było
// w tym repo — synchronizacja połączeń Play i tak jedzie z modułu.)

// Przypomnienia o zadaniach — co 5 minut. Komenda jest idempotentna
// (`reminder_sent_at`), więc nakładające się przebiegi nic nie psują.
Schedule::command('tasks:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// License: walidacja co 24h (3:00 w nocy). Middleware ma własny lazy-refresh
// jako fallback gdy cron nie działa.
Schedule::call(fn () => app(LicenseService::class)->validate())
    ->dailyAt('03:00')
    ->name('license-validate')
    ->withoutOverlapping();

// Demo mode: kasuj wygasle pliki sesji co godzine (tylko gdy demo enabled)
if (config('demo.enabled')) {
    Schedule::command('demo:cleanup')
        ->hourly()
        ->withoutOverlapping()
        ->runInBackground();
}
