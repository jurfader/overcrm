<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings')) return;

        $now = now();
        $rows = [
            // ============== License (system, ukryte z UI Settings) ==============
            ['key' => 'license_key',            'group' => 'license', 'type' => 'string',   'label' => 'Klucz licencji',         'description' => 'Format: XXXX-XXXX-XXXX-XXXX', 'value' => null,    'order' => 10],
            ['key' => 'license_installation_id','group' => 'license', 'type' => 'string',   'label' => 'ID instalacji',          'description' => null,                          'value' => null,    'order' => 20],
            ['key' => 'license_status',         'group' => 'license', 'type' => 'string',   'label' => 'Status licencji',        'description' => null,                          'value' => 'missing','order' => 30],
            ['key' => 'license_plan',           'group' => 'license', 'type' => 'string',   'label' => 'Plan',                   'description' => null,                          'value' => null,    'order' => 40],
            ['key' => 'license_expires_at',     'group' => 'license', 'type' => 'string',   'label' => 'Data wygaśnięcia',       'description' => null,                          'value' => null,    'order' => 50],
            ['key' => 'license_last_check_at',  'group' => 'license', 'type' => 'string',   'label' => 'Ostatnia weryfikacja',   'description' => null,                          'value' => null,    'order' => 60],
            ['key' => 'license_grace_until',    'group' => 'license', 'type' => 'string',   'label' => 'Okres karencji do',      'description' => '7 dni grace gdy serwer licencji niedostępny', 'value' => null, 'order' => 70],
            ['key' => 'license_last_error',     'group' => 'license', 'type' => 'string',   'label' => 'Ostatni błąd',           'description' => null,                          'value' => null,    'order' => 80],

            // Setup wizard flag
            ['key' => 'setup_completed',        'group' => 'system',  'type' => 'boolean',  'label' => 'Setup ukończony',        'description' => 'Czy admin ukończył pierwszą konfigurację', 'value' => '0', 'order' => 10],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('settings')->where('module', 'core')->where('key', $row['key'])->exists();
            if ($exists) continue;

            DB::table('settings')->insert(array_merge($row, [
                'module'     => 'core',
                'is_public'  => false,
                'options'    => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) return;
        $keys = [
            'license_key', 'license_installation_id', 'license_status', 'license_plan',
            'license_expires_at', 'license_last_check_at', 'license_grace_until', 'license_last_error',
            'setup_completed',
        ];
        DB::table('settings')->where('module', 'core')->whereIn('key', $keys)->delete();
    }
};
