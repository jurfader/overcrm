<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskRecurrenceService;
use App\Support\Notifications\Notifier;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Zadania cykliczne i przypomnienia.
 *
 * Oba mechanizmy działają bez udziału użytkownika, więc błąd w nich objawia się
 * jako „coś się nie stało" — najgorszy rodzaj awarii do zauważenia.
 */
class TaskRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected TaskRecurrenceService $serwis;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serwis = app(TaskRecurrenceService::class);
        $this->user = User::create([
            'name' => 'Handlowiec',
            'email' => 'handlowiec@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
        ]);
    }

    protected function makeTask(array $attrs = []): Task
    {
        return Task::create(array_merge([
            'title' => 'Raport tygodniowy',
            'status_id' => Status::context(Status::CONTEXT_TASK)->where('is_final', false)->value('id'),
            'created_by' => $this->user->id,
            'assigned_to' => $this->user->id,
            'priority' => 'medium',
            'due_date' => '2026-08-10', // poniedziałek
        ], $attrs));
    }

    public function test_zadanie_bez_cyklicznosci_nie_tworzy_kolejnego(): void
    {
        $this->assertNull($this->serwis->createNextOccurrence($this->makeTask()));
    }

    public function test_cykl_dzienny_i_tygodniowy_liczy_kolejny_termin(): void
    {
        $co3Dni = $this->makeTask(['recurrence_type' => 'daily', 'recurrence_interval' => 3]);
        $this->assertSame('2026-08-13', $this->serwis->nextDueDate($co3Dni)->toDateString());

        $coTydzien = $this->makeTask(['recurrence_type' => 'weekly', 'recurrence_interval' => 1]);
        $this->assertSame('2026-08-17', $this->serwis->nextDueDate($coTydzien)->toDateString());
    }

    public function test_cykl_tygodniowy_z_wybranymi_dniami(): void
    {
        // Poniedziałek i czwartek. Z poniedziałku 10.08 następny ma być czwartek 13.08,
        // a nie skok o cały tydzień — inaczej czwartek by wypadł z serii.
        $zadanie = $this->makeTask([
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_weekdays' => [1, 4],
        ]);

        $this->assertSame('2026-08-13', $this->serwis->nextDueDate($zadanie)->toDateString());

        // Z czwartku wracamy na poniedziałek kolejnego tygodnia.
        $zCzwartku = $this->makeTask([
            'due_date' => '2026-08-13',
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_weekdays' => [1, 4],
        ]);

        $this->assertSame('2026-08-17', $this->serwis->nextDueDate($zCzwartku)->toDateString());
    }

    public function test_data_konca_serii_zatrzymuje_powtarzanie(): void
    {
        $zadanie = $this->makeTask([
            'recurrence_type' => 'weekly',
            'recurrence_until' => '2026-08-15',
        ]);

        // Następny termin (17.08) wypada po dacie końca — seria się kończy.
        $this->assertNull($this->serwis->nextDueDate($zadanie));
        $this->assertNull($this->serwis->createNextOccurrence($zadanie));
    }

    public function test_kolejne_wystapienie_wskazuje_na_korzen_serii(): void
    {
        $pierwsze = $this->makeTask(['recurrence_type' => 'daily']);
        $pierwsze->collaborators()->attach($this->user->id);

        $drugie = $this->serwis->createNextOccurrence($pierwsze);
        $this->assertNotNull($drugie);
        $this->assertSame($pierwsze->id, $drugie->recurrence_parent_id);

        // Trzecie wskazuje nadal na PIERWSZE, nie na drugie — inaczej powstałby
        // łańcuch, po którym trzeba się cofać, żeby znaleźć początek serii.
        $trzecie = $this->serwis->createNextOccurrence($drugie);
        $this->assertSame($pierwsze->id, $trzecie->recurrence_parent_id);

        // Współpracownicy przechodzą dalej — to ta sama praca w kolejnym okresie.
        $this->assertTrue($drugie->collaborators->contains($this->user->id));
    }

    public function test_kolejne_wystapienie_startuje_jako_otwarte(): void
    {
        $zadanie = $this->makeTask([
            'recurrence_type' => 'daily',
            'completed_at' => now(),
            'reminder_sent_at' => now(),
        ]);

        $kolejne = $this->serwis->createNextOccurrence($zadanie);

        $this->assertNull($kolejne->completed_at);
        $this->assertNull($kolejne->reminder_sent_at, 'Nowe wystąpienie musi móc wysłać własne przypomnienie');
        $this->assertFalse($kolejne->status->is_final);
    }

    public function test_termin_uwzglednia_godzine(): void
    {
        $calodniowe = $this->makeTask();
        $this->assertSame('23:59', $calodniowe->due_at->format('H:i'), 'Bez godziny zadanie jest całodniowe');

        $zGodzina = $this->makeTask(['due_time' => '09:30']);
        $this->assertSame('09:30', $zGodzina->due_at->format('H:i'));
    }

    public function test_przypomnienie_wysyla_sie_raz(): void
    {
        $rejestr = app(ProviderRegistry::class);
        $rejestr->register('notification', 'atrapa', AtrapaKanalu::class);
        $rejestr->setEnabled('notification', ['atrapa']);
        AtrapaKanalu::$wyslane = [];

        // Termin za 10 minut, przypomnienie na 30 minut przed → już powinno pójść.
        $zadanie = $this->makeTask([
            'due_date' => now()->toDateString(),
            'due_time' => now()->addMinutes(10)->format('H:i'),
            'reminder_offset_minutes' => 30,
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertCount(1, AtrapaKanalu::$wyslane);
        $this->assertNotNull($zadanie->fresh()->reminder_sent_at);

        // Drugi przebieg nie może wysłać powtórki.
        $this->artisan('tasks:send-reminders')->assertSuccessful();
        $this->assertCount(1, AtrapaKanalu::$wyslane);
    }

    public function test_przypomnienie_nie_idzie_przed_czasem(): void
    {
        $rejestr = app(ProviderRegistry::class);
        $rejestr->register('notification', 'atrapa', AtrapaKanalu::class);
        $rejestr->setEnabled('notification', ['atrapa']);
        AtrapaKanalu::$wyslane = [];

        $this->makeTask([
            'due_date' => Carbon::tomorrow()->toDateString(),
            'due_time' => '12:00',
            'reminder_offset_minutes' => 15,
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertSame([], AtrapaKanalu::$wyslane);
    }
}

class AtrapaKanalu implements \App\Contracts\NotificationChannel
{
    /** @var array<int, array<string, mixed>> */
    public static array $wyslane = [];

    public function key(): string { return 'atrapa'; }
    public function label(): string { return 'Atrapa'; }
    public function isAvailable(): bool { return true; }
    public function canReach(User $user): bool { return true; }

    public function send(User $user, string $title, string $body, array $options = []): bool
    {
        self::$wyslane[] = ['user' => $user->id, 'title' => $title, 'body' => $body];

        return true;
    }
}
