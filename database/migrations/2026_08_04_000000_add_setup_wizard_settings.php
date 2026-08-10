<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ustawienia kreatora pierwszego uruchomienia (/setup).
 *
 * Dodatkowo: instalacje, ktore juz pracuja (maja klientow/zadania/wizyty albo
 * wiecej niz jednego uzytkownika) oznaczamy jako skonfigurowane. Bez tego po
 * deployu wpadlyby w kreator mimo dzialajacej konfiguracji.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        $rows = [
            ['key' => 'setup_progress',     'type' => 'json',   'label' => 'Postęp kreatora',        'description' => 'Które kroki kreatora zostały ukończone lub pominięte', 'value' => '{}',  'order' => 20],
            ['key' => 'setup_completed_at', 'type' => 'string', 'label' => 'Data ukończenia setupu', 'description' => 'Kiedy admin zakończył pierwszą konfigurację',          'value' => null,  'order' => 30],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('settings')->where('module', 'core')->where('key', $row['key'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('settings')->insert(array_merge($row, [
                'module'     => 'core',
                'group'      => 'system',
                'options'    => null,
                'is_public'  => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $this->markExistingInstallationAsConfigured($now);
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('module', 'core')
            ->whereIn('key', ['setup_progress', 'setup_completed_at'])
            ->delete();
    }

    /**
     * Heurystyka "instalacja juz pracuje": sa dane robocze albo wiecej niz
     * jeden uzytkownik (install.sh zaklada dokladnie jednego admina).
     */
    protected function markExistingInstallationAsConfigured($now): void
    {
        $completed = DB::table('settings')
            ->where('module', 'core')
            ->where('key', 'setup_completed')
            ->value('value');

        if ($completed === '1') {
            return;
        }

        $hasWorkingData = $this->tableHasRows('clients')
            || $this->tableHasRows('client_visits')
            || $this->tableHasRows('tasks')
            || $this->countRows('users') > 1;

        if (!$hasWorkingData) {
            return; // swieza instalacja — niech przejdzie kreator
        }

        DB::table('settings')->updateOrInsert(
            ['module' => 'core', 'key' => 'setup_completed'],
            ['value' => '1', 'updated_at' => $now],
        );

        $progress = json_encode([
            'license'     => 'done',
            'company'     => 'done',
            'branding'    => 'done',
            'baseline'    => 'done',
            'preferences' => 'done',
            'modules'     => 'done',
        ]);

        DB::table('settings')->updateOrInsert(
            ['module' => 'core', 'key' => 'setup_progress'],
            ['value' => $progress, 'updated_at' => $now],
        );
    }

    protected function tableHasRows(string $table): bool
    {
        return $this->countRows($table) > 0;
    }

    protected function countRows(string $table): int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
};
