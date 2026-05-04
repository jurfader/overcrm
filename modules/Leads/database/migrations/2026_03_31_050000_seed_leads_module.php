<?php

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Module::firstOrCreate(
            ['name' => 'leads'],
            [
                'display_name' => 'Leady',
                'description' => 'Zarządzanie leadami sprzedażowymi — tablica Kanban, automatyczne statusy, konwersja do klientów',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'leads',
                'is_active' => true,
                'is_core' => false,
                'order' => 6,
            ]
        );
    }

    public function down(): void
    {
        Module::where('name', 'leads')->delete();
    }
};
