<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Smsapi\Providers\SmsapiNotificationChannel;
use Modules\Smsapi\Services\SmsapiService;
use Tests\TestCase;

/**
 * Kanał SMS przez SMSAPI.
 *
 * Atrapy celują w `api.smsapi.pl`, nie `.com`. To nie jest szczegół: SMSAPI ma
 * dwa rozłączne środowiska, a token z konta polskiego dostaje na .com
 * odmowę autoryzacji 401 (sprawdzone na żywym API 2026-08-11). Wcześniej i kod,
 * i te testy używały .com — moduł nie zadziałałby u żadnego polskiego klienta,
 * a testy świeciły na zielono.
 *
 * Największe ryzyko w tej integracji nie jest techniczne, tylko finansowe:
 * źle znormalizowany numer to SMS wysłany donikąd (zapłacony), a brak
 * `normalize` potraja koszt wiadomości z polskimi znakami. Stąd nacisk testów.
 */
class SmsapiChannelTest extends TestCase
{
    use RefreshDatabase;

    protected SmsapiService $serwis;
    protected SmsapiNotificationChannel $kanal;

    protected function setUp(): void
    {
        parent::setUp();

        // `modules/` jest gitignorowane, więc po świeżym klonie tych klas nie ma.
        // Bez tej bramki test nie „pomija się" tylko czerwieni u każdego, kto
        // akurat nie ma zainstalowanego tego modułu — łącznie z CI.
        if (!class_exists(SmsapiService::class)) {
            $this->markTestSkipped('Moduł Smsapi nie jest zainstalowany.');
        }

        // Niedopasowany wzorzec Http::fake przepuszcza żądanie do PRAWDZIWEGO
        // API — Laravel domyślnie nie blokuje. Objawia się to mylącym błędem
        // autoryzacji zamiast wskazania złej atrapy.
        Http::preventStrayRequests();

        Setting::set('smsapi_token', 'token-testowy', 'smsapi');
        Setting::set('smsapi_sender', 'OVERCRM', 'smsapi');

        $this->serwis = app(SmsapiService::class);
        $this->kanal = new SmsapiNotificationChannel($this->serwis);
    }

    protected function makeUser(?string $telefon): User
    {
        return User::create([
            'name' => 'Anna', 'email' => 'anna'.uniqid().'@example.test',
            'password' => bcrypt('secret123'), 'role' => 'user', 'status' => 'active',
            'phone' => $telefon,
        ]);
    }

    protected function fakeOk(): void
    {
        Http::fake(['api.smsapi.pl/*' => Http::response([
            'count' => 1,
            'list' => [['id' => '1460969715572091219', 'points' => 0.16, 'number' => '48123456789', 'status' => 'QUEUE']],
        ], 200)]);
    }

    /** @dataProvider numeryProvider */
    public function test_normalizacja_numerow(?string $wejscie, ?string $oczekiwane): void
    {
        $this->assertSame($oczekiwane, $this->serwis->normalizeNumber($wejscie));
    }

    public static function numeryProvider(): array
    {
        return [
            'krajowy bez prefiksu' => ['123456789', '48123456789'],
            'ze spacjami' => ['123 456 789', '48123456789'],
            'z myslnikami i plusem' => ['+48 123-456-789', '48123456789'],
            'z zerami zamiast plusa' => ['0048123456789', '48123456789'],
            'juz poprawny' => ['48123456789', '48123456789'],
            'zagraniczny' => ['+49 151 12345678', '4915112345678'],
            'za krotki' => ['12345', null],
            'pusty' => ['', null],
            'null' => [null, null],
            'same znaki' => ['---', null],
        ];
    }

    public function test_kanal_nie_dosiegnie_uzytkownika_bez_numeru(): void
    {
        $this->assertFalse($this->kanal->canReach($this->makeUser(null)));
        $this->assertFalse($this->kanal->canReach($this->makeUser('12345')));
        $this->assertTrue($this->kanal->canReach($this->makeUser('123 456 789')));
    }

