<?php

namespace Modules\Reports;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rejestracja serwisów modułu
    }

    public function boot(): void
    {
        // Migracje są ładowane automatycznie przez ModulesServiceProvider
    }
}
