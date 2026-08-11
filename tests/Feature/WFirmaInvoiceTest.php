<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\WFirma\Providers\WFirmaInvoiceProvider;
use Modules\WFirma\Services\WFirmaService;
use Tests\TestCase;

/**
 * Integracja z wFirmą.
 *
 * Odpowiedzi w atrapach są odwzorowane 1:1 z dokumentacji wFirmy, bo trzy jej
 * cechy łatwo zaimplementować źle i nie zauważyć:
 *  - kod HTTP zawsze 200, o wyniku mówi `status.code` w treści,
 *  - rekordy w JSON są ZAWSZE numerowane kluczem, nawet gdy jest jeden,
 *  - błędy walidacji przychodzą doklejone do rekordu, nie do gałęzi status.
 */
class WFirmaInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected WFirmaService $serwis;
    protected WFirmaInvoiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // `modules/` jest gitignorowane, więc po świeżym klonie tych klas nie ma.
        // Bez tej bramki test nie „pomija się" tylko czerwieni u każdego, kto
        // akurat nie ma zainstalowanego tego modułu — łącznie z CI.
        if (!class_exists(WFirmaService::class)) {
            $this->markTestSkipped('Moduł WFirma nie jest zainstalowany.');
        }

        // Niedopasowany wzorzec Http::fake przepuszcza żądanie do PRAWDZIWEGO
        // API — Laravel domyślnie nie blokuje. Objawia się to mylącym błędem
        // autoryzacji zamiast wskazania złej atrapy.
        Http::preventStrayRequests();

        Setting::set('wfirma_access_key', 'ak-test', 'wfirma');
        Setting::set('wfirma_secret_key', 'sk-test', 'wfirma');
        Setting::set('wfirma_app_key', 'app-test', 'wfirma');

        $this->serwis = app(WFirmaService::class);
        $this->provider = new WFirmaInvoiceProvider($this->serwis);
    }

    protected function makeOrder(): Order
    {
        $user = User::create([
            'name' => 'Handlowiec', 'email' => 'h@example.test',
            'password' => bcrypt('secret123'), 'role' => 'user', 'status' => 'active',
        ]);

        $client = Client::create([
            'name' => 'Firma Testowa sp. z o.o.', 'type' => 'company',
            'nip' => '1111111111', 'street' => 'Prosta 1', 'postal_code' => '10-100',
            'city' => 'Wrocław', 'email' => 'biuro@example.test', 'created_by' => $user->id,
        ]);

        $order = Order::create([
            'client_id' => $client->id, 'created_by' => $user->id,
            'number' => 'ZAM/1/2026', 'order_date' => now()->toDateString(), 'status' => 'new',
        ]);

        $pozycja = new OrderItem([
            'order_id' => $order->id, 'name' => 'Usługa wdrożeniowa',
            'unit' => 'szt.', 'quantity' => 2, 'price_net' => 500.00, 'vat_rate' => 23,
        ]);
        // Kolumny total_* są NOT NULL i liczy je model, nie baza.
        $pozycja->recalc();
        $pozycja->save();

        return $order->fresh(['items', 'client']);
    }

    public function test_brak_kluczy_wylacza_dostawce(): void
    {
        Setting::set('wfirma_app_key', '', 'wfirma');

        $this->assertFalse((new WFirmaInvoiceProvider(app(WFirmaService::class)))->isAvailable());
    }

    public function test_wystawia_fakture_w_strukturze_wymaganej_przez_wfirme(): void
    {
        Http::fake([
            'api2.wfirma.pl/invoices/add*' => Http::response([
                'invoices' => ['0' => ['invoice' => [
                    'id' => '16679047', 'fullnumber' => 'FV 1/2026',
                ]]],
                'status' => ['code' => 'OK'],
            ], 200),
        ]);

        $wynik = $this->provider->createFromOrder($this->makeOrder());

        $this->assertSame('16679047', $wynik['id']);
        $this->assertSame('FV 1/2026', $wynik['number']);
        $this->assertStringContainsString('/wfirma/invoice/16679047/pdf', $wynik['pdf_url']);

        Http::assertSent(function (Request $r) {
            // Trzy nagłówki naraz — brak któregokolwiek daje 401 AUTH.
            $this->assertSame('ak-test', $r->header('accessKey')[0] ?? null);
            $this->assertSame('sk-test', $r->header('secretKey')[0] ?? null);
            $this->assertSame('app-test', $r->header('appKey')[0] ?? null);

            $faktura = $r->data()['invoices']['0']['invoice'];

            $this->assertSame('normal', $faktura['type']);
            $this->assertSame('1111111111', $faktura['contractor']['nip']);
            $this->assertSame('nip', $faktura['contractor']['tax_id_type']);

            $pozycja = $faktura['invoicecontents']['0']['invoicecontent'];
            $this->assertSame('Usługa wdrożeniowa', $pozycja['name']);
            $this->assertSame('2.0000', $pozycja['count']);
            // Cena z pola price_net zamówienia — NIE unit_price, którego nie ma.
            $this->assertSame('500.00', $pozycja['price']);

            return true;
        });
    }

    /**
     * `price_type` musi jechać jawnie jako `netto`.
     *
     * wFirma interpretuje `price` w pozycjach jako netto ALBO brutto zależnie
     * od tego pola — nie od nazwy pola. Bez niego decydowało domyślne ustawienie
     * konta klienta, więc ta sama instalacja mogła wystawiać poprawne faktury
     * u jednego klienta i zawyżone o VAT u drugiego. Zamówienie niesie ceny
     * netto, więc deklarujemy to wprost.
     */
    public function test_faktura_deklaruje_ceny_jako_netto(): void
    {
        Http::fake(['api2.wfirma.pl/*' => Http::response([
            'invoices' => ['0' => ['invoice' => ['id' => '1', 'fullnumber' => 'FV 3/2026']]],
            'status' => ['code' => 'OK'],
        ], 200)]);

        $this->provider->createFromOrder($this->makeOrder());

        Http::assertSent(function (Request $r) {
            $faktura = $r->data()['invoices']['0']['invoice'];

            $this->assertSame('netto', $faktura['price_type']);
            // Kontrola spójności: skoro deklarujemy netto, w pozycji ma być
            // cena netto z zamówienia, a nie brutto.
            $this->assertSame('500.00', $faktura['invoicecontents']['0']['invoicecontent']['price']);

            return true;
        });
    }

    public function test_klient_bez_nipu_dostaje_tax_id_type_none(): void
    {
        Http::fake(['api2.wfirma.pl/*' => Http::response([
            'invoices' => ['0' => ['invoice' => ['id' => '1', 'fullnumber' => 'FV 2/2026']]],
            'status' => ['code' => 'OK'],
        ], 200)]);

        $order = $this->makeOrder();
        $order->client->update(['nip' => null]);

        $this->provider->createFromOrder($order->fresh(['items', 'client']));

        Http::assertSent(function (Request $r) {
            $kontrahent = $r->data()['invoices']['0']['invoice']['contractor'];

            // Bez jawnego 'none' wFirma odrzuca dokument na walidacji NIP-u.
            $this->assertSame('none', $kontrahent['tax_id_type']);
            $this->assertArrayNotHasKey('nip', $kontrahent);

            return true;
        });
    }

    public function test_blad_w_ciele_odpowiedzi_przerywa_mimo_http_200(): void
    {
        // Sedno: wFirma odpowiada 200 nawet przy odmowie autoryzacji.
        Http::fake(['api2.wfirma.pl/*' => Http::response(['status' => ['code' => 'AUTH']], 200)]);

        $this->expectExceptionMessage('Odrzucona autoryzacja');

        $this->provider->createFromOrder($this->makeOrder());
    }

    public function test_bledy_walidacji_trafiaja_do_komunikatu(): void
    {
        Http::fake(['api2.wfirma.pl/*' => Http::response([
            'invoices' => ['0' => ['invoice' => [
                'errors' => ['0' => ['error' => [
                    'field' => 'date',
                    'message' => 'Data musi być w formacie RRRR-MM-DD',
                ]]],
            ]]],
            'status' => ['code' => 'ERROR'],
        ], 200)]);

        try {
            $this->provider->createFromOrder($this->makeOrder());
            $this->fail('Powinien polecieć wyjątek');
        } catch (\RuntimeException $e) {
            // Sam „ERROR" nie mówi użytkownikowi nic — komunikat musi nieść pole.
            $this->assertStringContainsString('Data musi być w formacie', $e->getMessage());
        }
    }

    public function test_lista_faktur_rozpakowuje_numerowane_galezie(): void
    {
        Http::fake(['api2.wfirma.pl/invoices/find*' => Http::response([
            'invoices' => [
                '0' => ['invoice' => ['id' => '1', 'fullnumber' => 'FV 1/2026', 'total' => '1230.00']],
                '1' => ['invoice' => ['id' => '2', 'fullnumber' => 'FV 2/2026', 'total' => '500.00']],
                'parameters' => ['limit' => 50],
            ],
            'status' => ['code' => 'OK'],
        ], 200)]);

        $faktury = $this->provider->listForClientByNip('111-111-11-11');

        // `parameters` to metadane, nie faktura — nie może trafić na listę.
        $this->assertCount(2, $faktury);
        $this->assertSame('FV 1/2026', $faktury[0]['number']);
        $this->assertSame(1230.00, $faktury[0]['total']);
    }

    public function test_awaria_listy_faktur_nie_wywraca_karty_klienta(): void
    {
        Http::fake(['api2.wfirma.pl/*' => Http::response(['status' => ['code' => 'OUT OF SERVICE']], 200)]);

        // Lista faktur to informacja poboczna — ma zniknąć, nie wysadzić ekranu.
        $this->assertSame([], $this->provider->listForClientByNip('1111111111'));
    }

    public function test_pdf_nie_moze_byc_jsonem_z_bledem(): void
    {
        // Akcja download zwraca plik, więc omija kontrolę status.code. Bez
        // sprawdzenia nagłówka pliku zapisalibyśmy komunikat błędu jako fakturę.
        Http::fake(['api2.wfirma.pl/invoices/download/*' => Http::response(
            json_encode(['status' => ['code' => 'NOT FOUND']]), 200
        )]);

        $this->expectExceptionMessage('Nie znaleziono');

        $this->serwis->downloadInvoicePdf('999');
    }

    public function test_test_polaczenia_zwraca_liste_firm(): void
    {
        Http::fake(['api2.wfirma.pl/user_companies/find*' => Http::response([
            'user_companies' => ['0' => ['user_company' => [
                'company' => ['id' => '702218', 'name' => 'Overmedia', 'altname' => 'Overmedia'],
            ]]],
            'status' => ['code' => 'OK'],
        ], 200)]);

        $wynik = $this->serwis->testConnection();

        $this->assertTrue($wynik['ok']);
        $this->assertSame('702218', $wynik['companies'][0]['id']);
    }

    public function test_test_polaczenia_nie_rzuca_przy_zlych_kluczach(): void
    {
        Http::fake(['api2.wfirma.pl/*' => Http::response(['status' => ['code' => 'AUTH']], 401)]);

        $wynik = $this->serwis->testConnection();

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('accessKey', $wynik['message']);
    }

    public function test_company_id_trafia_do_adresu_gdy_ustawione(): void
    {
        Setting::set('wfirma_company_id', '702218', 'wfirma');
        Http::fake(['api2.wfirma.pl/*' => Http::response(['status' => ['code' => 'OK']], 200)]);

        app(WFirmaService::class)->request('user_companies/find');

        // Brak tego parametru przy koncie wielofirmowym kończy się
        // COMPANY ID REQUIRED albo zapisem do niewłaściwej firmy.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'company_id=702218'));
    }
}
