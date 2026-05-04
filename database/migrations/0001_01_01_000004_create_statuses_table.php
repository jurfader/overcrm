<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nazwa statusu
            $table->string('slug')->unique(); // Identyfikator (np. new, in_progress)
            $table->enum('type', ['new', 'in_progress', 'done', 'cancelled'])->default('new');
            $table->string('color', 7)->default('#3B82F6'); // Kolor HEX
            $table->string('bg_class')->nullable(); // Klasa CSS tła
            $table->integer('order')->default(0); // Kolejność sortowania
            $table->boolean('is_default')->default(false); // Czy domyślny dla nowych zadań
            $table->boolean('is_visible')->default(true); // Czy widoczny
            $table->boolean('is_final')->default(false); // Czy końcowy (zamyka zadanie)
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index('order');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
