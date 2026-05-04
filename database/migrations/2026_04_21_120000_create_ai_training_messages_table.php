<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Współdzielona historia czatu z AI Training — każdy admin widzi co pisali inni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_training_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20); // user | assistant
            $table->text('content');
            $table->json('meta')->nullable(); // np. {added: [...], removed: [...]}
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_messages');
    }
};
