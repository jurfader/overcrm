<?php

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Module::firstOrCreate(
            ['name' => 'email'],
            [
                'display_name' => 'Skrzynka odbiorcza',
                'description' => 'Odczyt maili z podłączonego serwera IMAP (te same dane logowania co SMTP)',
                'version' => '1.0.0',
                'author' => 'CHICKENKING',
                'icon' => 'mail',
                'is_active' => true,
                'is_core' => false,
                'order' => 20,
            ]
        );
    }

    public function down(): void
    {
        Module::where('name', 'email')->delete();
    }
};
