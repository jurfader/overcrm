<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Buduje czysty SQLite template dla demo mode.
 * Run-once przed wystawieniem demo. Reset: '--fresh' kasuje stary template.
 *
 *   php artisan demo:build-template --fresh
 *
 * Template trafia do config('demo.path').'/_template.sqlite'. Kazdy nowy
 * odwiedzajacy dostanie kopie tego pliku jako wlasna izolowana baze.
 */
class DemoBuildTemplate extends Command
{
    protected $signature = 'demo:build-template {--fresh : Skasuj istniejacy template i zbuduj od nowa}';
    protected $description = 'Buduje SQLite template dla demo mode (migracje + seedery)';

    public function handle(): int
    {
        $dir = config('demo.path');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $template = $dir . '/_template.sqlite';

        if (File::exists($template)) {
            if (!$this->option('fresh')) {
                $this->error("Template juz istnieje: {$template}");
                $this->line('Uruchom z --fresh zeby skasowac i zbudowac od nowa.');
                return self::FAILURE;
            }
            File::delete($template);
            $this->info('Stary template skasowany.');
        }

        // Pusty plik (sqlite tworzy schema przy pierwszej migracji)
        File::put($template, '');
        $this->info("Stworzono pusty plik: {$template}");

        // Przelacz sqlite connection na template path zeby migracje + seedery
        // trafily do template (a nie do default DB)
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $template,
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->info('Uruchamiam migracje...');
        Artisan::call('migrate', ['--force' => true, '--database' => 'sqlite'], $this->output);

        $this->info('Uruchamiam seedery...');
        Artisan::call('db:seed', ['--force' => true, '--database' => 'sqlite'], $this->output);

        $this->info('Template zbudowany. Kazdy odwiedzajacy demo dostanie kopie tego pliku.');
        $this->line("Sciezka: {$template}");
        $this->line("Rozmiar: " . round(File::size($template) / 1024, 1) . ' KB');

        return self::SUCCESS;
    }
}
