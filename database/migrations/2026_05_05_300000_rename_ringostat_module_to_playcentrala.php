<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename module slug 'ringostat' → 'playcentrala' (Iteracja 6 — split na 2 osobne moduły).
 *
 * Aktualny moduł 'ringostat' faktycznie obsługuje Play Wirtualną Centralę — został
 * zmigrowany w 2026-04 (migracja migrate_ringostat_to_play). Slug zostawał legacy.
 * Teraz rename + nowy moduł 'ringostat' (skeleton) dla prawdziwej integracji
 * z ringostat.net API.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('modules')) return;

        DB::table('modules')
            ->where('name', 'ringostat')
            ->update([
                'name'         => 'playcentrala',
                'display_name' => 'Play Centrala',
                'updated_at'   => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('modules')) return;

        DB::table('modules')
            ->where('name', 'playcentrala')
            ->update([
                'name'         => 'ringostat',
                'display_name' => 'Play Centrala',
                'updated_at'   => now(),
            ]);
    }
};
