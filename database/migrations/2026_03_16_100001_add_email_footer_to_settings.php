<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['module' => 'core', 'key' => 'email_html_footer'],
            [
                'group' => 'mail',
                'value' => '',
                'type' => 'textarea',
                'label' => 'Stopka HTML wiadomości',
                'description' => 'HTML dodawany na stałe na końcu każdej wysyłanej wiadomości (np. dane firmy, logo)',
                'order' => 10,
            ]
        );
    }

    public function down(): void
    {
        Setting::where('module', 'core')->where('key', 'email_html_footer')->delete();
    }
};
