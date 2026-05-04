<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['module' => 'core', 'key' => 'apilo_warehouse_price_list_id'],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_warehouse_price_list_id',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo ID cennika magazynu (opcjonalnie)',
                'description' => 'Puste = pierwszy cennik z Apilo. Jeśli ceny w planerze nie zgadzają się z widokiem w Apilo, wpisz ID cennika z Administracja → Cenniki (GET /rest/api/warehouse/price/).',
                'order' => 9,
            ]
        );
    }

    public function down(): void
    {
        Setting::where('module', 'core')
            ->where('key', 'apilo_warehouse_price_list_id')
            ->delete();
    }
};
