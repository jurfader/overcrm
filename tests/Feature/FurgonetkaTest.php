<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Furgonetka\Providers\FurgonetkaShippingProvider;
use Modules\Furgonetka\Services\FurgonetkaService;
use Tests\TestCase;

/**
 * Integracja z Furgonetką (REST — SOAP wyłączono w 2023).
 *
 * Testy pilnują pułapek wyciągniętych wprost ze specyfikacji OpenAPI, bo każda
 * z nich daje kod, który wygląda poprawnie i nie działa:
 *  - wyszukiwanie punktów jest PUBLICZNE i nie wolno mu dokleić tokenu,
 *  - odpowiedź punktów to obiekt z kluczem `points`, nie goła tablica,
 *  - `partial_success` NIE jest sukcesem,
 *  - etykieta jest binarna; 204 znaczy „brak etykiety", 500 zwraca JSON,
 *  - lista przewoźników konta i lista przewoźników punktów to RÓŻNE zbiory.
 */
class FurgonetkaTest extends TestCase
{
    use RefreshDatabase;

    protected FurgonetkaService $serwis;

    protected function setUp(): void
    {
        parent::setUp();

        // `modules/` jest gitignorowane, więc po świeżym klonie tych klas nie ma.
        // Bez tej bramki test nie „pomija się" tylko czerwieni u każdego, kto
        // akurat nie ma zainstalowanego tego modułu — łącznie z CI.
        if (!class_exists(FurgonetkaService::class)) {
            $this->markTestSkipped('Moduł Furgonetka nie jest zainstalowany.');
        }

        Cache::flush();

        // Bez tego niedopasowany wzorzec Http::fake przepuszcza żądanie do
        // PRAWDZIWEGO API — testy zaczynają po cichu uderzać w sieć i zwracać
        // mylące błędy autoryzacji zamiast wskazać złe dopasowanie atrapy.
        Http::preventStrayRequests();

        $this->serwis = app(FurgonetkaService::class);
    }

    protected function skonfiguruj(): void
    {
        Setting::set('furgonetka_client_id', 'cid', 'furgonetka');
        Setting::set('furgonetka_client_secret', 'secret', 'furgonetka');
        Setting::set('furgonetka_default_service_id', '42', 'furgonetka');
    }