    public function test_brak_tokenu_wylacza_kanal(): void
    {
        Setting::set('smsapi_token', '', 'smsapi');

        $this->assertFalse((new SmsapiNotificationChannel(app(SmsapiService::class)))->isAvailable());
    }

    public function test_wysylka_normalizuje_numer_i_dokleja_nadawce(): void
    {
        $this->fakeOk();

        $this->assertTrue($this->kanal->send($this->makeUser('123 456 789'), 'Termin zadania', 'Raport tygodniowy o 12:00'));

        Http::assertSent(function (Request $r) {
            $this->assertSame('48123456789', $r['to']);
            $this->assertSame('OVERCRM', $r['from']);
            $this->assertSame('json', $r['format']);
            // Bez tego wiadomość z polskimi znakami liczy się jako 70 znaków
            // zamiast 160 — ta sama treść kosztuje trzy razy więcej.
            $this->assertSame('1', (string) $r['normalize']);
            // Tytuł staje się pierwszą linią — SMS nie ma tematu.
            $this->assertStringStartsWith('Termin zadania', $r['message']);

            return true;
        });
    }

    public function test_wylaczona_normalizacja_nie_jest_wysylana(): void
    {
        Setting::set('smsapi_normalize', false, 'smsapi');
        $this->fakeOk();

        $this->kanal->send($this->makeUser('123456789'), 'Tytuł', 'Treść');

        Http::assertSent(fn (Request $r) => !isset($r['normalize']));
    }

    public function test_dluga_wiadomosc_jest_przycinana(): void
    {
        $this->fakeOk();

        $this->kanal->send($this->makeUser('123456789'), 'Tytuł', str_repeat('a', 500));

        Http::assertSent(function (Request $r) {
            // Powyżej dwóch segmentów rachunek rośnie szybciej niż wartość
            // powiadomienia, więc kanał tnie treść zamiast płacić za nią.
            $this->assertLessThanOrEqual(320, mb_strlen($r['message']));
            $this->assertStringEndsWith('…', $r['message']);

            return true;
        });
    }

    public function test_blad_w_ciele_odpowiedzi_jest_tlumaczony(): void
    {
        // SMSAPI zwraca błąd w polu `error`, a nie kodem HTTP.
        Http::fake(['api.smsapi.pl/*' => Http::response(['error' => 103, 'message' => 'Insufficient funds'], 200)]);

        $wynik = $this->serwis->send('123456789', 'Test');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('Brak środków', $wynik['message']);
        $this->assertStringContainsString('103', $wynik['message']);
    }

    public function test_nieudana_wysylka_nie_rzuca_wyjatkiem(): void
    {
        Http::fake(['api.smsapi.pl/*' => Http::response(['error' => 101], 401)]);

        // Powiadomienie jest efektem ubocznym — nie może wywrócić operacji,
        // która je wywołała. Kanał ma zwrócić false, nie rzucić.
        $this->assertFalse($this->kanal->send($this->makeUser('123456789'), 'Tytuł', 'Treść'));
    }

    public function test_brak_polaczenia_nie_rzuca_wyjatkiem(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $wynik = $this->serwis->send('123456789', 'Test');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('połączyć', $wynik['message']);
    }

    public function test_test_polaczenia_uzywa_trybu_probnego(): void
    {
        $this->fakeOk();

        $wynik = $this->serwis->testConnection('123456789');

        $this->assertTrue($wynik['ok']);
        $this->assertStringContainsString('opłata nie została naliczona', $wynik['message']);

        // Sedno: `test=1` sprawdza token, nadawcę i numer BEZ wysyłki i kosztu.
        Http::assertSent(fn (Request $r) => (string) $r['test'] === '1');
    }

    public function test_zwykla_wysylka_nie_jest_probna(): void
    {
        $this->fakeOk();

        $this->kanal->send($this->makeUser('123456789'), 'Tytuł', 'Treść');

        // Odwrotność testu wyżej: gdyby `test` wyciekło do zwykłej wysyłki,
        // powiadomienia nigdy by nie dotarły, a wszystko wyglądałoby dobrze.
        Http::assertSent(fn (Request $r) => !isset($r['test']));
    }
}
