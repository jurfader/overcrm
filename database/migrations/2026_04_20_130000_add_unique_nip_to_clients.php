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
        // Jeśli kolumna już istnieje z poprzedniej próby — skip
        if (Schema::hasColumn('clients', 'nip_normalized')) {
            return;
        }

        DB::statement("
            ALTER TABLE clients
            ADD COLUMN nip_normalized VARCHAR(20) GENERATED ALWAYS AS (
                CASE
                    WHEN deleted_at IS NOT NULL THEN NULL
                    WHEN nip IS NULL OR TRIM(nip) = '' THEN NULL
                    ELSE REPLACE(REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', ''), '\t', '')
                END
            ) STORED
        ");

        DB::statement("
            ALTER TABLE clients
            ADD UNIQUE INDEX clients_nip_normalized_unique (nip_normalized)
        ");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('clients', 'nip_normalized')) {
            return;
        }

        DB::statement("ALTER TABLE clients DROP INDEX clients_nip_normalized_unique");
        DB::statement("ALTER TABLE clients DROP COLUMN nip_normalized");
    }
};
