<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * module.json v2 — moduł opisuje, co WNOSI i czego POTRZEBUJE w kategoriach
 * zdolności, a nie nazw innych modułów.
 *
 * Istniejąca kolumna `dependencies` zostaje: trzyma twarde zależności po nazwie
 * modułu i nadal działa. Nowe `requires` jest ogólniejsze — pozycja
 * "capability:telephony" jest spełniona przez DOWOLNY moduł telefonii, więc
 * moduł analizy rozmów nie musi wiedzieć, czy klient ma Play Centralę czy 3CX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            // Kategoria w marketplace (telephony, invoice, order, tasks…) — steruje
            // grupowaniem listy modułów i nagłówkami w UI.
            $table->string('category')->nullable()->after('icon');

            // Producent integracji (Play, Ringostat, Comarch…). Null dla modułów
            // funkcjonalnych, które nie integrują się z niczym zewnętrznym.
            $table->string('vendor')->nullable()->after('category');

            // Zdolności, które moduł REJESTRUJE w ProviderRegistry, np. ["telephony"].
            $table->json('provides')->nullable()->after('dependencies');

            // Czego moduł potrzebuje: "capability:telephony" albo "module:leads".
            $table->json('requires')->nullable()->after('provides');

            // Z czym moduł nie może działać równolegle. Zwykle własna kategoria —
            // dwie centrale telefoniczne naraz to gwarantowany bałagan w danych.
            $table->json('conflicts')->nullable()->after('requires');

            // Pakiet licencyjny, do którego moduł należy (overcrm-ai, overcrm-telefonia…).
            $table->string('bundle')->nullable()->after('conflicts');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['category', 'vendor', 'provides', 'requires', 'conflicts', 'bundle']);
        });
    }
};
