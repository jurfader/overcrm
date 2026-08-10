<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\SetupService;
use App\Support\License;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audyt uniwersalności rdzenia.
 *
 * Cała obietnica warstwy zdolności brzmi: **rdzeń działa bez każdego modułu
 * z osobna i bez wszystkich naraz**. To jest stan, w którym startuje KAŻDA nowa
 * instalacja klienta — świeży CRM nie ma jeszcze ani Apilo, ani Fakturowni,
 * ani centrali telefonicznej.
 *
 * Ta obietnica psuje się cicho: wystarczy jeden twardy import klasy z modułu
 * albo `route()` do trasy, której nie ma, i ekran wywala się dopiero u klienta,
 * który akurat danego modułu nie kupił. Testy tutaj celowo wyłączają moduły
 * i sprawdzają, czy rdzeń nadal stoi.
 */
class UniwersalnoscTest extends TestCase
{
    use RefreshDatabase;

    /** Ekrany rdzenia — muszą działać niezależnie od zainstalowanych modułów. */
    protected const EKRANY_RDZENIA = [
        '/dashboard', '/clients', '/clients/create', '/tasks', '/tasks/create',
        '/calendar', '/users', '/statuses', '/admin', '/admin/settings',
        '/admin/products', '/admin/orders', '/admin/marketplace', '/license',
    ];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupService::class)->applyBaseline('sales');
        app(SetupService::class)->complete();
        $this->activateLicense();

        $this->admin = User::create([
            'name' => 'Administrator', 'email' => 'admin@example.test',
            'password' => bcrypt('secret123'), 'role' => 'admin',
            'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

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

    /**
     * Odtwarza stan „modułu nie ma na dysku".
     *
     * Samo ustawienie `is_active = false` w bazie NIE WYSTARCZA i to była
     * pułapka, w którą sam wpadłem: rejestr dostawców wypełniają ServiceProvidery
     * modułów przy starcie aplikacji, więc zmiana flagi w trakcie testu niczego
     * z niego nie usuwa. Test przechodził wtedy z niewłaściwego powodu —
     * celowo zepsuty ekran, który wymagał telefonii, nadal działał, bo w rejestrze
     * wciąż siedziała Play Centrala.
     *
     * Dlatego podmieniamy rejestr na świeży, z samymi dostawcami rdzenia.
     */
    protected function zasymulujBrakModulow(): void
    {
        Module::query()->update(['is_active' => false]);

        $this->app->forgetInstance(ProviderRegistry::class);

        $rejestr = new ProviderRegistry();
        $rejestr->register('product', 'local', \App\Support\Providers\LocalProductProvider::class);
        $rejestr->register('order', 'local', \App\Support\Providers\LocalOrderProvider::class);
        $rejestr->register('invoice', 'none', \App\Support\Providers\NullInvoiceProvider::class);
        $rejestr->register('storage', 'local', \App\Support\Providers\LocalStorageProvider::class);
        $rejestr->register('notification', 'mail', \App\Support\Notifications\MailNotificationChannel::class);

        $this->app->instance(ProviderRegistry::class, $rejestr);
    }

    /**
     * NAJOSTRZEJSZY TEST: rdzeń bez ani jednego modułu.
     *
     * To dokładnie stan świeżej instalacji, zanim klient cokolwiek kupi
     * z marketplace'u. Jeśli tu coś pęka, pęka u każdego nowego klienta.
     */
    public function test_rdzen_dziala_bez_zadnego_modulu(): void
    {
        $this->zasymulujBrakModulow();

        $bledy = [];

        foreach (self::EKRANY_RDZENIA as $ekran) {
            $odpowiedz = $this->actingAs($this->admin)->get($ekran);

            if ($odpowiedz->status() >= 500) {
                $bledy[] = $ekran.' → HTTP '.$odpowiedz->status();
            }
        }

        $this->assertSame([], $bledy,
            "Rdzeń nie działa bez modułów — to stan każdej nowej instalacji:\n".implode("\n", $bledy));
    }

    /**
     * Każdy moduł wyłączony z osobna.
     *
     * Rdzeń bez modułów to jedno; gorsza jest sytuacja, w której moduł A
     * działa tylko dlatego, że przypadkiem jest włączony moduł B.
     */
    public function test_rdzen_dziala_bez_kazdego_pojedynczego_modulu(): void
    {
        $bledy = [];

        foreach (Module::query()->pluck('name') as $nazwa) {
            Module::query()->update(['is_active' => true]);
            Module::where('name', $nazwa)->update(['is_active' => false]);

            foreach (self::EKRANY_RDZENIA as $ekran) {
                $odpowiedz = $this->actingAs($this->admin)->get($ekran);

                if ($odpowiedz->status() >= 500) {
                    $bledy[] = sprintf('bez modulu "%s": %s -> HTTP %d', $nazwa, $ekran, $odpowiedz->status());
                }
            }
        }

        $this->assertSame([], $bledy, implode("\n", $bledy));
    }

    /**
     * Każda zdolność musi mieć bezpieczne zachowanie przy braku dostawcy.
     *
     * `activeOrNull()` istnieje właśnie po to, żeby kod funkcjonalny mógł
     * zapytać „czy ktoś to obsługuje" bez wyjątku. Gdyby któraś kategoria
     * rzucała, rdzeń przestałby być odporny na brak modułu.
     */
    public function test_kazda_zdolnosc_zwraca_null_zamiast_rzucac(): void
    {
        $this->zasymulujBrakModulow();

        $rejestr = app(ProviderRegistry::class);
        $bledy = [];

        foreach ($rejestr->categories() as $kategoria) {
            try {
                $rejestr->activeOrNull($kategoria);
                $rejestr->has($kategoria);
            } catch (\Throwable $e) {
                $bledy[] = "{$kategoria}: ".$e->getMessage();
            }
        }

        $this->assertSame([], $bledy, implode("\n", $bledy));
    }

    /**
     * Rdzeń nie może sięgać po klasy z modułów w sposób NIEZABEZPIECZONY.
     *
     * `modules/` jest gitignorowane, więc u klienta bez danego modułu tych klas
     * po prostu nie ma. Sprawdzone empirycznie, co jest bezpieczne, a co nie:
     *
     *  - samo `use Modules\...` — BEZPIECZNE. W PHP to tylko alias, nie ładuje klasy.
     *  - `Klasa::class` — BEZPIECZNE. Rozwijane w czasie kompilacji do stringa.
     *  - `?Klasa $x = null` w konstruktorze — BEZPIECZNE. Kontener Laravela
     *    podstawia null, gdy klasy nie ma (zweryfikowane sondą).
     *  - `app(Klasa::class)` w try/catch — BEZPIECZNE. Rzuca łapalny
     *    BindingResolutionException, a nie fatal error.
     *  - `new Klasa(...)`, `extends Klasa`, NIEnullowalny type hint — NIEBEZPIECZNE.
     *    Tego kontener ani `catch` nie uratują.
     *
     * Test celuje wyłącznie w ostatnią grupę. Flagowanie samych importów
     * kazałoby usuwać kod, który działa poprawnie.
     */
    public function test_rdzen_nie_siega_po_moduly_w_sposob_niezabezpieczony(): void
    {
        $winowajcy = [];

        foreach (File::allFiles(app_path()) as $plik) {
            if ($plik->getExtension() !== 'php') {
                continue;
            }

            $tresc = File::get($plik->getPathname());
            $sciezka = str_replace(base_path().'/', '', $plik->getPathname());

            // Klasy zaimportowane z modułów — po nich szukamy ryzykownych użyć.
            preg_match_all('/^\s*use\s+Modules\\\\[^;]*\\\\(\w+);/m', $tresc, $trafienia);

            foreach (array_unique($trafienia[1] ?? []) as $klasa) {
                // Bezpośrednia instancjacja — kontener nie ma tu nic do powiedzenia.
                if (preg_match('/new\s+'.$klasa.'\s*\(/', $tresc)) {
                    $winowajcy[] = "{$sciezka}: new {$klasa}() — bezposrednia instancjacja";
                }

                // Dziedziczenie po klasie z modułu — fatal przy ładowaniu pliku.
                if (preg_match('/\b(extends|implements)\s+'.$klasa.'\b/', $tresc)) {
                    $winowajcy[] = "{$sciezka}: dziedziczy po {$klasa}";
                }

                // Wymagany (nienullowalny, bez domyślnego null) parametr typu
                // z modułu — kontener nie zdoła go rozwiazac i rzuci.
                if (preg_match('/function\s+__construct\s*\([^)]*(?<![?\w])'.$klasa.'\s+\$\w+(?!\s*=\s*null)/s', $tresc)) {
                    $winowajcy[] = "{$sciezka}: wymagany parametr konstruktora typu {$klasa}";
                }
            }

            // Bezposrednie wywolanie kontenera na klasie z modulu POZA metoda
            // z try/catch i poza OptionalModule.
            if (preg_match_all('/(?:app|resolve)\(\s*(\w+)::class\s*\)/', $tresc, $wywolania)) {
                foreach (array_unique($wywolania[1]) as $klasa) {
                    if (!in_array($klasa, $trafienia[1] ?? [], true)) {
                        continue;
                    }

                    if (!str_contains($tresc, 'try {') && !str_contains($tresc, 'OptionalModule')) {
                        $winowajcy[] = "{$sciezka}: app({$klasa}::class) bez try/catch i bez OptionalModule";
                    }
                }
            }
        }

        $this->assertSame([], $winowajcy,
            "Rdzen siega po klasy z modulow bez zabezpieczenia. U klienta bez tego modulu "
            ."klasa nie istnieje:\n".implode("\n", $winowajcy));
    }

    /**
     * Rdzeń nie może pytać o moduł po nazwie tam, gdzie chodzi o zdolność.
     *
     * `Module::where('name', 'inpost')` wiąże rdzeń z konkretnym dostawcą.
     * Klient z Furgonetką zamiast InPostu nie dostanie funkcji, którą kupił,
     * mimo że jego moduł tę samą zdolność wnosi.
     */
    public function test_rdzen_pyta_o_zdolnosci_a_nie_o_nazwy_modulow(): void
    {
        $dostawcy = ['inpost', 'apilo', 'fakturownia', 'infakt', 'ringostat',
            'playcentrala', 'wfirma', 'smsapi', 'baselinker', 'furgonetka'];
        $winowajcy = [];

        foreach (File::allFiles(app_path()) as $plik) {
            if ($plik->getExtension() !== 'php') {
                continue;
            }

            $tresc = File::get($plik->getPathname());

            foreach ($dostawcy as $nazwa) {
                // Pytanie o moduł po nazwie w zapytaniu do bazy.
                if (preg_match('/Module::(where|firstWhere)\([\'"]name[\'"],\s*[\'"]'.$nazwa.'[\'"]/i', $tresc)) {
                    $winowajcy[] = sprintf('%s -> pyta o modul "%s" po nazwie', str_replace(base_path().'/', '', $plik->getPathname()), $nazwa);
                }
            }
        }

        $this->assertSame([], $winowajcy,
            "Rdzeń wiąże się z konkretnym dostawcą zamiast pytać o zdolność:\n".implode("\n", $winowajcy));
    }
}
