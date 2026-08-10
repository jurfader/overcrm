<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Services\SetupService;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Kreator pierwszego uruchomienia (/setup) — blokada aplikacji, uprawnienia
 * i idempotentność danych startowych.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migracje zostawiają setup_completed = '0' → stan świeżej instalacji.
        License::reset();
    }

    protected function admin(): User
    {
        return User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function regularUser(): User
    {
        return User::create([
            'name' => 'Handlowiec',
            'email' => 'user@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_swieza_instalacja_startuje_z_nieukonczonym_setupem(): void
    {
        $this->assertFalse(app(SetupService::class)->isComplete());
    }

    public function test_admin_jest_przekierowany_na_kreator(): void
    {
        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertRedirect(route('setup.show'));
    }

    public function test_kreator_jest_dostepny_mimo_braku_licencji(): void
    {
        // EnforceLicense musi przepuszczać /setup — inaczej krok "Licencja"
        // byłby nieosiągalny na świeżej instalacji.
        $response = $this->actingAs($this->admin())->get('/setup');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Setup/Index')
            ->has('setup.steps', count(SetupService::STEPS))
            ->has('setup.license')
            ->has('setup.baseline.presets', count(SetupService::STATUS_PRESETS))
            // marketplace to prop opcjonalny — nie może być w pierwszym renderze,
            // bo to zapytanie HTTP do license servera.
            ->missing('marketplace'));
    }

    public function test_zwykly_uzytkownik_widzi_ekran_oczekiwania_zamiast_kreatora(): void
    {
        $response = $this->actingAs($this->regularUser())->get('/setup');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Setup/Pending'));
    }

    public function test_zwykly_uzytkownik_nie_moze_zapisac_krokow_kreatora(): void
    {
        $response = $this->actingAs($this->regularUser())
            ->post(route('setup.company'), ['company_name' => 'Przejęta firma']);

        $response->assertForbidden();
        $this->assertNull(Setting::get('company_name'));
    }

    public function test_dane_startowe_tworza_statusy_i_uprawnienia(): void
    {
        // Preset opisuje obieg SPOTKAŃ. Statusy zadań są stałym zestawem
        // zakładanym migracją i nie zależą od wyboru klienta w kreatorze,
        // dlatego liczymy wyłącznie kontekst kalendarza.
        $this->assertSame(0, Status::context(Status::CONTEXT_CALENDAR)->count());
        $this->assertSame(3, Status::context(Status::CONTEXT_TASK)->count());

        $response = $this->actingAs($this->admin())
            ->post(route('setup.baseline'), ['preset' => 'minimal']);

        $response->assertRedirect();
        $this->assertSame(3, Status::context(Status::CONTEXT_CALENDAR)->count());
        $this->assertSame(count(SetupService::PERMISSIONS), Permission::count());
        $this->assertTrue(Status::context(Status::CONTEXT_CALENDAR)->where('is_default', true)->exists());
    }

    public function test_ponowne_uruchomienie_danych_startowych_nie_duplikuje_wierszy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('setup.baseline'), ['preset' => 'sales']);
        $statusesAfterFirst = Status::count();

        $this->actingAs($admin)->post(route('setup.baseline'), ['preset' => 'sales']);

        $this->assertSame($statusesAfterFirst, Status::count());
        $this->assertSame(count(SetupService::PERMISSIONS), Permission::count());
    }

    public function test_krok_opcjonalny_mozna_pominac_a_obowiazkowego_nie(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('setup.skip'), ['step' => 'company']);
        $this->assertSame('skipped', app(SetupService::class)->progress()['company'] ?? null);

        $this->actingAs($admin)->post(route('setup.skip'), ['step' => 'license']);
        $this->assertArrayNotHasKey('license', app(SetupService::class)->progress());
    }

    public function test_zapis_danych_firmy_trafia_do_ustawien_i_brandu(): void
    {
        $this->actingAs($this->admin())->post(route('setup.company'), [
            'company_name' => 'ACME Sp. z o.o.',
            'company_nip' => '1234563218',
            'company_city' => 'Warszawa',
        ]);

        $this->assertSame('ACME Sp. z o.o.', Setting::get('company_name'));
        $this->assertSame('Warszawa', Setting::get('company_city'));
        $this->assertSame('ACME Sp. z o.o.', Setting::get('brand_company_name', null, 'branding'));
        $this->assertSame('done', app(SetupService::class)->progress()['company'] ?? null);
    }

    public function test_zakonczenie_kreatora_zdejmuje_blokade(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('setup.complete'))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(app(SetupService::class)->isComplete());

        // Po zakończeniu setupu blokadę przejmuje już tylko licencja —
        // /dashboard nie może wracać do kreatora.
        $response = $this->actingAs($admin)->get('/dashboard');
        $this->assertNotSame(route('setup.show'), $response->headers->get('Location'));
    }

    public function test_ponowne_uruchomienie_kreatora_przywraca_blokade(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('setup.complete'));
        $this->assertTrue(app(SetupService::class)->isComplete());

        $this->actingAs($admin)->post(route('setup.restart'))
            ->assertRedirect(route('setup.show'));

        $this->assertFalse(app(SetupService::class)->isComplete());
        $this->assertSame([], app(SetupService::class)->progress());
    }

    public function test_aktywacja_licencji_nie_oznacza_setupu_jako_ukonczonego(): void
    {
        // Regresja: LicenseService ustawiał setup_completed = '1' po aktywacji,
        // co po cichu pomijało całą konfigurację instalacji.
        Setting::set('license_status', 'active');
        Setting::set('license_expires_at', now()->addYear()->toIso8601String());

        $this->assertFalse(app(SetupService::class)->isComplete());
    }
}
