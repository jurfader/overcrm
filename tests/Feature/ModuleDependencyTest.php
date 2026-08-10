<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zależności modułów oparte o ZDOLNOŚCI, nie o nazwy.
 *
 * Sedno: moduł analizy rozmów deklaruje "capability:telephony" i ma działać
 * z każdą centralą. Gdyby wymagał "playcentrala", klient z Ringostatem albo
 * 3CX nie mógłby go włączyć — i cała uniwersalność byłaby fikcją.
 */
class ModuleDependencyTest extends TestCase
{
    use RefreshDatabase;

    protected ModuleService $modules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modules = app(ModuleService::class);
    }

    /**
     * Migracje zasiewają prawdziwe moduły (kanban, email, inpost…), więc atrapy
     * muszą mieć własną przestrzeń nazw — inaczej test wywala się na unikalności.
     */
    protected function makeModule(string $name, array $attrs = []): Module
    {
        return Module::create(array_merge([
            'name' => 'tst-'.$name,
            'display_name' => ucfirst($name),
            'version' => '1.0.0',
            'is_active' => false,
            'is_core' => false,
        ], $attrs));
    }

    public function test_modul_bez_wymagan_wlacza_sie_normalnie(): void
    {
        $module = $this->makeModule('kanban');

        $result = $this->modules->activate($module);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertTrue($module->fresh()->is_active);
    }

    public function test_brak_wymaganej_zdolnosci_blokuje_wlaczenie(): void
    {
        $ai = $this->makeModule('ai-rozmowy', [
            'requires' => ['capability:telephony', 'capability:ai'],
        ]);

        $result = $this->modules->activate($ai);

        $this->assertFalse($result['success']);
        // Komunikat mówi o ZDOLNOŚCI, nie o nazwie konkretnego modułu.
        $this->assertStringContainsString('moduł telefonii', $result['message']);
        $this->assertFalse($ai->fresh()->is_active);
    }

    public function test_dowolny_modul_wnoszacy_zdolnosc_spelnia_wymaganie(): void
    {
        // Klient ma Ringostat, nie Play Centralę — moduł AI ma się włączyć tak samo.
        $this->makeModule('ringostat', [
            'provides' => ['telephony'],
            'is_active' => true,
        ]);
        $this->makeModule('ai-core', [
            'provides' => ['ai'],
            'is_active' => true,
        ]);

        $ai = $this->makeModule('ai-rozmowy', [
            'requires' => ['capability:telephony', 'capability:ai'],
        ]);

        $result = $this->modules->activate($ai);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function test_dwie_centrale_naraz_sa_blokowane(): void
    {
        $this->makeModule('playcentrala', [
            'display_name' => 'Play Wirtualna Centrala',
            'provides' => ['telephony'],
            'conflicts' => ['capability:telephony'],
            'is_active' => true,
        ]);

        $second = $this->makeModule('ringostat', [
            'display_name' => 'Ringostat',
            'provides' => ['telephony'],
            'conflicts' => ['capability:telephony'],
        ]);

        $result = $this->modules->activate($second);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Play Wirtualna Centrala', $result['message']);
        $this->assertFalse($second->fresh()->is_active);
    }

    public function test_nie_da_sie_wylaczyc_jedynego_dostawcy_potrzebnej_zdolnosci(): void
    {
        $telephony = $this->makeModule('playcentrala', [
            'display_name' => 'Play Wirtualna Centrala',
            'provides' => ['telephony'],
            'is_active' => true,
        ]);
        $this->makeModule('ai-rozmowy', [
            'display_name' => 'Analiza rozmów',
            'requires' => ['capability:telephony'],
            'is_active' => true,
        ]);

        $result = $this->modules->deactivate($telephony);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Analiza rozmów', $result['message']);
        $this->assertTrue($telephony->fresh()->is_active);
    }

    public function test_mozna_wylaczyc_centrale_gdy_druga_dostarcza_te_sama_zdolnosc(): void
    {
        $first = $this->makeModule('playcentrala', [
            'provides' => ['telephony'],
            'is_active' => true,
        ]);
        $this->makeModule('ringostat', [
            'provides' => ['telephony'],
            'is_active' => true,
        ]);
        $this->makeModule('ai-rozmowy', [
            'requires' => ['capability:telephony'],
            'is_active' => true,
        ]);

        // Zdolność zostaje w systemie, więc wyłączenie jednego dostawcy jest bezpieczne.
        $result = $this->modules->deactivate($first);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function test_kaskada_wlacza_wymagane_moduly(): void
    {
        $rdzenAi = $this->makeModule('ai-core', ['display_name' => 'Rdzeń AI', 'provides' => ['ai']]);
        $analiza = $this->makeModule('ai-rozmowy', [
            'display_name' => 'Analiza rozmów',
            'requires' => ['capability:ai'],
        ]);

        // Zwykła aktywacja odmawia — i słusznie, warunek nie jest spełniony.
        $this->assertFalse($this->modules->activate($analiza)['success']);

        $wynik = $this->modules->activateWithDependencies($analiza->fresh());

        $this->assertTrue($wynik['success'], $wynik['message'] ?? '');
        $this->assertStringContainsString('Rdzeń AI', $wynik['message']);
        $this->assertTrue($rdzenAi->fresh()->is_active, 'Wymagany moduł powinien zostać włączony');
        $this->assertTrue($analiza->fresh()->is_active);
    }

    public function test_kaskada_nie_obiecuje_gdy_brakuje_dostawcy(): void
    {
        // Nikt nie wnosi zdolności 'storage-x' — nie ma czego kaskadowo włączyć,
        // więc UI nie może pokazać przycisku, który skończyłby się odmową.
        $modul = $this->makeModule('wymagajacy', ['requires' => ['capability:storage-x']]);

        $this->assertTrue($modul->resolvableRequirements()->isEmpty());
        $this->assertFalse($this->modules->activateWithDependencies($modul)['success']);
    }

    public function test_stare_pole_dependencies_nadal_dziala(): void
    {
        // Moduły w formacie sprzed v2 nie mogą przestać działać po migracji.
        $stale = $this->makeModule('raporty', ['dependencies' => ['tst-leady']]);

        $result = $this->modules->activate($stale);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('wymaga', $result['message']);

        $this->makeModule('leady', ['display_name' => 'Leady', 'is_active' => true]);

        $this->assertTrue($this->modules->activate($stale)['success']);
    }
}
