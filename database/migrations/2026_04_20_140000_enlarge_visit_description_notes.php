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
        // SQLite traktuje TEXT jako bezgranicznie długi — MODIFY COLUMN nie istnieje, więc no-op.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE client_visits MODIFY COLUMN description LONGTEXT NULL');
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN notes LONGTEXT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE client_visits MODIFY COLUMN description TEXT NULL');
        DB::statement('ALTER TABLE client_visits MODIFY COLUMN notes TEXT NULL');
    }
};
