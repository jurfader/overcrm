<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Osobny adres dostawy na karcie klienta.
 *
 * W B2B adres rejestrowy firmy rzadko jest miejscem, do którego jedzie towar —
 * siedziba bywa w biurze, a dostawa idzie do magazynu albo lokalu. Dotąd CRM znał
 * wyłącznie adres rejestrowy, więc handlowiec przepisywał adres dostawy ręcznie
 * przy KAŻDYM zamówieniu. To nie tylko strata czasu: przepisywanie z pamięci jest
 * najczęstszym źródłem wysyłek pod zły adres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('delivery_name')->nullable()->after('country');
            $table->string('delivery_street')->nullable()->after('delivery_name');
            $table->string('delivery_building_number', 20)->nullable()->after('delivery_street');
            $table->string('delivery_apartment_number', 20)->nullable()->after('delivery_building_number');
            $table->string('delivery_postal_code', 10)->nullable()->after('delivery_apartment_number');
            $table->string('delivery_city')->nullable()->after('delivery_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_name',
                'delivery_street',
                'delivery_building_number',
                'delivery_apartment_number',
                'delivery_postal_code',
                'delivery_city',
            ]);
        });
    }
};
