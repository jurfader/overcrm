<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\BaseLinker\Providers\BaseLinkerProductProvider;
use Modules\BaseLinker\Services\BaseLinkerService;
use Tests\TestCase;

/**
 * Integracja z BaseLinkerem.
 *
 * Testy skupiają się na trzech rzeczach, które w tym API łatwo przeoczyć,
 * bo przy każdej z nich kod wygląda na działający:
 *  - HTTP jest zawsze 200, o błędzie mówi pole `status` w treści,
 *  - `getOrders` oddaje maks. 100 zamówień, więc bez stronicowania
 *    synchronizacja wsteczna cicho urywa się na setce,
 *  - argumenty jadą jako JSON w stringu pod kluczem `parameters`.
 */
class BaseLinkerTest extends TestCase
{
    use RefreshDatabase;

    protected BaseLinkerService $serwis;

    protected function setUp(): void
    {
        parent::setUp();

        // `modules/` jest gitignorowane, więc po świeżym klonie tych klas nie ma.
        // Bez tej bramki test nie „pomija się" tylko czerwieni u każdego, kto
        // akurat nie ma zainstalowanego tego modułu — łącznie z CI.
        if (!class_exists(BaseLinkerService::class)) {
            $this->markTestSkipped('Moduł BaseLinker nie jest zainstalowany.');
        }

        // Niedopasowany wzorzec Http::fake przepuszcza żądanie do PRAWDZIWEGO
        // API — Laravel domyślnie nie blokuje. Objawia się to mylącym błędem
        // autoryzacji zamiast wskazania złej atrapy.
        Http::preventStrayRequests();

        Setting::set('baselinker_token', '1-23-ABC', 'baselinker');
        $this->serwis = app(BaseLinkerService::class);
    }

    public function test_wywolanie_ma_wlasciwy_ksztalt(): void
    {
        Http::fake(['api.baselinker.com/*' => Http::response(['status' => 'SUCCESS', 'statuses' => []], 200)]);

        $this->serwis->call('getOrderStatusList');

        Http::assertSent(function (Request $r) {
            $this->assertSame('1-23-ABC', $r->header('X-BLToken')[0] ?? null);
            $this->assertSame('getOrderStatusList', $r['method']);
            // Blokada zachowania, nie wymóg API: sprawdzone na żywym API
            // 2026-08-11, że "[]" i brak pola też przechodzą. "{}" jest
            // poprawną reprezentacją pustego zbioru argumentów i nie zależy
            // od tolerancji serwera, więc pilnujemy właśnie jego.
            $this->assertSame('{}', $r['parameters']);

            return true;
        });
    }

    public function test_parametry_ida_jako_json_w_stringu(): void
    {
        Http::fake(['api.baselinker.com/*' => Http::response(['status' => 'SUCCESS', 'orders' => []], 200)]);

        $this->serwis->call('getOrders', ['date_confirmed_from' => 1407341754]);

        Http::assertSent(function (Request $r) {
            // Nie zagnieżdżony formularz — dosłownie JSON w jednym polu.
            $this->assertSame('{"date_confirmed_from":1407341754}', $r['parameters']);

            return true;
        });
    }

    public function test_blad_w_ciele_przerywa_mimo_http_200(): void
    {
        // Sedno: BaseLinker odpowiada 200 nawet przy odmowie.
        Http::fake(['api.baselinker.com/*' => Http::response([
            'status' => 'ERROR',
            'error_code' => 'ERROR_AUTH_TOKEN',
            'error_message' => 'Invalid token',
        ], 200)]);

        $this->expectExceptionMessage('ERROR_AUTH_TOKEN');

        $this->serwis->call('getOrderStatusList');
    }

    public function test_stronicowanie_pobiera_wiecej_niz_setke(): void
    {
        $pelnaStrona = fn (int $od) => collect(range(1, 100))
            ->map(fn (int $i) => ['order_id' => $od + $i, 'date_confirmed' => $od + $i])
            ->all();

        Http::fakeSequence()
            ->push(['status' => 'SUCCESS', 'orders' => $pelnaStrona(1000)], 200)
            ->push(['status' => 'SUCCESS', 'orders' => $pelnaStrona(2000)], 200)
            ->push(['status' => 'SUCCESS', 'orders' => [['order_id' => 9999, 'date_confirmed' => 3000]]], 200);

        $zamowienia = $this->serwis->fetchOrders(0);

        // Bez pętli dostalibyśmy 100 i uznali, że to wszystko.
        $this->assertCount(201, $zamowienia);
    }

    public function test_stronicowanie_konczy_sie_na_krotszej_paczce(): void
    {
        Http::fake(['api.baselinker.com/*' => Http::response([
            'status' => 'SUCCESS',
            'orders' => [['order_id' => 1, 'date_confirmed' => 100]],
        ], 200)]);

        $this->serwis->fetchOrders(0);

        // Paczka krótsza niż limit = ostatnia strona, więc dokładnie jedno wywołanie.
        Http::assertSentCount(1);
    }

