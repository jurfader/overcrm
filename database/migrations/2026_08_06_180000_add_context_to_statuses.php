<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rozdzielenie statusów zadań od statusów kalendarza.
 *
 * Dotąd obie rzeczy dzieliły jedną pulę, więc administrator dodający kolor
 * spotkania („Wizyta u klienta") widział go potem na liście statusów zadania,
 * a status zadania („Wykonane") pojawiał się jako kolor w kalendarzu. Kolumna
 * `context` rozdziela te dwa zbiory.
 *
 * Istniejące statusy trafiają do kalendarza, bo to one nadawały kolory spotkaniom
 * i są używane w danych. Zadania dostają własny, minimalny zestaw i są na niego
 * przemapowane wg typu dotychczasowego statusu — bez tego wszystkie wylądowałyby
 * bez statusu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('statuses') || Schema::hasColumn('statuses', 'context')) {
            return;
        }

        Schema::table('statuses', function (Blueprint $table) {
            $table->string('context')->default('calendar')->after('type')->index();
        });

        DB::table('statuses')->update(['context' => 'calendar']);

        $now = now();
        $noweStatusy = [
            ['name' => 'Do zrobienia', 'slug' => 'task_todo', 'type' => 'new', 'color' => '#3B82F6', 'order' => 1, 'is_default' => true, 'is_final' => false],
            ['name' => 'W trakcie', 'slug' => 'task_in_progress', 'type' => 'in_progress', 'color' => '#F59E0B', 'order' => 2, 'is_default' => false, 'is_final' => false],
            ['name' => 'Zrobione', 'slug' => 'task_done', 'type' => 'done', 'color' => '#10B981', 'order' => 3, 'is_default' => false, 'is_final' => true],
        ];

        foreach ($noweStatusy as $status) {
            if (DB::table('statuses')->where('slug', $status['slug'])->exists()) {
                continue;
            }

            DB::table('statuses')->insert($status + [
                'context' => 'task',
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!Schema::hasTable('tasks')) {
            return;
        }

        $doZrobienia = DB::table('statuses')->where('slug', 'task_todo')->value('id');
        $wTrakcie = DB::table('statuses')->where('slug', 'task_in_progress')->value('id');
        $zrobione = DB::table('statuses')->where('slug', 'task_done')->value('id');

        // Anulowane traktujemy jak zakończone — zadanie ma wypaść z aktywnych,
        // a osobny status „anulowane" nie niesie tu wartości.
        $mapa = [
            'new' => $doZrobienia,
            'in_progress' => $wTrakcie,
            'done' => $zrobione,
            'cancelled' => $zrobione,
        ];

        foreach (DB::table('statuses')->where('context', 'calendar')->get(['id', 'type']) as $stary) {
            DB::table('tasks')
                ->where('status_id', $stary->id)
                ->update(['status_id' => $mapa[$stary->type] ?? $doZrobienia]);
        }

        DB::table('tasks')->whereNull('status_id')->update(['status_id' => $doZrobienia]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('statuses')) {
            return;
        }

        DB::table('statuses')->whereIn('slug', ['task_todo', 'task_in_progress', 'task_done'])->delete();

        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
