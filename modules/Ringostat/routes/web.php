<?php

use Illuminate\Support\Facades\Route;
use Modules\Ringostat\Controllers\RingostatController;

// Webhook BEZ auth (Ringostat POSTuje z zewnątrz) — w produkcji dorobić signature verify
Route::post('/webhook', [RingostatController::class, 'webhook'])
    ->withoutMiddleware(['auth', 'verified', 'web'])
    ->name('webhook');

// Konfiguracja — admin
Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',       [RingostatController::class, 'config'])->name('config');
    Route::post('/credentials', [RingostatController::class, 'saveCredentials'])->name('credentials');
    Route::post('/test',        [RingostatController::class, 'test'])->name('test');
});
