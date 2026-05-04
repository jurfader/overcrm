<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Usuń stare pola Apilo jeśli istnieją
        Setting::where('module', 'core')
            ->whereIn('key', ['apilo_api_key', 'apilo_api_secret'])
            ->delete();

        // Dodaj nowe pola Apilo (jeśli nie istnieją)
        $settings = [
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_subdomain',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Adres',
                'description' => 'Nazwa konta Apilo (np. overcrm)',
                'order' => 3,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_client_id',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Client ID',
                'description' => 'Client ID z Administracja > API Apilo',
                'order' => 4,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_client_secret',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Client Secret',
                'description' => 'Client Secret z Administracja > API Apilo',
                'order' => 5,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_access_token',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Access Token',
                'description' => 'Wklejony z Apilo lub uzupełniony automatycznie po autoryzacji',
                'order' => 6,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_refresh_token',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Refresh Token',
                'description' => 'Wklejony z Apilo lub uzupełniony automatycznie po autoryzacji',
                'order' => 7,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_access_token_expires_at',
                'value' => '',
                'type' => 'string',
                'label' => 'Access Token Ważny do',
                'description' => 'Data wygaśnięcia access tokenu (automatycznie)',
                'order' => 8,
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['module' => $setting['module'], 'key' => $setting['key']],
                $setting
            );
        }
    }

    public function down(): void
    {
        Setting::where('module', 'core')
            ->whereIn('key', [
                'apilo_subdomain',
                'apilo_client_id',
                'apilo_client_secret',
                'apilo_access_token',
                'apilo_refresh_token',
                'apilo_access_token_expires_at',
            ])
            ->delete();
    }
};
