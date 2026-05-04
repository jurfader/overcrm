<?php

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Module::firstOrCreate(
            ['name' => 'kanban'],
            [
                'display_name' => 'Kanban',
                'description' => 'Widok tablicy Kanban dla zadań — przeciąganie zadań między statusami',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'kanban',
                'is_active' => true,
                'is_core' => false,
                'order' => 3,
            ]
        );

        Module::firstOrCreate(
            ['name' => 'timeline'],
            [
                'display_name' => 'Timeline',
                'description' => 'Widok osi czasu zadań — przegląd terminów i obciążenia pracowników',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'activity',
                'is_active' => true,
                'is_core' => false,
                'order' => 4,
            ]
        );
    }

    public function down(): void
    {
        Module::where('name', 'kanban')->delete();
        Module::where('name', 'timeline')->delete();
    }
};
