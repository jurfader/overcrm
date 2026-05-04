<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Usuń stare ustawienia Ringostat (zastąpione przez Play)
        DB::table('settings')
            ->where('module', 'ringostat')
            ->whereIn('key', ['ringostat_auth_key', 'ringostat_project_id'])
            ->delete();

        // Upewnij się, że nowe ustawienia Play istnieją w DB (z domyślnymi wartościami)
        $playSettings = [
            ['key' => 'play_client_id',       'group' => 'integrations', 'type' => 'string',   'label' => 'Play Client ID',           'description' => '16-znakowy identyfikator API z Panelu Usług dla Firm', 'order' => 10],
            ['key' => 'play_client_secret',    'group' => 'integrations', 'type' => 'password',  'label' => 'Play Client Secret',        'description' => '64-znakowe hasło API',                                  'order' => 20],
            ['key' => 'play_private_key',      'group' => 'integrations', 'type' => 'textarea',  'label' => 'Klucz prywatny (PEM)',      'description' => 'Klucz prywatny RSA do odszyfrowania nagrań',            'order' => 30],
            ['key' => 'play_webhook_login',    'group' => 'integrations', 'type' => 'string',   'label' => 'Webhook — Login (Basic Auth)',  'description' => 'Login do autoryzacji webhooka z Play',              'order' => 40],
            ['key' => 'play_webhook_password', 'group' => 'integrations', 'type' => 'password',  'label' => 'Webhook — Hasło (Basic Auth)',  'description' => 'Hasło do autoryzacji webhooka',                    'order' => 50],
        ];

        foreach ($playSettings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['module' => 'ringostat', 'key' => $setting['key']],
                [
                    'module'      => 'ringostat',
                    'key'         => $setting['key'],
                    'value'       => '',
                    'group'       => $setting['group'],
                    'type'        => $setting['type'],
                    'label'       => $setting['label'],
                    'description' => $setting['description'],
                    'order'       => $setting['order'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('module', 'ringostat')
            ->whereIn('key', ['play_client_id', 'play_client_secret', 'play_private_key', 'play_webhook_login', 'play_webhook_password'])
            ->delete();
    }
};
