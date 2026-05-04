<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Setting;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        // Moduły systemowe (core)
        $coreModules = [
            [
                'name' => 'core',
                'display_name' => 'System',
                'description' => 'Podstawowy moduł systemowy zawierający fundamentalne funkcje aplikacji',
                'version' => '1.0.0',
                'author' => 'OVERCRM',
                'icon' => 'settings',
                'is_active' => true,
                'is_core' => true,
                'order' => 1,
            ],
            [
                'name' => 'users',
                'display_name' => 'Użytkownicy',
                'description' => 'Zarządzanie użytkownikami, rolami i uprawnieniami',
                'version' => '1.0.0',
                'author' => 'OVERCRM',
                'icon' => 'users',
                'is_active' => true,
                'is_core' => true,
                'order' => 2,
            ],
            [
                'name' => 'clients',
                'display_name' => 'Klienci',
                'description' => 'Zarządzanie bazą klientów i kontrahentów',
                'version' => '1.0.0',
                'author' => 'OVERCRM',
                'icon' => 'clients',
                'is_active' => true,
                'is_core' => true,
                'order' => 3,
            ],
            [
                'name' => 'planner',
                'display_name' => 'Planner',
                'description' => 'System zarządzania zadaniami i projektami',
                'version' => '1.0.0',
                'author' => 'OVERCRM',
                'icon' => 'tasks',
                'is_active' => true,
                'is_core' => true,
                'order' => 4,
            ],
            [
                'name' => 'calendar',
                'display_name' => 'Kalendarz',
                'description' => 'Kalendarz wizyt klientów z drag & drop',
                'version' => '1.0.0',
                'author' => 'OVERCRM',
                'icon' => 'calendar',
                'is_active' => true,
                'is_core' => false,
                'order' => 5,
            ],
        ];

        foreach ($coreModules as $module) {
            Module::firstOrCreate(['name' => $module['name']], $module);
        }

        // Podstawowe ustawienia systemowe
        $coreSettings = [
            // Ogólne
            [
                'module' => 'core',
                'group' => 'general',
                'key' => 'app_name',
                'value' => 'OVERCRM',
                'type' => 'string',
                'label' => 'Nazwa aplikacji',
                'description' => 'Nazwa wyświetlana w interfejsie',
                'order' => 1,
            ],
            [
                'module' => 'core',
                'group' => 'general',
                'key' => 'app_logo',
                'value' => null,
                'type' => 'string',
                'label' => 'Logo aplikacji',
                'description' => 'URL do logo (pozostaw puste dla domyślnego)',
                'order' => 2,
            ],
            [
                'module' => 'core',
                'group' => 'general',
                'key' => 'default_language',
                'value' => 'pl',
                'type' => 'select',
                'label' => 'Domyślny język',
                'options' => ['pl' => 'Polski', 'en' => 'English'],
                'order' => 3,
            ],
            [
                'module' => 'core',
                'group' => 'general',
                'key' => 'timezone',
                'value' => 'Europe/Warsaw',
                'type' => 'string',
                'label' => 'Strefa czasowa',
                'order' => 4,
            ],

            // Dane firmy
            [
                'module' => 'core',
                'group' => 'company',
                'key' => 'company_name',
                'value' => 'OVERCRM',
                'type' => 'string',
                'label' => 'Nazwa firmy',
                'order' => 1,
            ],
            [
                'module' => 'core',
                'group' => 'company',
                'key' => 'company_nip',
                'value' => '',
                'type' => 'string',
                'label' => 'NIP',
                'order' => 2,
            ],
            [
                'module' => 'core',
                'group' => 'company',
                'key' => 'company_address',
                'value' => '',
                'type' => 'textarea',
                'label' => 'Adres firmy',
                'order' => 3,
            ],
            [
                'module' => 'core',
                'group' => 'company',
                'key' => 'company_email',
                'value' => '',
                'type' => 'string',
                'label' => 'Email kontaktowy',
                'order' => 4,
            ],
            [
                'module' => 'core',
                'group' => 'company',
                'key' => 'company_phone',
                'value' => '',
                'type' => 'string',
                'label' => 'Telefon',
                'order' => 5,
            ],

            // Integracje
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'fakturownia_api_token',
                'value' => '',
                'type' => 'string',
                'label' => 'Fakturownia API Token',
                'description' => 'Token API do integracji z Fakturownia.pl',
                'order' => 1,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'fakturownia_subdomain',
                'value' => '',
                'type' => 'string',
                'label' => 'Fakturownia Subdomena',
                'description' => 'Subdomena konta (np. mojafirma)',
                'order' => 2,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'apilo_subdomain',
                'value' => '',
                'type' => 'string',
                'label' => 'Apilo Adres',
                'description' => 'Nazwa konta Apilo (np. mojafirma)',
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
                'description' => 'Data wygaśnięcia access tokenu (ISO 8601)',
                'order' => 8,
            ],
            [
                'module' => 'core',
                'group' => 'integrations',
                'key' => 'gus_api_key',
                'value' => '',
                'type' => 'string',
                'label' => 'GUS API Key',
                'description' => 'Klucz do API BIR (GUS) - zostaw puste dla testowego',
                'order' => 5,
            ],

            // Wygląd
            [
                'module' => 'core',
                'group' => 'appearance',
                'key' => 'primary_color',
                'value' => '#4F46E5',
                'type' => 'string',
                'label' => 'Kolor główny',
                'description' => 'Kolor akcentu w interfejsie (format HEX)',
                'order' => 1,
            ],
            [
                'module' => 'core',
                'group' => 'appearance',
                'key' => 'dark_mode_default',
                'value' => '0',
                'type' => 'boolean',
                'label' => 'Domyślny tryb ciemny',
                'description' => 'Włącz domyślnie ciemny motyw',
                'order' => 2,
            ],

            // Poczta
            [
                'module' => 'core',
                'group' => 'mail',
                'key' => 'mail_from_address',
                'value' => '',
                'type' => 'string',
                'label' => 'Adres nadawcy',
                'order' => 1,
            ],
            [
                'module' => 'core',
                'group' => 'mail',
                'key' => 'mail_from_name',
                'value' => 'OVERCRM',
                'type' => 'string',
                'label' => 'Nazwa nadawcy',
                'order' => 2,
            ],
        ];

        foreach ($coreSettings as $setting) {
            Setting::firstOrCreate(
                ['module' => $setting['module'], 'key' => $setting['key']],
                $setting
            );
        }
    }
}
