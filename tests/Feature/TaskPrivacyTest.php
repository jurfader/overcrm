<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\SetupService;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Izolacja zadań między użytkownikami.
 *
 * Przed tą zmianą każdy zalogowany widział i edytował wszystkie zadania w systemie
 * — stażysta miał wgląd w zadania zarządu. Testy pilnują obu warstw: zawężenia
 * listy ORAZ guarda na pojedynczym rekordzie, bo samo zawężenie listy nie chroni
 * przed wpisaniem cudzego ID w adres.
 */
class TaskPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $anna;
    protected User $bartek;
    protected Status $status;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();

        $this->admin = $this->makeUser('admin', 'admin');
        $this->anna = $this->makeUser('anna', 'user');
        $this->bartek = $this->makeUser('bartek', 'user');
        $this->status = Status::first();
    }

    protected function activateLicense(): void
    {
        $license = app(LicenseService::class);

        Setting::set('license_key', 'TEST-TEST-TEST-TEST');
        Setting::set('license_status', LicenseService::STATUS_ACTIVE);
        Setting::set('license_expires_at', now()->addYear()->toIso8601String());

        (new ReflectionMethod($license, 'writeStateLock'))->invoke($license);
        License::reset();
    }

    protected function makeUser(string $nazwa, string $rola): User
    {
        return User::create([
            'name' => ucfirst($nazwa),
            'email' => $nazwa.'@example.test',
            'password' => bcrypt('secret123'),
            'role' => $rola,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function makeTask(User $autor, ?User $wykonawca = null): Task
    {
        return Task::create([
            'title' => 'Zadanie '.$autor->name,
            'status_id' => $this->status->id,
            'created_by' => $autor->id,
            'assigned_to' => $wykonawca?->id,
            'priority' => 'medium',
        ]);
    }

    public function test_uzytkownik_nie_widzi_cudzego_zadania_na_liscie(): void
    {
        $moje = $this->makeTask($this->anna);
        $cudze = $this->makeTask($this->bartek);

        $response = $this->actingAs($this->anna)->get('/tasks');

        $response->assertOk();
        $widoczne = collect($response->viewData('page')['props']['tasks']['data'])->pluck('id');

        $this->assertTrue($widoczne->contains($moje->id));
        $this->assertFalse($widoczne->contains($cudze->id), 'Anna widzi zadanie Bartka na liście');
    }

    public function test_uzytkownik_nie_otworzy_cudzego_zadania_z_adresu(): void
    {
        // Samo zawężenie listy nie wystarcza — bez guarda na rekordzie
        // wpisanie cudzego ID w adres nadal pokazywałoby zadanie.
        //
        // Uprawnienie nadane celowo: bez niego ekran edycji odbiłby middleware
        // i test przechodziłby, nie dotykając wcale sprawdzanej reguły.
        $this->grantTasksManage($this->anna);

        $cudze = $this->makeTask($this->bartek);

        $this->actingAs($this->anna)->get("/tasks/{$cudze->id}")->assertForbidden();
        $this->actingAs($this->anna)->get("/tasks/{$cudze->id}/edit")->assertForbidden();
    }

    public function test_uzytkownik_z_uprawnieniem_i_tak_nie_zmieni_cudzego_zadania(): void
    {
        // KLUCZOWY przypadek. Uprawnienie `tasks_manage` mówi tylko „ta osoba
        // może zarządzać zadaniami" — bez polityki na rekordzie oznaczałoby
        // „może zarządzać WSZYSTKIMI zadaniami w firmie". Dlatego Anna dostaje
        // tu uprawnienie: bez niego odbiłoby ją middleware i test nie dotknąłby
        // wcale sprawdzanej reguły.
        $this->grantTasksManage($this->anna);

        $cudze = $this->makeTask($this->bartek);

        $this->actingAs($this->anna)
            ->patch("/tasks/{$cudze->id}/status", ['status_id' => $this->status->id])
            ->assertForbidden();

        $this->actingAs($this->anna)
            ->delete("/tasks/{$cudze->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $cudze->id, 'deleted_at' => null]);
    }

    public function test_uzytkownik_z_uprawnieniem_zmieni_wlasne_zadanie(): void
    {
        // Kontrola dla testu wyżej: polityka blokuje cudze, ale nie przeszkadza
        // w pracy nad własnymi zadaniami.
        $this->grantTasksManage($this->anna);

        $moje = $this->makeTask($this->anna);

        $this->actingAs($this->anna)
            ->patch("/tasks/{$moje->id}/status", ['status_id' => $this->status->id])
            ->assertRedirect();

        $this->actingAs($this->anna)
            ->delete("/tasks/{$moje->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('tasks', ['id' => $moje->id]);
    }

    protected function grantTasksManage(User $user): void
    {
        $uprawnienie = \App\Models\Permission::firstOrCreate(
            ['code' => 'tasks_manage'],
            ['name' => 'Zarządzanie zadaniami', 'module' => 'tasks']
        );

        $user->permissions()->syncWithoutDetaching([$uprawnienie->id]);
    }

    public function test_wykonawca_i_wspolpracownik_maja_dostep(): void
    {
        $przypisane = $this->makeTask($this->bartek, $this->anna);
        $this->actingAs($this->anna)->get("/tasks/{$przypisane->id}")->assertOk();

        $wspoldzielone = $this->makeTask($this->bartek);
        $wspoldzielone->collaborators()->attach($this->anna->id);

        $this->actingAs($this->anna)->get("/tasks/{$wspoldzielone->id}")->assertOk();
    }

    public function test_administrator_widzi_wszystko(): void
    {
        $cudze = $this->makeTask($this->bartek);

        $this->actingAs($this->admin)->get("/tasks/{$cudze->id}")->assertOk();

        $response = $this->actingAs($this->admin)->get('/tasks');
        $widoczne = collect($response->viewData('page')['props']['tasks']['data'])->pluck('id');

        $this->assertTrue($widoczne->contains($cudze->id));
    }
}
