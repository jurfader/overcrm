<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('gba_save_states');
        Schema::dropIfExists('war_rooms');
        Schema::dropIfExists('battleship_rooms');
        Schema::dropIfExists('game_rooms');
        Schema::dropIfExists('game_settings');
        Schema::dropIfExists('game_scores');

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('name', 'test')->delete();
        }
    }

    public function down(): void
    {
        // CUT — nie przywracamy gier ani modułu Test
    }
};
