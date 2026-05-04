<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dodaje generowaną kolumnę `nip_normalized` + unique index.
 *
 * Po tej migracji MySQL fizycznie odmówi zapisu drugiego klienta z tym samym NIP
 * (niezależnie od formatu: "123-456-78-90", "1234567890", "123 456 78 90" itp.).
 *
 * Zasady kolumny:
 *   - jest NULL gdy nip jest puste / null / klient soft-deleted (duplikaty po merge nie blokują)
 *   - w przeciwnym razie to cyfry z nip
 *
 * Unique index na NULL w MySQL dopuszcza wiele wierszy z NULL — osoby prywatne bez NIP OK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'nip_normalized')) {
            return;
        }

        $driver = DB::getDriverName();
        $expr = "
            CASE
                WHEN deleted_at IS NOT NULL THEN NULL
                WHEN nip IS NULL OR TRIM(nip) = '' THEN NULL
                ELSE REPLACE(REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', ''), '\t', '')
            END
        ";

        if ($driver === 'sqlite') {
            // SQLite supports VIRTUAL generated columns (computed on read, no STORED).
            DB::statement("ALTER TABLE clients ADD COLUMN nip_normalized VARCHAR(20) GENERATED ALWAYS AS ({$expr}) VIRTUAL");
            DB::statement("CREATE UNIQUE INDEX clients_nip_normalized_unique ON clients (nip_normalized)");
            return;
        }

        DB::statement("ALTER TABLE clients ADD COLUMN nip_normalized VARCHAR(20) GENERATED ALWAYS AS ({$expr}) STORED");
        DB::statement("ALTER TABLE clients ADD UNIQUE INDEX clients_nip_normalized_unique (nip_normalized)");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('clients', 'nip_normalized')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS clients_nip_normalized_unique");
            DB::statement("ALTER TABLE clients DROP COLUMN nip_normalized");
            return;
        }

        DB::statement("ALTER TABLE clients DROP INDEX clients_nip_normalized_unique");
        DB::statement("ALTER TABLE clients DROP COLUMN nip_normalized");
    }
};
