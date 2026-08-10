<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Higiena modułów — pilnuje klasy błędów, które NIE objawiają się wyjątkiem
 * podczas testów ani przy zwykłym klikaniu, tylko psują instalację klienta po cichu.
 *
 * Każda asercja tutaj odpowiada realnej awarii znalezionej w tym repo:
 *  - trasa w manifeście, której nie ma → pozycja menu znika bez śladu
 *  - komenda w harmonogramie, której nie ma → cron wykłada się co 15 minut
 *  - moduł czytający ustawienia spod cudzej nazwy → panel zapisuje w próżnię
 *  - moduł używający nazw innego dostawcy → nikt nie wie, co do kogo należy
 */
class ModuleHygieneTest extends TestCase
{
    use RefreshDatabase;

    /** Foldery modułów obecne na dysku. */
    protected function moduleDirs(): array
    {
        $path = base_path('modules');

        return File::exists($path) ? File::directories($path) : [];
    }

    protected function manifest(string $dir): ?array
    {
        $file = $dir.'/module.json';

        return File::exists($file) ? json_decode(File::get($file), true) : null;
    }

    /**
     * `settings` w manifeście muszą być ZGRUPOWANE: settings → grupa → klucz → definicja.
     *
     * `ModuleService::registerModuleSettings()` iteruje po dwóch poziomach. Przy
     * płaskiej strukturze (settings → klucz → definicja) potraktuje nazwę
     * ustawienia jako nazwę grupy, a jego pola — jako nazwy ustawień, i utworzy
     * wiersze o kluczach „label" i „type". Moduł wygląda wtedy na skonfigurowany,
     * a nie ma ani jednego prawdziwego ustawienia.
     */
    public function test_settings_w_manifestach_sa_zgrupowane(): void
    {
        $bledy = [];

        foreach ($this->moduleDirs() as $dir) {
            $manifest = $this->manifest($dir);
            $nazwa = basename($dir);

            foreach ($manifest['settings'] ?? [] as $grupa => $ustawienia) {
                if (!is_array($ustawienia)) {
                    $bledy[] = sprintf('%s: grupa "%s" nie jest obiektem', $nazwa, $grupa);
                    continue;
                }

                foreach ($ustawienia as $klucz => $definicja) {
                    if (!is_array($definicja)) {
                        $bledy[] = sprintf(
                            '%s: "%s.%s" wyglada na plaska strukture (settings > klucz > definicja zamiast settings > grupa > klucz > definicja)',
                            $nazwa, $grupa, $klucz
                        );
                    }
                }
            }
        }

        $this->assertSame([], $bledy, "Nieprawidłowa struktura settings:\n".implode("\n", $bledy));
    }

    /**
     * Opis ustawienia musi siedzieć w polu `description`.
     *
     * Loader czyta wyłącznie `description`; `help` jest po cichu gubione, więc
     * podpowiedź, gdzie znaleźć klucz API, nigdy nie dociera do administratora.
     */
    public function test_opisy_ustawien_uzywaja_pola_description(): void
    {
        $bledy = [];

        foreach ($this->moduleDirs() as $dir) {
            $manifest = $this->manifest($dir);
            $nazwa = basename($dir);

            foreach ($manifest['settings'] ?? [] as $grupa => $ustawienia) {
                if (!is_array($ustawienia)) {
                    continue;
                }

                foreach ($ustawienia as $klucz => $definicja) {
                    if (is_array($definicja) && isset($definicja['help'])) {
                        $bledy[] = sprintf('%s: "%s.%s" uzywa pola help zamiast description', $nazwa, $grupa, $klucz);
                    }
                }
            }
        }

        $this->assertSame([], $bledy, implode("\n", $bledy));
    }

