<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

/**
 * Aktywacja licencji z linii poleceń (używane przez OVERPANEL installer
 * gdy admin podaje klucz w wizardzie instalacji).
 *
 * Exit code: 0 = sukces, 1 = błąd (nieprawidłowy klucz / network).
 */
class ActivateLicenseCommand extends Command
{
    protected $signature = 'license:activate {key : Klucz licencji w formacie XXXX-XXXX-XXXX-XXXX}';
    protected $description = 'Aktywuje licencję dla tej domeny przez OVERMEDIA license-server';

    public function handle(LicenseService $license): int
    {
        $key = $this->argument('key');
        $this->line("Aktywuję licencję dla domeny " . parse_url(config('app.url'), PHP_URL_HOST));

        $result = $license->activate($key);

        if ($result['success']) {
            $status = $license->status();
            $this->info('✓ ' . $result['message']);
            $this->line('  Plan: ' . ($status['plan'] ?? '—'));
            $this->line('  Wygasa: ' . ($status['expires_at'] ?? '—'));
            return self::SUCCESS;
        }

        $this->error('✗ ' . $result['message']);
        return self::FAILURE;
    }
}
