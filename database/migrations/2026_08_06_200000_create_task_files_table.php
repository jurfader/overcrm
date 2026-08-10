<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Załączniki do zadań.
 *
 * Plik nie jest tu trzymany — trzyma go aktywny dostawca zdolności `storage`
 * (domyślnie dysk lokalny, po włączeniu modułu może to być Dysk Google).
 * Tabela przechowuje wyłącznie WSKAZANIE na plik plus metadane do wyświetlenia
 * listy bez odpytywania dostawcy przy każdym renderze.
 *
 * `provider` zapamiętuje, KTÓRY dostawca trzyma dany plik. Bez tego po zmianie
 * dostawcy stare załączniki wskazywałyby w próżnię, a system nie miałby jak
 * tego wykryć.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 40)->default('local');
            // Identyfikator u dostawcy: ścieżka względna (dysk lokalny)
            // albo identyfikator pliku (Dysk Google).
            $table->string('external_id', 1024);

            $table->string('name');
            $table->string('mime', 190)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // Link do podglądu u dostawcy, jeśli go udostępnia.
            $table->string('web_url', 1024)->nullable();

            $table->timestamps();
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_files');
    }
};
