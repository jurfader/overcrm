<?php

namespace Tests\Feature;

use App\Jobs\SendVisitEmailJob;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\Setting;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\SetupService;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Wysyłka oferty z wizyty przez kolejkę.
 *
 * Sedno: żądanie HTTP ma wracać NATYCHMIAST z tokenem, a cała ciężka robota
 * (Browsershot, Ghostscript, SMTP) ma dziać się w tle. Wcześniej wszystko szło
 * w trakcie żądania i na większym cenniku przeglądarka czekała kilkadziesiąt
 * sekund, a na hostingu z krótkim limitem czasu żądanie po prostu ginęło.
 */
class VisitEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ClientVisit $wizyta;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();

        $this->user = User::create([
            'name' => 'Handlowiec',
            'email' => 'handlowiec@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $klient = Client::create([
            'name' => 'Klient testowy',
            'type' => 'company',
            'email' => 'klient@example.test',
            'created_by' => $this->user->id,
        ]);

        $this->wizyta = ClientVisit::create([
            'client_id' => $klient->id,
            'user_id' => $this->user->id,
            'title' => 'Spotkanie',
            'visit_date' => now()->toDateString(),
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

    public function test_wysylka_trafia_do_kolejki_a_nie_do_zadania_http(): void
    {
        Queue::fake();

        $odpowiedz = $this->actingAs($this->user)
            ->postJson(route('calendar.send-email', $this->wizyta->id), [
                'subject' => 'Oferta',
                'html_content' => '<p>Treść oferty</p>',
            ]);

        // 202 = przyjęte do realizacji. Wynik pod osobnym adresem.
        $odpowiedz->assertStatus(202)
            ->assertJson(['success' => true, 'queued' => true])
            ->assertJsonStructure(['token', 'status_url']);

        Queue::assertPushed(SendVisitEmailJob::class, function (SendVisitEmailJob $job) {
            return $job->visitId === $this->wizyta->id
                && $job->userId === $this->user->id
                && $job->dane['to_email'] === 'klient@example.test';
        });
    }

    public function test_status_jest_dostepny_zaraz_po_zleceniu(): void
    {
        Queue::fake();

        $token = $this->actingAs($this->user)
            ->postJson(route('calendar.send-email', $this->wizyta->id), [
                'subject' => 'Oferta',
                'html_content' => '<p>Treść</p>',
            ])
            ->json('token');

        $this->actingAs($this->user)
            ->getJson(route('calendar.send-email-status', $token))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'pending']);
    }

    public function test_nieznany_token_zwraca_404(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('calendar.send-email-status', 'token-ktorego-nie-ma'))
            ->assertNotFound()
            ->assertJson(['status' => 'unknown']);
    }

    public function test_zalaczniki_sa_przenoszone_w_trwale_miejsce(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson(route('calendar.send-email', $this->wizyta->id), [
                'subject' => 'Oferta',
                'html_content' => '<p>Treść</p>',
                'attachments' => [UploadedFile::fake()->create('zalacznik.pdf', 50)],
            ])
            ->assertStatus(202);

        Queue::assertPushed(SendVisitEmailJob::class, function (SendVisitEmailJob $job) {
            // Katalog tymczasowy PHP znika z końcem żądania, a zadanie startuje
            // później — plik MUSI już leżeć gdzie indziej i faktycznie istnieć.
            $this->assertCount(1, $job->zalaczniki);
            $this->assertFileExists($job->zalaczniki[0]);
            $this->assertStringContainsString('zalacznik.pdf', $job->zalaczniki[0]);

            return true;
        });
    }

    public function test_brak_tresci_odrzucany_przed_kolejkowaniem(): void
    {
        Queue::fake();

        // Bez szablonu i bez tematu z treścią nie ma czego wysyłać — walidacja
        // musi to złapać ZANIM cokolwiek trafi do kolejki.
        $this->actingAs($this->user)
            ->postJson(route('calendar.send-email', $this->wizyta->id), [])
            ->assertStatus(422);

        // Konkretnie o TO zadanie chodzi. `assertNothingPushed()` byłoby za szerokie:
        // moduł Ringostat dorzuca własne zakolejkowane domknięcie przy każdym
        // zapisie klienta, a klienta tworzymy w setUp.
        Queue::assertNotPushed(SendVisitEmailJob::class);
    }

    public function test_status_zadania_przechodzi_przez_kolejne_stany(): void
    {
        SendVisitEmailJob::zapiszStatus('abc', 'processing', 'Generuję PDF cennika…');
        $this->assertSame('processing', SendVisitEmailJob::status('abc')['status']);

        SendVisitEmailJob::zapiszStatus('abc', 'done', 'Wysłano.', ['sent_email_id' => 7]);
        $stan = SendVisitEmailJob::status('abc');

        $this->assertSame('done', $stan['status']);
        $this->assertSame(7, $stan['sent_email_id']);
    }
}
