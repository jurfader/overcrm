<?php

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Module::firstOrCreate(
            ['name' => 'inpost'],
            [
                'display_name' => 'InPost Geowidget',
                'description' => 'Mapa wyboru paczkomatu InPost – przycisk „Wybierz na mapie” przy dostawie InPost',
                'version' => '1.0.0',
                'author' => 'OVERMEDIA',
                'icon' => 'map-pin',
                'is_active' => true,
                'is_core' => false,
                'order' => 15,
            ]
        );
    }

    public function down(): void
    {
        Module::where('name', 'inpost')->delete();
    }
};
