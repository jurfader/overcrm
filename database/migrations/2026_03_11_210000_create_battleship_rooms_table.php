<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battleship_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('waiting');
            $table->json('player1_ships')->nullable();
            $table->json('player2_ships')->nullable();
            $table->json('player1_hits')->nullable();
            $table->json('player2_hits')->nullable();
            $table->string('current_turn', 10)->default('player1');
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battleship_rooms');
    }
};
