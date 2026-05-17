<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Kasuje pliki sesji demo starsze niz config('demo.ttl_hours').
 * Scheduler: routes/console.php (co godzine). Template (_template.sqlite)
 * jest pomijany.
 */
class DemoCleanup extends Command
{
    protected $signature = 'demo:cleanup {--dry : Tylko wypisz co bys skasowal}';
    protected $description = 'Kasuje wygasle pliki sesji demo (storage/app/demo/*.sqlite)';

    public function handle(): int
    {
        $dir = config('demo.path');
        if (!File::exists($dir)) {
            $this->info('Brak katalogu demo, nic do roboty.');
            return self::SUCCESS;
        }

        $ttlSeconds = config('demo.ttl_hours', 24) * 3600;
        $cutoff = time() - $ttlSeconds;
        $dry = (bool) $this->option('dry');

        $files = File::files($dir);
        $deleted = 0;
        $kept = 0;
        $freedBytes = 0;

        foreach ($files as $file) {
            $name = $file->getFilename();

            // Pomin template
            if ($name === '_template.sqlite') {
                continue;
            }

            // Akceptujemy tylko *.sqlite
            if ($file->getExtension() !== 'sqlite') {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime < $cutoff) {
                $size = $file->getSize();
                if ($dry) {
                    $this->line(" [DRY] Skasowal bym: {$name} (mtime " . date('Y-m-d H:i', $mtime) . ", " . round($size / 1024, 1) . " KB)");
                } else {
                    File::delete($file->getPathname());
                }
                $deleted++;
                $freedBytes += $size;
            } else {
                $kept++;
            }
        }

        $verb = $dry ? 'Do skasowania' : 'Skasowano';
        $this->info("{$verb}: {$deleted} plikow (" . round($freedBytes / 1024 / 1024, 2) . " MB)");
        $this->line("Aktywnych sesji: {$kept}");

        return self::SUCCESS;
    }
}
