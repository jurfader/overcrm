<?php

namespace App\Providers;

use App\Support\License;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->forceHttpsInProduction();
        $this->guardConsoleCommands();
    }

    protected function forceHttpsInProduction(): void
    {
        if ($this->app->environment('local')) {
            return;
        }
        URL::forceScheme('https');
        $appUrl = config('app.url', '');
        if ($appUrl) {
            URL::forceRootUrl(preg_replace('#^http:#', 'https:', $appUrl));
        }
    }

    /**
     * Etap 2a anti-piracy: console commands wymagają ważnej licencji.
     * Wyjątki: license:* (musi działać żeby aktywować), schedule:* (cron),
     * queue:* (worker), migrate / db / key:generate (instalacja),
     * package:discover / config|route|view:cache|clear (build).
     */
    protected function guardConsoleCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Demo deployment nie ma licencji w DB (per-session sqlite) i nie potrzebuje —
        // EnforceLicense middleware tez bypassuje. Wszystkie komendy CLI dozwolone.
        if (config('demo.enabled')) {
            return;
        }

        Event::listen(function (CommandStarting $event) {
            $cmd = $event->command ?? '';
            if ($this->commandIsAllowedWithoutLicense($cmd)) {
                return;
            }

            if (License::ok()) {
                return;
            }

            // Bez licencji — wypisz komunikat na stderr i przerwij.
            // Nie używamy abort() bo to web-only; rzucamy RuntimeException które
            // Symfony Console pokaże adminowi.
            fwrite(STDERR, "\033[31m✗ Wymagana ważna licencja, aby uruchomić tę komendę.\033[0m\n");
            fwrite(STDERR, "  Uruchom 'php artisan license:activate {klucz}' lub odwiedź /license w przeglądarce.\n");
            throw new \RuntimeException('License invalid — command blocked');
        });
    }

    protected function commandIsAllowedWithoutLicense(string $command): bool
    {
        $allowed = [
            'license:',          // license:activate
            'schedule:',         // schedule:run, schedule:work
            'queue:',            // queue:work, queue:listen
            'migrate',           // migrate, migrate:fresh, migrate:rollback, migrate:status
            'db:',               // db:seed, db:wipe
            'key:generate',
            'storage:link',
            'package:discover',
            'config:',           // config:cache, config:clear
            'route:',
            'view:',
            'optimize',
            'cache:',            // cache:clear, cache:forget
            'event:',
            'about',
            'list',
            'help',
            'inspire',
            'tinker',            // tinker — bez tego diagnostyka niemożliwa; ale pirat też może z niego korzystać
            'demo:',             // demo:build-template, demo:cleanup (setup demo deploymentu)
        ];
        foreach ($allowed as $prefix) {
            if ($command === rtrim($prefix, ':') || str_starts_with($command, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
