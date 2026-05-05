<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings')) return;

        $exists = DB::table('settings')->where('module', 'core')->where('key', 'license_binding_token')->exists();
        if ($exists) return;

        DB::table('settings')->insert([
            'module'      => 'core',
            'group'       => 'license',
            'key'         => 'license_binding_token',
            'type'        => 'string',
            'label'       => 'Token bindujący instalację (encrypted)',
            'description' => 'Etap 2c anti-piracy: rotujący token unique per activation. Encrypted z APP_KEY.',
            'value'       => null,
            'options'     => null,
            'is_public'   => false,
            'order'       => 100,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) return;
        DB::table('settings')->where('module', 'core')->where('key', 'license_binding_token')->delete();
    }
};
