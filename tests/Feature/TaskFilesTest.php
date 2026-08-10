<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\SetupService;
use App\Support\License;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Załączniki zadań.
 *
 * Dwie rzeczy nie do ruszenia: plik ma naprawdę wylądować u dostawcy (nie tylko
 * wiersz w bazie), a dostęp do załączników ma być dokładnie taki sam jak do
 * samego zadania — inaczej prywatność zadań z Fazy 1 miałaby obejście.
 */
class TaskFilesTest extends TestCase
{
    use RefreshDatabase;

    protected User $wlasciciel;
    protected User $obcy;
    protected Task $zadanie;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();

        $this->wlasciciel = $this->makeUser('wlasciciel');
        $this->obcy = $this->makeUser('obcy');

        $this->zadanie = Task::create([
            'title' => 'Zadanie z załącznikiem',
            'status_id' => Status::context(Status::CONTEXT_TASK)->where('is_final', false)->value('id'),
            'created_by' => $this->wlasciciel->id,
            'assigned_to' => $this->wlasciciel->id,
            'priority' => 'medium',
        ]);
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

    protected function makeUser(string $nazwa): User
    {
        return User::create([
            'name' => ucfirst($nazwa),
            'email' => $nazwa.'@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_zalacznik_laduje_u_dostawcy_plikow(): void
    {
        Storage::fake('local');

        $this->actingAs($this->wlasciciel)
            ->post(route('tasks.files.store', $this->zadanie->id), [
                'file' => UploadedFile::fake()->create('oferta.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $plik = TaskFile::first();

        $this->assertNotNull($plik, 'Powinien powstać wpis załącznika');
        $this->assertSame('oferta.pdf', $plik->name);
        $this->assertSame('local', $plik->provider);
        $this->assertSame($this->wlasciciel->id, $plik->user_id);

        // Sedno: plik ma NAPRAWDĘ istnieć u dostawcy, nie tylko w bazie.
        Storage::disk('local')->assertExists($plik->external_id);
    }

    public function test_obcy_nie_doda_ani_nie_zobaczy_zalacznika(): void
    {
        Storage::fake('local');

        $this->actingAs($this->obcy)
            ->post(route('tasks.files.store', $this->zadanie->id), [
                'file' => UploadedFile::fake()->create('podrzucone.pdf', 10),
            ])
            ->assertForbidden();

        $this->assertSame(0, TaskFile::count());

        $this->actingAs($this->obcy)
            ->get(route('tasks.files.index', $this->zadanie->id))
            ->assertForbidden();
    }

    public function test_wspolpracownik_ma_dostep_do_zalacznikow(): void
    {
        Storage::fake('local');
        $this->zadanie->collaborators()->attach($this->obcy->id);

        $this->actingAs($this->obcy)
            ->post(route('tasks.files.store', $this->zadanie->id), [
                'file' => UploadedFile::fake()->create('notatka.txt', 5),
            ])
            ->assertRedirect();

        $this->assertSame(1, TaskFile::count());
    }

    public function test_usuniecie_zalacznika_kasuje_plik(): void
    {
        Storage::fake('local');

        $this->actingAs($this->wlasciciel)->post(route('tasks.files.store', $this->zadanie->id), [
            'file' => UploadedFile::fake()->create('do-usuniecia.pdf', 10),
        ]);

        $plik = TaskFile::first();
        $sciezka = $plik->external_id;

        $this->actingAs($this->wlasciciel)
            ->delete(route('tasks.files.destroy', [$this->zadanie->id, $plik->id]))
            ->assertRedirect();

        $this->assertSame(0, TaskFile::count());
        // Dysk lokalny nie kasuje trwale, tylko przenosi do .trash — plik ma
        // zniknąć z pierwotnej ścieżki.
        Storage::disk('local')->assertMissing($sciezka);
    }

    public function test_zalacznik_z_innego_dostawcy_jest_oznaczony_jako_niedostepny(): void
    {
        $plik = TaskFile::create([
            'task_id' => $this->zadanie->id,
            'user_id' => $this->wlasciciel->id,
            'provider' => 'google-drive',
            'external_id' => 'abc123',
            'name' => 'stary.pdf',
        ]);

        // Aktywny jest dysk lokalny, więc pliku z Dysku Google nie mamy czym pobrać.
        $this->assertSame('local', app(ProviderRegistry::class)->activeKey('storage'));
        $this->assertFalse($plik->isReachable());

        // Zamiast wyjątku użytkownik dostaje zrozumiały komunikat.
        $this->actingAs($this->wlasciciel)
            ->get(route('tasks.files.download', [$this->zadanie->id, $plik->id]))
            ->assertRedirect();
    }

    public function test_nie_da_sie_pobrac_pliku_podpietego_pod_inne_zadanie(): void
    {
        Storage::fake('local');

        $inneZadanie = Task::create([
            'title' => 'Inne zadanie',
            'status_id' => $this->zadanie->status_id,
            'created_by' => $this->wlasciciel->id,
            'priority' => 'low',
        ]);

        $plik = TaskFile::create([
            'task_id' => $inneZadanie->id,
            'provider' => 'local',
            'external_id' => 'files/zadania/999/x.pdf',
            'name' => 'x.pdf',
        ]);

        // Identyfikator zadania w adresie musi zgadzać się z właścicielem pliku,
        // inaczej dałoby się obejść politykę, podstawiając własne zadanie.
        $this->actingAs($this->wlasciciel)
            ->get(route('tasks.files.download', [$this->zadanie->id, $plik->id]))
            ->assertNotFound();
    }
}
