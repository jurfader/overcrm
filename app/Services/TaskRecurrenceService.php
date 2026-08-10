<?php

namespace App\Services;

use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Zadania cykliczne.
 *
 * Model jest celowo prosty: w bazie istnieje zawsze JEDNO otwarte wystąpienie
 * serii. Kolejne powstaje dopiero w momencie zamknięcia poprzedniego, a nie
 * z góry na rok naprzód. Dzięki temu lista zadań pokazuje to, co faktycznie
 * jest do zrobienia, a zmiana treści zadania nie wymaga poprawiania setek
 * przyszłych kopii.
 */
class TaskRecurrenceService
{
    public const TYPES = [
        'daily' => 'Codziennie',
        'weekly' => 'Co tydzień',
        'monthly' => 'Co miesiąc',
        'yearly' => 'Co rok',
    ];

    /**
     * Tworzy kolejne wystąpienie serii po zamknięciu zadania.
     *
     * Zwraca null, gdy zadanie nie jest cykliczne, seria dobiegła końca albo
     * następny termin wypadłby po dacie zakończenia.
     */
    public function createNextOccurrence(Task $task): ?Task
    {
        if (!$this->isRecurring($task) || !$task->due_date) {
            return null;
        }

        $nastepny = $this->nextDueDate($task);

        if (!$nastepny) {
            return null;
        }

        $nowe = $task->replicate([
            'completed_at',
            'reminder_sent_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);

        $nowe->due_date = $nastepny;
        $nowe->completed_at = null;
        $nowe->reminder_sent_at = null;
        // Pierwsze zadanie serii jest jej korzeniem — kolejne wskazują na nie,
        // a nie na swojego bezpośredniego poprzednika. Inaczej powstałby łańcuch,
        // po którym trzeba by się cofać, żeby znaleźć początek.
        $nowe->recurrence_parent_id = $task->recurrence_parent_id ?? $task->id;
        $nowe->status_id = $this->statusPoczatkowy($task);
        $nowe->save();

        // Współpracownicy przechodzą na kolejne wystąpienie — to ta sama praca,
        // tylko w następnym okresie.
        $wspolpracownicy = $task->collaborators()->pluck('users.id')->all();

        if ($wspolpracownicy) {
            $nowe->collaborators()->sync($wspolpracownicy);
        }

        return $nowe;
    }

    public function isRecurring(?Task $task): bool
    {
        return $task
            && $task->recurrence_type
            && array_key_exists($task->recurrence_type, self::TYPES);
    }

    /**
     * Termin kolejnego wystąpienia albo null, gdy seria się skończyła.
     */
    public function nextDueDate(Task $task): ?Carbon
    {
        $od = Carbon::parse($task->due_date);
        $krok = max(1, (int) ($task->recurrence_interval ?: 1));

        $nastepny = match ($task->recurrence_type) {
            'daily' => $od->copy()->addDays($krok),
            'weekly' => $this->nastepnyTygodniowy($od, $task, $krok),
            'monthly' => $od->copy()->addMonthsNoOverflow($krok),
            'yearly' => $od->copy()->addYearsNoOverflow($krok),
            default => null,
        };

        if (!$nastepny) {
            return null;
        }

        if ($task->recurrence_until && $nastepny->gt(Carbon::parse($task->recurrence_until))) {
            return null;
        }

        return $nastepny;
    }

    /**
     * Tryb tygodniowy z wyborem dni.
     *
     * Gdy podano dni tygodnia, szukamy najbliższego z nich PO bieżącym terminie.
     * Interwał („co 2 tygodnie") stosuje się dopiero po wyczerpaniu dni
     * w bieżącym tygodniu — inaczej „poniedziałek i czwartek co 2 tygodnie"
     * gubiłoby czwartek.
     */
    protected function nastepnyTygodniowy(Carbon $od, Task $task, int $krok): Carbon
    {
        $dni = collect((array) $task->recurrence_weekdays)
            ->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= 1 && $d <= 7)
            ->sort()
            ->values();

        if ($dni->isEmpty()) {
            return $od->copy()->addWeeks($krok);
        }

        $biezacyDzien = $od->dayOfWeekIso;
        $kolejnyWTygodniu = $dni->first(fn ($d) => $d > $biezacyDzien);

        if ($kolejnyWTygodniu !== null) {
            return $od->copy()->addDays($kolejnyWTygodniu - $biezacyDzien);
        }

        // Wyczerpaliśmy dni w tym tygodniu — przeskakujemy o interwał
        // i wracamy do pierwszego wybranego dnia.
        return $od->copy()
            ->addWeeks($krok)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addDays($dni->first() - 1);
    }

    /**
     * Status, od którego zaczyna nowe wystąpienie: domyślny status zadań,
     * a gdy go nie ma — pierwszy niekończący.
     */
    protected function statusPoczatkowy(Task $task): ?int
    {
        $domyslny = \App\Models\Status::context(\App\Models\Status::CONTEXT_TASK)
            ->where('is_default', true)
            ->value('id');

        if ($domyslny) {
            return $domyslny;
        }

        return \App\Models\Status::context(\App\Models\Status::CONTEXT_TASK)
            ->where('is_final', false)
            ->ordered()
            ->value('id') ?? $task->status_id;
    }
}
