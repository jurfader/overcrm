<?php

namespace Database\Seeders;

use App\Services\SetupService;
use Illuminate\Database\Seeder;

/**
 * Uprawnienia core. Lista mieszka w SetupService::PERMISSIONS — ten sam zestaw
 * zakłada kreator pierwszego uruchomienia na produkcji. Idempotentne.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(SetupService::class)->seedPermissions();
    }
}
