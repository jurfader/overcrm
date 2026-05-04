<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lista telefonów przypisanych do wizyty.
 * - `phones` JSON array oryginalnych formatów np. ["+48 500 123 456", "500999888"]
 * - `phones_normalized` TEXT — znormalizowane cyfry rozdzielone spacjami (np. " 500123456 500999888 ")
 *   używane przez match w Play Centrali (LIKE '% 500123456 %' jest wydajne i unika false match na fragmentach)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            if (!Schema::hasColumn('client_visits', 'phones')) {
                $table->json('phones')->nullable()->after('notes');
            }
        });

        // phones_normalized musi być VARCHAR (nie TEXT) — MySQL wymaga długości dla indexu na TEXT
        // Jeśli w poprzednim deploy było utworzone jako TEXT — zmień na VARCHAR.
        if (!Schema::hasColumn('client_visits', 'phones_normalized')) {
            DB::statement("ALTER TABLE client_visits ADD COLUMN phones_normalized VARCHAR(500) NULL AFTER phones");
        } else {
            // Upewnij się że typ to VARCHAR (poprzedni deploy mógł dać TEXT)
            DB::statement("ALTER TABLE client_visits MODIFY COLUMN phones_normalized VARCHAR(500) NULL");
        }

        $indexExists = DB::select(
            "SELECT COUNT(1) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE()
             AND table_name = 'client_visits'
             AND index_name = 'client_visits_phones_norm_idx'"
        );
        if (($indexExists[0]->cnt ?? 0) == 0) {
            Schema::table('client_visits', function (Blueprint $table) {
                $table->index(['phones_normalized'], 'client_visits_phones_norm_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->dropIndex('client_visits_phones_norm_idx');
            $table->dropColumn(['phones', 'phones_normalized']);
        });
    }
};
