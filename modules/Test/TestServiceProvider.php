<?php

namespace Modules\Test;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class TestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rejestracja serwisów modułu
    }

    public function boot(): void
    {
        // Ładowanie routes
        $this->loadRoutes();
        
        // Ładowanie widoków
        $this->loadViewsFrom(__DIR__ . '/resources/views', strtolower('Test'));
        
        // Ładowanie migracji
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    protected function loadRoutes(): void
    {
        Route::middleware('web')
            ->prefix(strtolower('Test'))
            ->name(strtolower('Test') . '.')
            ->group(__DIR__ . '/routes/web.php');
    }
}