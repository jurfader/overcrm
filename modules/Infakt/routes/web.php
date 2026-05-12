<?php

use Illuminate\Support\Facades\Route;
use Modules\Infakt\Controllers\InfaktController;

// Webhook BEZ auth (inFakt POSTuje z zewnatrz). Walidacja zrodla po IP (pula inFakt) — TODO.
Route::post('/webhook', [InfaktController::class, 'webhook'])
    ->withoutMiddleware(['auth', 'verified', 'web'])
    ->name('webhook');

// Konfiguracja — admin
Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',       [InfaktController::class, 'config'])->name('config');
    Route::post('/credentials', [InfaktController::class, 'saveCredentials'])->name('credentials');
    Route::post('/test',        [InfaktController::class, 'test'])->name('test');
});

// PDF faktury — dla wszystkich zalogowanych (jako proxy do API inFakt)
Route::get('/invoices/{uuid}/pdf', [InfaktController::class, 'invoicePdf'])->name('invoice.pdf');
