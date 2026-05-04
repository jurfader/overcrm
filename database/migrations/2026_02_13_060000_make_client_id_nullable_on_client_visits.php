<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * client_id musi być nullable, bo ~77.6% wpisów ze starego planera
     * nie ma przypisanego klienta (id_client=0).
     * Bez tej zmiany migracja danych straciłaby większość wpisów.
     */
    public function up(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            // Najpierw usuń istniejący klucz obcy
            $table->dropForeign(['client_id']);

            // Zmień kolumnę na nullable
            $table->unsignedBigInteger('client_id')->nullable()->change();

            // Dodaj klucz obcy ponownie z nullable i set null
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('client_visits', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->onDelete('cascade');
        });
    }
};
