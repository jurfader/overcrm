<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trasy publiczne — bez logowania.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_jest_przekierowany_ze_strony_glownej_do_logowania(): void
    {
        // '/' nie renderuje landing page'a — od razu kieruje do logowania
        // (albo do dashboardu dla zalogowanych), patrz routes/web.php.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_ekran_logowania_sie_renderuje(): void
    {
        $this->get('/login')->assertOk();
    }
}
