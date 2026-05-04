<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Controllers\ReportsController;

// Raporty widoczne tylko dla adminów
Route::middleware(['role:admin'])->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->name('index');
    Route::get('/margin', [ReportsController::class, 'margin'])->name('margin');
    Route::get('/margin/export', [ReportsController::class, 'export'])->name('margin.export');
});