    protected function fakeToken(): array
    {
        return ['api.furgonetka.pl/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200)];
    }

    // ---------- Punkty: publiczne, bez OAuth ----------

    public function test_wyszukiwanie_punktow_nie_wysyla_tokenu(): void
    {
        $this->skonfiguruj();
        Http::fake(['api.furgonetka.pl/points/map' => Http::response([
            'points' => [['code' => 'GDA114M', 'name' => 'Paczkomat GDA114M', 'service' => 'inpost']],
            'recentlySelectedPoints' => [],
        ], 200)]);

        $punkty = $this->serwis->points(['search_phrase' => 'Gdańsk']);

        $this->assertCount(1, $punkty);

        Http::assertSent(function (Request $r) {
            // Endpoint jest publiczny. Doklejenie tokenu wymusiłoby OAuth tam,
            // gdzie nie jest potrzebny — mapa przestałaby działać przed
            // skonfigurowaniem konta.
            $this->assertEmpty($r->header('Authorization'));

            return true;
        });
    }

    public function test_punkty_rozpakowuja_obiekt_a_nie_gola_tablice(): void
    {
        Http::fake(['api.furgonetka.pl/points/map' => Http::response([
            'points' => [['code' => 'A'], ['code' => 'B']],
            'recentlySelectedPoints' => [['code' => 'C']],
            'coordinates' => ['latitude' => 52.2, 'longitude' => 21.0],
        ], 200)]);

        $punkty = $this->serwis->points(['search_phrase' => 'Warszawa']);

        // Iterowanie odpowiedzi wprost dałoby też `coordinates`
        // i `recentlySelectedPoints` jako „punkty".
        $this->assertCount(2, $punkty);
        $this->assertSame('A', $punkty[0]['code']);
    }

    public function test_brak_lokalizacji_jest_odrzucany_przed_wywolaniem(): void
    {
        Http::fake();

        $this->expectExceptionMessage('lokalizacji');
        $this->serwis->points([]);
        Http::assertNothingSent();
    }

    public function test_domyslnie_pyta_o_wszystkich_obslugiwanych_przewoznikow(): void
    {
        Http::fake(['api.furgonetka.pl/points/map' => Http::response(['points' => []], 200)]);

        $this->serwis->points(['search_phrase' => 'x']);

        Http::assertSent(function (Request $r) {
            // `services` to jedyne pole obowiązkowe w filtrach — pusta lista
            // dałaby 400.
            $this->assertNotEmpty($r['filters']['services']);
            $this->assertContains('inpost', $r['filters']['services']);
            // GLS celowo NIE MA na liście: można do niego nadać przesyłkę,
            // ale jego punktów nie da się wyszukać tym endpointem.
            $this->assertNotContains('gls', $r['filters']['services']);

            return true;
        });
    }

    public function test_awaria_wyszukiwania_zwraca_pustke_zamiast_wyjatku(): void
    {
        Http::fake(['api.furgonetka.pl/points/map' => Http::response(['errors' => []], 400)]);

        // Mapa punktów to element formularza zamówienia — jej awaria nie może
        // wywrócić ekranu, na którym handlowiec pracuje.
        $this->assertSame([], $this->serwis->points(['search_phrase' => 'x']));
    }

    // ---------- OAuth ----------

    public function test_token_jest_cachowany(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/account/services' => Http::response(['services' => []], 200)]);

        $this->serwis->token();
        $this->serwis->token();

        // Token z client_credentials żyje 60 minut — pobieranie go przy każdym
        // wywołaniu podwajałoby liczbę żądań i zjadało limit.
        Http::assertSentCount(1);
    }

    public function test_zle_dane_logowania_daja_czytelny_blad(): void
    {
        $this->skonfiguruj();
        Http::fake(['api.furgonetka.pl/oauth/token' => Http::response(
            ['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401
        )]);

        $this->expectExceptionMessage('Client authentication failed');
        $this->serwis->token();
    }

    // ---------- Przewoźnicy konta ----------

    public function test_mapa_przewoznikow_zachowuje_id_i_kod(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/account/services' => Http::response([
            'services' => [
                ['id' => 42, 'service' => 'inpost', 'name' => 'InPost Paczkomaty', 'owner' => 'furgonetka'],
                ['id' => 77, 'service' => 'dpd', 'name' => 'DPD', 'owner' => 'client'],
            ],
        ], 200)]);

        $mapa = $this->serwis->serviceMap();

        // service_id to liczba i jest LOKALNY dla konta — nie da się go
        // zahardkodować ani wyprowadzić z nazwy przewoźnika.
        $this->assertSame('inpost', $mapa[42]['service']);
        $this->assertSame('client', $mapa[77]['owner']);
    }

    public function test_przewoznik_bez_id_jest_pomijany(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/account/services' => Http::response([
            // Schemat Service nie ma pola `required` — każde pole może nie przyjść.
            'services' => [['service' => 'dhl', 'name' => 'DHL bez id']],
        ], 200)]);

        $this->assertSame([], $this->serwis->serviceMap());
    }

    // ---------- Nadawanie ----------

    public function test_bez_service_id_nadawanie_jest_wylaczone(): void
    {
        Setting::set('furgonetka_client_id', 'cid', 'furgonetka');
        Setting::set('furgonetka_client_secret', 'secret', 'furgonetka');
        // service_id celowo nieustawione

        $provider = app(FurgonetkaShippingProvider::class);

        $this->assertFalse($provider->supportsShipments());
        // ...ale punkty działają nadal, bo nie wymagają konta.
        $this->assertTrue($provider->supportsPointPicking());
        $this->assertTrue($provider->isAvailable());
    }

    public function test_partial_success_nie_jest_traktowany_jak_sukces(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + [
            'api.furgonetka.pl/create-package-command/*' => Http::sequence()
                ->push(['uuid' => 'u1'], 200)
                ->push([
                    'status' => 'partial_success',
                    'package_id' => 500,
                    'errors' => [['message' => 'Brak numeru telefonu odbiorcy']],
                ], 200),
        ]);

        $order = $this->makeOrder();

        try {
            app(FurgonetkaShippingProvider::class)->createShipment($order, ['weight' => 2]);
            $this->fail('Powinien polecieć wyjątek');
        } catch (\RuntimeException $e) {
            // Przepuszczenie partial_success dałoby przesyłkę w niepełnym stanie,
            // która wygląda na nadaną.
            $this->assertStringContainsString('częściowo', $e->getMessage());
            $this->assertStringContainsString('Brak numeru telefonu', $e->getMessage());
        }
    }

    public function test_wysylane_dane_maja_ksztalt_z_specyfikacji(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + [
            'api.furgonetka.pl/create-package-command/*' => Http::sequence()
                ->push(['uuid' => 'u1'], 200)
                ->push(['status' => 'successful', 'package_id' => 900, 'errors' => []], 200),
        ]);

        app(FurgonetkaShippingProvider::class)->createShipment($this->makeOrder(), [
            'weight' => 3.5, 'point' => 'GDA114M',
        ]);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'create-package-command') || $r->method() !== 'PUT') {
                return false;
            }

            // requestBody NIE przyjmuje application/json — wyłącznie wersjonowany
            // media type. Bez tego żądanie zostaje odrzucone.
            $this->assertStringContainsString('vnd.furgonetka.v1+json', $r->header('Content-Type')[0] ?? '');

            $this->assertSame(42, $r['service_id']);
            $this->assertSame('GDA114M', $r['receiver']['point']);
            $this->assertSame(3.5, $r['parcels'][0]['weight']);
            // V1 trzyma `type` na poziomie przesyłki (w V2 przenosi się do paczki).
            $this->assertSame('package', $r['type']);

            return true;
        });
    }

    public function test_numer_zamowienia_jest_przycinany_do_limitu_kuriera(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + [
            'api.furgonetka.pl/create-package-command/*' => Http::sequence()
                ->push(['uuid' => 'u1'], 200)
                ->push(['status' => 'successful', 'package_id' => 1, 'errors' => []], 200),
        ]);

        $order = $this->makeOrder();
        $order->update(['number' => str_repeat('Z', 60)]);

        app(FurgonetkaShippingProvider::class)->createShipment($order->fresh(['client']), []);

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'PUT') {
                return false;
            }

            // Limit zależy od przewoźnika (GLS 25 to najkrótszy) — dłuższy numer
            // wywala nadanie u najbardziej restrykcyjnego.
            $this->assertLessThanOrEqual(25, mb_strlen($r['user_reference_number']));

            return true;
        });
    }

    // ---------- Etykieta ----------

    public function test_brak_etykiety_zwraca_null_a_nie_pusty_plik(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/packages/*' => Http::response('', 204)]);

        // HTTP 204 to poprawna odpowiedź „przesyłka nie ma etykiety".
        // Zapisanie jej dałoby plik zerowej długości udający PDF.
        $this->assertNull($this->serwis->label('123'));
    }

    public function test_blad_generowania_etykiety_nie_jest_zapisywany_jako_pdf(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/packages/*' => Http::response(
            ['errors' => [['message' => 'Error while generating label']]], 500
        )]);

        // HTTP 500 zwraca JSON, nie PDF — ślepy zapis dałby uszkodzoną
        // etykietę z komunikatem błędu w środku.
        $this->expectException(\RuntimeException::class);
        $this->serwis->label('123');
    }

    public function test_etykieta_zachowuje_typ_zwrocony_przez_api(): void
    {
        $this->skonfiguruj();
        Http::fake($this->fakeToken() + ['api.furgonetka.pl/packages/*' => Http::response(
            '%PDF-1.4 udawany', 200, ['Content-Type' => 'application/pdf']
        )]);

        $etykieta = $this->serwis->label('123');

        // O formacie (PDF vs ZPL/EPL) decyduje ustawienie konta, nie parametr
        // żądania — rozszerzenie musi iść z odpowiedzi.
        $this->assertStringStartsWith('%PDF', $etykieta['content']);
        $this->assertStringContainsString('pdf', $etykieta['mime']);
    }

    protected function makeOrder(): \App\Models\Order
    {
        $user = \App\Models\User::create([
            'name' => 'H', 'email' => 'h'.uniqid().'@e.test', 'password' => bcrypt('secret123'),
            'role' => 'user', 'status' => 'active',
        ]);

        $klient = \App\Models\Client::create([
            'name' => 'Klient', 'type' => 'company', 'street' => 'Prosta 1',
            'postal_code' => '80-100', 'city' => 'Gdańsk', 'created_by' => $user->id,
        ]);

        return \App\Models\Order::create([
            'client_id' => $klient->id, 'created_by' => $user->id,
            'number' => 'ZAM/1/2026', 'order_date' => now()->toDateString(), 'status' => 'new',
        ])->fresh(['client']);
    }
}