    public function test_trasy_z_manifestow_istnieja(): void
    {
        $brakujace = [];

        foreach ($this->moduleDirs() as $dir) {
            $manifest = $this->manifest($dir);

            if (!$manifest) {
                continue;
            }

            $nazwa = basename($dir);

            foreach ($manifest['menu'] ?? [] as $pozycja) {
                $trasa = $pozycja['route'] ?? null;

                if ($trasa && !Route::has($trasa)) {
                    $brakujace[] = "{$nazwa}: menu → {$trasa}";
                }
            }

            $config = $manifest['config_route'] ?? null;

            if ($config && !Route::has($config)) {
                $brakujace[] = "{$nazwa}: config_route → {$config}";
            }
        }

        $this->assertSame([], $brakujace,
            "Manifest wskazuje na nieistniejące trasy. Pozycja menu po prostu nie pojawi się "
            ."w interfejsie, bez żadnego komunikatu:\n".implode("\n", $brakujace));
    }

    public function test_komendy_z_harmonogramu_istnieja(): void
    {
        $zarejestrowane = array_keys(Artisan::all());
        $brakujace = [];

        foreach (app(\Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
            // Interesują nas tylko wpisy uruchamiające komendę artisan.
            if (!preg_match('/artisan[\'"]?\s+(\S+)/', $event->command ?? '', $m)) {
                continue;
            }

            $komenda = trim($m[1], '\'"');

            if ($komenda !== '' && !in_array($komenda, $zarejestrowane, true)) {
                $brakujace[] = $komenda;
            }
        }

        $this->assertSame([], $brakujace,
            "Harmonogram wskazuje na nieistniejące komendy — cron będzie się wykładał "
            ."w tle bez śladu w interfejsie:\n".implode("\n", $brakujace));
    }

    public function test_moduly_telefonii_nie_mieszaja_nazewnictwa(): void
    {
        // Play Centrala powstała jako fork integracji z Ringostatem i przez długi
        // czas używała jego nazw dla własnych klas, tabel i tras. Efektem były
        // trasy wołane pod złą nazwą i ustawienia zapisywane do innego kubełka.
        $play = base_path('modules/PlayCentrala');

        if (!File::exists($play)) {
            $this->markTestSkipped('Moduł PlayCentrala nie jest zainstalowany.');
        }

        $winowajcy = [];

        foreach (File::allFiles($play) as $plik) {
            if ($plik->getExtension() !== 'php') {
                continue;
            }

            // Migracje są historyczne — ich nazw nie wolno zmieniać wstecz.
            if (str_contains($plik->getPathname(), '/database/migrations/')) {
                continue;
            }

            $tresc = File::get($plik->getPathname());

            // Nazwy klas i tras należące do innego dostawcy.
            if (preg_match('/\b(RingostatService|RingostatCall|RingostatController)\b/', $tresc)
                || preg_match('/route\([\'"]ringostat\./', $tresc)
                || preg_match('/[\'"]ringostat:[a-z-]+/', $tresc)) {
                $winowajcy[] = str_replace(base_path().'/', '', $plik->getPathname());
            }
        }

        $this->assertSame([], $winowajcy,
            "Moduł Play Centrali używa nazw należących do Ringostata:\n".implode("\n", $winowajcy));
    }

    public function test_moduly_czytaja_ustawienia_z_wlasnego_kubelka(): void
    {
        $obcy = [];

        foreach ($this->moduleDirs() as $dir) {
            $nazwaModulu = strtolower(basename($dir));

            foreach (File::allFiles($dir) as $plik) {
                if ($plik->getExtension() !== 'php' || str_contains($plik->getPathname(), '/database/migrations/')) {
                    continue;
                }

                // Trzeci argument Setting::get/set to moduł. 'core' jest dozwolone —
                // to wspólny kubełek rdzenia.
                preg_match_all(
                    '/Setting::(?:get|set)\([^)]*?,\s*[\'"]([a-z0-9_-]+)[\'"]\s*\)/i',
                    File::get($plik->getPathname()),
                    $trafienia
                );

                foreach ($trafienia[1] ?? [] as $kubelek) {
                    if ($kubelek !== 'core' && $kubelek !== $nazwaModulu) {
                        $obcy[] = str_replace(base_path().'/', '', $plik->getPathname())." → '{$kubelek}'";
                    }
                }
            }
        }

        $this->assertSame([], $obcy,
            "Moduł czyta lub zapisuje ustawienia pod nazwą innego modułu. Panel zapisuje "
            ."pod nazwę modułu, więc taki odczyt zawsze zwróci pustą wartość:\n".implode("\n", $obcy));
    }
}
