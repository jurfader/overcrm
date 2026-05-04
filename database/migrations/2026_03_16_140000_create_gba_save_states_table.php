<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gba_save_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rom_key', 255)->index();
            $table->binary('save_data');
            $table->binary('screenshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'rom_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gba_save_states');
    }
};
