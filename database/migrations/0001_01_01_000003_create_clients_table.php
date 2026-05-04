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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['company', 'person'])->default('company'); // Firma lub osoba
            $table->string('name'); // Nazwa firmy lub imię i nazwisko
            $table->string('short_name')->nullable(); // Skrócona nazwa
            $table->string('nip', 15)->nullable(); // NIP
            $table->string('regon', 14)->nullable(); // REGON
            
            // Dane kontaktowe
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone2', 20)->nullable(); // Dodatkowy telefon
            $table->string('website')->nullable();
            
            // Adres
            $table->string('street')->nullable();
            $table->string('building_number', 10)->nullable();
            $table->string('apartment_number', 10)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Polska');
            
            // Osoba kontaktowa
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            
            // Dodatkowe
            $table->enum('status', ['active', 'inactive', 'potential'])->default('active');
            $table->text('notes')->nullable();
            $table->date('birthday')->nullable(); // Dla osób prywatnych
            
            // Powiązania
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indeksy
            $table->index('name');
            $table->index('nip');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
