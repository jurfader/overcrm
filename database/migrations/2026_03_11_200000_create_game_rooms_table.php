<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('waiting'); // waiting, playing, finished
            $table->json('board')->nullable();
            $table->string('current_turn', 1)->default('x'); // x, o
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['code', 'status']);
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rooms');
    }
};
