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
            ['key' => 'provider_product', 'group' => 'integrations', 'type' => 'string', 'label' => 'Provider produktów',  'description' => 'Skąd CRM pobiera produkty (lokalny magazyn, Apilo, BaseLinker, …)',          'value' => 'local', 'order' => 10],
            ['key' => 'provider_order',   'group' => 'integrations', 'type' => 'string', 'label' => 'Provider zamówień',   'description' => 'Gdzie CRM zapisuje zamówienia (lokalna baza+PDF, Apilo orders API, …)', 'value' => 'local', 'order' => 20],
            ['key' => 'provider_invoice', 'group' => 'integrations', 'type' => 'string', 'label' => 'Provider faktur',     'description' => 'Czym wystawiać faktury (brak / Fakturownia / iFirma)',                  'value' => 'none',  'order' => 30],
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
        DB::table('settings')->where('module', 'core')->whereIn('key', [
            'provider_product', 'provider_order', 'provider_invoice',
        ])->delete();
    }
};
