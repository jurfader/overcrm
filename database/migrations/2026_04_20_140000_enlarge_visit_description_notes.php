<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zmienia kolumny description i notes w client_visits z TEXT (64KB) na LONGTEXT (4GB)
 * żeby pozwolić na bogaty HTML z wklejanymi obrazkami (base64).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN description LONGTEXT NULL');
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN notes LONGTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN description TEXT NULL');
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN notes TEXT NULL');
    }
};
