<?php

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
