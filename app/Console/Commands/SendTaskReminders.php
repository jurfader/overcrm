<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Support\Notifications\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Wysyła przypomnienia o zbliżających się terminach zadań.
 *
 * Uruchamiana z harmonogramu co 5 minut. Idempotentna dzięki `reminder_sent_at`
 * — nawet przy nakładających się przebiegach nikt nie dostanie tego samego
 * przypomnienia dwa razy.
 *
 * Powiadomienie idzie przez Notifier, więc trafia wszystkimi włączonymi kanałami
 * (mail, a po dołożeniu modułów także push, WhatsApp czy SMS) — komenda nie wie,
 * które z nich klient ma.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders {--dry-run : Pokaż, co zostałoby wysłane, ale nic nie wysyłaj}';

    protected $description = 'Wyślij przypomnienia o zbliżających się terminach zadań';

    public function handle(Notifier $notifier): int
    {
        $naSucho = (bool) $this->option('dry-run');

        $kandydaci = Task::query()
            ->whereNotNull('due_date')
            ->whereNotNull('reminder_offset_minutes')
            ->whereNull('reminder_sent_at')
            ->incomplete()
            ->with(['assignee', 'creator', 'collaborators', 'client'])
            ->get();

        $wyslane = 0;
        $pominiete = 0;

        foreach ($kandydaci as $zadanie) {
            $termin = $zadanie->due_at;

            if (!$termin) {
                continue;
            }

            $momentPrzypomnienia = $termin->copy()->subMinutes((int) $zadanie->reminder_offset_minutes);

            // Jeszcze za wcześnie — zadanie doczeka kolejnego przebiegu.
            if ($momentPrzypomnienia->isFuture()) {
                continue;
            }

            $odbiorcy = $this->odbiorcy($zadanie);

            if ($odbiorcy->isEmpty()) {
                $pominiete++;
                continue;
            }

            if ($naSucho) {
                $this->line(sprintf(
                    '  [%s] %s → %s',
                    $termin->format('Y-m-d H:i'),
                    $zadanie->title,
                    $odbiorcy->pluck('name')->join(', ')
                ));
                $wyslane++;
                continue;
            }

            try {
                $notifier->sendToMany(
                    $odbiorcy,
                    'Zbliża się termin zadania',
                    $this->tresc($zadanie, $termin),
                    ['url' => route('tasks.show', $zadanie->id), 'tag' => 'task-'.$zadanie->id]
                );

                // Znaczymy NAWET gdy żaden kanał nie zadziałał. Powtarzanie próby
                // co 5 minut do skutku zasypałoby logi i — po naprawie poczty —
                // wysłało lawinę spóźnionych przypomnień.
                $zadanie->forceFill(['reminder_sent_at' => now()])->save();
                $wyslane++;
            } catch (\Throwable $e) {
                Log::warning('Przypomnienie o zadaniu nie zostało wysłane', [
                    'task_id' => $zadanie->id,
                    'error' => $e->getMessage(),
                ]);
                $pominiete++;
            }
        }

        $this->info(($naSucho ? '[na sucho] ' : '')."Przypomnienia: wysłane {$wyslane}, pominięte {$pominiete}.");

        return self::SUCCESS;
    }

    /**
     * Kto dostaje przypomnienie: wykonawca, autor i współpracownicy — czyli
     * dokładnie ci, którzy zadanie widzą.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    protected function odbiorcy(Task $zadanie): \Illuminate\Support\Collection
    {
        return collect([$zadanie->assignee, $zadanie->creator])
            ->merge($zadanie->collaborators)
            ->filter()
            ->unique('id')
            ->values();
    }

    protected function tresc(Task $zadanie, \Carbon\Carbon $termin): string
    {
        $czesci = [$zadanie->title];

        if ($zadanie->client) {
            $czesci[] = 'Klient: '.($zadanie->client->short_name ?: $zadanie->client->name);
        }

        $czesci[] = 'Termin: '.$termin->format('d.m.Y H:i');

        return implode("\n", $czesci);
    }
}
