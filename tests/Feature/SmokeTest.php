<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PriceList;
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
 * Smoke test głównych ekranów: żaden nie może zwrócić 500 na świeżej,
 * skonfigurowanej instalacji. Łapie błędy typu brakujący import w kontrolerze
 * czy zapytanie do nieistniejącej tabeli, które inaczej wychodzą dopiero u klienta.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Stan "po kreatorze": baseline zaseedowany, licencja ważna, setup zamknięty.
        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();

        $this->admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Ustawia stan ważnej licencji. Sam zapis do settings NIE wystarcza —
     * LicenseService::isValid() weryfikuje HMAC stanu (anti-tamper), więc
     * musimy go policzyć tą samą metodą co produkcyjna ścieżka aktywacji.
     */
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

    public static function routeProvider(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'klienci' => ['/clients'],
            'nowy klient' => ['/clients/create'],
            'zadania' => ['/tasks'],
            'nowe zadanie' => ['/tasks/create'],
            'kalendarz' => ['/calendar'],
            'cenniki' => ['/cenniki'],
            'uzytkownicy' => ['/users'],
            'nowy uzytkownik' => ['/users/create'],
            'statusy' => ['/statuses'],
            'changelog' => ['/changelog'],
            'konfiguracja poczty' => ['/settings/mail'],
            'panel admina' => ['/admin'],
            'ustawienia' => ['/admin/settings'],
            'produkty' => ['/admin/products'],
            'zamowienia' => ['/admin/orders'],
            'cenniki admin' => ['/admin/price-lists'],
            'szablony email' => ['/admin/email-templates'],
            'logi integracji' => ['/admin/integration-logs'],
            'licencja' => ['/license'],
        ];
    }

    /**
     * Ekrany szczegółów/edycji — najczęstsze miejsce na brakujący import
     * albo zależność od niezainstalowanego modułu.
     */
    public function test_ekrany_szczegolow_odpowiadaja_bez_bledu_serwera(): void
    {
        $client = Client::create([
            'type' => 'company',
            'name' => 'ACME Sp. z o.o.',
            'nip' => '1234563218',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $task = Task::create([
            'title' => 'Zadanie testowe',
            'status_id' => Status::where('is_default', true)->value('id') ?? Status::value('id'),
            'client_id' => $client->id,
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
            'due_date' => now()->addDay(),
            'priority' => 'medium',
        ]);

        $priceList = PriceList::create([
            'name' => 'Cennik testowy',
            'slug' => 'cennik-testowy',
            'is_active' => true,
            'is_public' => true,
            'html_content' => '<h1>Cennik</h1>',
        ]);

        $paths = [
            "/clients/{$client->id}",
            "/clients/{$client->id}/edit",
            "/tasks/{$task->id}",
            "/tasks/{$task->id}/edit",
            "/users/{$this->admin->id}",
            "/users/{$this->admin->id}/edit",
            "/admin/price-lists/{$priceList->id}/edit",
            '/two-factor/setup',
        ];

        foreach ($paths as $path) {
            $response = $this->actingAs($this->admin)->get($path);

            $this->assertSame(200, $response->getStatusCode(), "Trasa {$path} zwróciła {$response->getStatusCode()}");
        }

        // Publiczny cennik — bez logowania
        $this->get("/cennik/{$priceList->slug}")->assertOk();
    }

    /**
     * @dataProvider routeProvider
     */
    public function test_ekran_odpowiada_bez_bledu_serwera(string $path): void
    {
        $response = $this->actingAs($this->admin)->get($path);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "Trasa {$path} zwróciła {$response->getStatusCode()} "
                .($response->headers->get('Location') ? '→ '.$response->headers->get('Location') : ''),
        );
    }
}
