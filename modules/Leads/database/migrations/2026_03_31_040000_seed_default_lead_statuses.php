<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = [
            ['name' => 'Nowy',                   'slug' => 'nowy',                    'color' => '#6B7280', 'order' => 1, 'is_default' => true,  'is_terminal' => false],
            ['name' => 'Oferta wysłana',         'slug' => 'oferta-wyslana',          'color' => '#3B82F6', 'order' => 2, 'is_default' => false, 'is_terminal' => false],
            ['name' => 'Oferta odczytana',       'slug' => 'oferta-odczytana',        'color' => '#F59E0B', 'order' => 3, 'is_default' => false, 'is_terminal' => false,
             'auto_rules' => json_encode([['trigger' => 'email_opened', 'target_status_slug' => 'oferta-odczytana']])],
            ['name' => 'Kontakt telefoniczny',   'slug' => 'kontakt-telefoniczny',    'color' => '#F97316', 'order' => 4, 'is_default' => false, 'is_terminal' => false,
             'auto_rules' => json_encode([['trigger' => 'call_detected', 'target_status_slug' => 'kontakt-telefoniczny']])],
            ['name' => 'Zainteresowany',         'slug' => 'zainteresowany',          'color' => '#22C55E', 'order' => 5, 'is_default' => false, 'is_terminal' => false],
            ['name' => 'Klient',                 'slug' => 'klient',                  'color' => '#10B981', 'order' => 6, 'is_default' => false, 'is_terminal' => true],
            ['name' => 'Odrzucony',              'slug' => 'odrzucony',               'color' => '#EF4444', 'order' => 7, 'is_default' => false, 'is_terminal' => true],
        ];

        $now = now();
        foreach ($statuses as &$s) {
            $s['created_at'] = $now;
            $s['updated_at'] = $now;
            if (!isset($s['auto_rules'])) {
                $s['auto_rules'] = null;
            }
        }

        DB::table('lead_statuses')->insert($statuses);
    }

    public function down(): void
    {
        DB::table('lead_statuses')->truncate();
    }
};
