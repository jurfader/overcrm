<?php

/*
|--------------------------------------------------------------------------
| Demo mode
|--------------------------------------------------------------------------
| Gdy enabled, kazdy odwiedzajacy dostaje wlasna sqlite DB zasiana z
| _template.sqlite. Plik kasowany po ttl_hours. Cel: demo dla klientow
| gdzie kazdy widzi izolowane dane ktore znikna po 24h.
|
| Wdrazanie:
|   1) .env (na serwerze demo):
|        DEMO_MODE=true
|        DEMO_TTL_HOURS=24
|        SESSION_DRIVER=file     # NIE database — inaczej sesje wpadaja
|                                # do per-session sqlite i znikaja po reset
|        CACHE_STORE=file        # opcjonalne — z database cache jest
|                                # per-session co marnuje hit'y do license
|                                # server. file = wspolny dla wszystkich.
|        QUEUE_CONNECTION=sync   # demo nie ma workerow
|
|   2) php artisan demo:build-template            (raz, przy pierwszej instalacji)
|   3) Scheduler: php artisan schedule:work       (cleanup co godzine)
|
| Reset template po zmianach schemy:
|   php artisan demo:build-template --fresh
|
| Aktywne sesje:
|   ls -lh storage/app/demo/*.sqlite
*/

return [
    'enabled'    => env('DEMO_MODE', false),

    // Katalog na pliki sesji + template (sciezka absolutna; default storage/app/demo)
    'path'       => env('DEMO_PATH', storage_path('app/demo')),

    // Czas zycia pliku sesji w godzinach (po tym czasie cleanup kasuje plik)
    'ttl_hours'  => (int) env('DEMO_TTL_HOURS', 24),

    // Nazwa cookie z session ID
    'cookie'     => 'demo_session',

    // Auto-login — demo zakłada konto admin'a w template i loguje automatycznie
    'auto_login_email' => env('DEMO_AUTO_LOGIN_EMAIL', 'admin@example.com'),
];
