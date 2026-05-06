<?php

use Illuminate\Support\Facades\Route;
use Modules\Apilo\Controllers\ApiloController;

// Tylko admin — konfiguracja modułu
Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',                [ApiloController::class, 'config'])->name('config');
    Route::post('/credentials',          [ApiloController::class, 'saveCredentials'])->name('credentials');
    Route::post('/authorize',            [ApiloController::class, 'authorize'])->name('authorize');
    Route::post('/refresh',              [ApiloController::class, 'refresh'])->name('refresh');
    Route::post('/test',                 [ApiloController::class, 'test'])->name('test');
});
