<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\SetupService;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guardy, których naruszenie nie objawia się błędem — aplikacja działa dalej,
 * tylko udostępnia to, czego nie powinna. Bez testu regresja wraca niezauważona.
 */
class SecurityGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();
    }

    /** Stan ważnej licencji — HMAC liczony tą samą metodą co produkcyjna aktywacja. */
    protected function activateLicense(): void
    {
        $license = app(LicenseService::class);

        Setting::set('license_key', 'TEST-TEST-TEST-TEST');
        Setting::set('license_status', LicenseService::STATUS_ACTIVE);
        Setting::set('license_expires_at', now()->addYear()->toIso8601String());
        Setting::set('license_plan', 'pro');

        (new ReflectionMethod($license, 'writeStateLock'))->invoke($license);

        License::reset();
    }

    protected function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'@example.test',
            'password' => bcrypt('secret123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_eksport_bazy_klientow_tylko_dla_admina(): void
    {
        $this->actingAs($this->makeUser('user'))
            ->get('/clients/export')
            ->assertForbidden();

        $this->actingAs($this->makeUser('manager'))
            ->get('/clients/export')
            ->assertForbidden();

        $this->actingAs($this->makeUser('admin'))
            ->get('/clients/export')
            ->assertOk();
    }

    public function test_eksport_bazy_klientow_niedostepny_bez_logowania(): void
    {
        $this->get('/clients/export')->assertRedirect('/login');
    }

    public function test_brak_samorejestracji(): void
    {
        // OVERCRM jest licencjonowany per instalacja — konta zakłada admin.
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruz',
            'email' => 'intruz@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruz@example.test']);
        $this->assertFalse(Route::has('register'));
    }
}
