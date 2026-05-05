<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings')) return;

        $exists = DB::table('settings')->where('module', 'core')->where('key', 'license_state_hmac')->exists();
        if ($exists) return;

        DB::table('settings')->insert([
            'module'      => 'core',
            'group'       => 'license',
            'key'         => 'license_state_hmac',
            'type'        => 'string',
            'label'       => 'HMAC stanu licencji',
            'description' => 'Anti-tamper: HMAC-SHA256 nad locked fields (key=APP_KEY). Niezmodyfikowane przy ręcznej edycji = invalid.',
            'value'       => null,
            'options'     => null,
            'is_public'   => false,
            'order'       => 90,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) return;
        DB::table('settings')->where('module', 'core')->where('key', 'license_state_hmac')->delete();
    }
};