    public function test_stronicowanie_nie_zapetla_sie_gdy_kursor_stoi(): void
    {
        // Gdyby API oddawało wciąż tę samą pełną paczkę z niezmienioną datą,
        // pętla po kursorze mieliłaby w nieskończoność.
        $tasama = collect(range(1, 100))->map(fn () => ['order_id' => 1, 'date_confirmed' => 500])->all();

        Http::fake(['api.baselinker.com/*' => Http::response(['status' => 'SUCCESS', 'orders' => $tasama], 200)]);

        $this->serwis->fetchOrders(500);

        Http::assertSentCount(1);
    }

    public function test_produkty_rozpakowuja_mape_id_na_liste(): void
    {
        Setting::set('baselinker_inventory_id', '42', 'baselinker');

        // BaseLinker zwraca produkty jako mapę `id => dane`, nie listę.
        Http::fake(['api.baselinker.com/*' => Http::response([
            'status' => 'SUCCESS',
            'products' => [
                '1001' => ['id' => 1001, 'name' => 'Kurczak mrożony', 'sku' => 'KM-1', 'prices' => ['0' => 19.99], 'stock' => ['bl_1' => 12]],
                '1002' => ['id' => 1002, 'name' => 'Panierka', 'sku' => 'PA-2', 'prices' => ['0' => 8.50], 'stock' => ['bl_1' => 3]],
            ],
        ], 200)]);

        $produkty = (new BaseLinkerProductProvider($this->serwis))->search('kur');

        $this->assertCount(2, $produkty);
        $this->assertSame('Kurczak mrożony', $produkty[0]['name']);
        $this->assertSame(19.99, $produkty[0]['price_net']);
        $this->assertSame(12, $produkty[0]['stock']);
    }

    public function test_brak_katalogu_daje_pusta_liste_zamiast_bledu(): void
    {
        // Bez `inventory_id` nie wiadomo, z którego magazynu czytać. Zgadywanie
        // pokazałoby handlowcowi cudze produkty — lepiej pusto.
        Setting::set('baselinker_inventory_id', '', 'baselinker');

        $this->assertCount(0, (new BaseLinkerProductProvider($this->serwis))->search('cokolwiek'));
        Http::assertNothingSent();
    }

    public function test_awaria_api_nie_wywraca_katalogu_produktow(): void
    {
        Setting::set('baselinker_inventory_id', '42', 'baselinker');
        Http::fake(['api.baselinker.com/*' => Http::response(['status' => 'ERROR', 'error_code' => 'X'], 200)]);

        // Katalog bywa tłem formularza zamówienia — awaria ma go opróżnić,
        // a nie wysadzić ekran, na którym ktoś właśnie pracuje.
        $this->assertCount(0, (new BaseLinkerProductProvider($this->serwis))->search('x'));
    }

    public function test_produkty_sa_tylko_do_odczytu(): void
    {
        // Produkty żyją w BaseLinkerze; dopisywanie ich z CRM rozjechałoby stany.
        $this->assertFalse((new BaseLinkerProductProvider($this->serwis))->supportsManagement());
    }

    public function test_baselinker_nie_jest_dostawca_zamowien(): void
    {
        $rejestr = app(\App\Support\Providers\ProviderRegistry::class);

        // Kontrakt OrderProvider wymaga create(), czyli zakładania zamówienia
        // u dostawcy. BaseLinker działa odwrotnie — zamówienia wpadają do niego
        // z marketplace'ów. Rejestracja jako `order` wywracałaby rdzeń.
        $this->assertArrayHasKey('baselinker', $rejestr->all('product'));
        $this->assertArrayNotHasKey('baselinker', $rejestr->all('order'));
    }

    public function test_test_polaczenia_zwraca_statusy(): void
    {
        Http::fake(['api.baselinker.com/*' => Http::response([
            'status' => 'SUCCESS',
            'statuses' => [['id' => 1, 'name' => 'Nowe'], ['id' => 2, 'name' => 'Wysłane']],
        ], 200)]);

        $wynik = $this->serwis->testConnection();

        $this->assertTrue($wynik['ok']);
        $this->assertCount(2, $wynik['statuses']);
    }

    public function test_test_polaczenia_nie_rzuca_przy_zlym_tokenie(): void
    {
        Http::fake(['api.baselinker.com/*' => Http::response([
            'status' => 'ERROR', 'error_code' => 'ERROR_AUTH_TOKEN', 'error_message' => 'Invalid token',
        ], 200)]);

        $wynik = $this->serwis->testConnection();

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('ERROR_AUTH_TOKEN', $wynik['message']);
    }
}
