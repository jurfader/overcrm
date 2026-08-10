<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadania cykliczne, godzina terminu i przypomnienia.
 *
 * Trzy rzeczy, bez których lista zadań jest tylko notatnikiem:
 *  - cykliczność (raport co poniedziałek, telefon kontrolny co miesiąc),
 *  - godzina terminu — bez niej przypomnienie „na godzinę przed" liczy się
 *    od północy i przychodzi w środku nocy,
 *  - przypomnienia, żeby zadanie samo się upomniało.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Godzina terminu. Pusta = zadanie na cały dzień.
            $table->time('due_time')->nullable()->after('due_date');

            // Cykliczność. `custom` zostawia furtkę na reguły, których nie da się
            // opisać interwałem (np. „ostatni piątek miesiąca").
            $table->string('recurrence_type', 20)->nullable()->after('due_time');
            $table->unsignedSmallInteger('recurrence_interval')->default(1)->after('recurrence_type');
            // Dni tygodnia dla trybu weekly, ISO 1–7 (poniedziałek = 1).
            $table->json('recurrence_weekdays')->nullable()->after('recurrence_interval');
            $table->date('recurrence_until')->nullable()->after('recurrence_weekdays');
            // Wskazuje na pierwsze zadanie w serii — pozwala pokazać całą serię
            // i przerwać ją w jednym miejscu.
            $table->foreignId('recurrence_parent_id')->nullable()->after('recurrence_until')
                ->constrained('tasks')->nullOnDelete();

            // Ile minut przed terminem wysłać przypomnienie (max 30 dni).
            $table->unsignedInteger('reminder_offset_minutes')->nullable()->after('recurrence_parent_id');
            // Znacznik wysyłki — chroni przed powtórnym powiadomieniem przy
            // każdym przebiegu harmonogramu.
            $table->timestamp('reminder_sent_at')->nullable()->after('reminder_offset_minutes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_parent_id');
            $table->dropColumn([
                'due_time',
                'recurrence_type',
                'recurrence_interval',
                'recurrence_weekdays',
                'recurrence_until',
                'reminder_offset_minutes',
                'reminder_sent_at',
            ]);
        });
    }
};
