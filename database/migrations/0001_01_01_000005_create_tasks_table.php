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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Tytuł/podsumowanie
            $table->text('description')->nullable(); // Opis zadania
            
            // Powiązania
            $table->foreignId('status_id')->constrained('statuses')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // Przypisany użytkownik
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // Kto utworzył
            
            // Daty
            $table->date('submit_date')->nullable(); // Data zgłoszenia
            $table->date('due_date')->nullable(); // Termin realizacji
            $table->datetime('completed_at')->nullable(); // Data zakończenia
            
            // Priorytet
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            
            // Dodatkowe
            $table->integer('estimated_hours')->nullable(); // Szacowany czas (godziny)
            $table->text('notes')->nullable(); // Notatki wewnętrzne
            
            $table->timestamps();
            $table->softDeletes(); // Kosz
            
            // Indeksy
            $table->index('due_date');
            $table->index('priority');
            $table->index(['status_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
