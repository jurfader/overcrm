<?php

namespace Database\Seeders;

use App\Services\SetupService;
use Illuminate\Database\Seeder;

/**
 * Statusy zadań — preset 'sales' z SetupService::STATUS_PRESETS (ten sam, który
 * kreator proponuje domyślnie na produkcji). Idempotentne: dokłada tylko
 * brakujące slugi, nie nadpisuje statusów edytowanych przez klienta.
 */
class StatusSeeder extends Seeder
{
    public function run(): void
    {
        app(SetupService::class)->seedStatuses('sales');
    }
}
