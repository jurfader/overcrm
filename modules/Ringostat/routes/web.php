<?php

use Illuminate\Support\Facades\Route;
use Modules\Ringostat\Controllers\RingostatController;

// Webhook BEZ auth (Ringostat POSTuje z zewnątrz) — w produkcji dorobić signature verify
Route::post('/webhook', [RingostatController::class, 'webhook'])
    ->withoutMiddleware(['auth', 'verified', 'web'])
    ->name('webhook');

// Konfiguracja + bulk sync — admin
Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',       [RingostatController::class, 'config'])->name('config');
    Route::post('/credentials', [RingostatController::class, 'saveCredentials'])->name('credentials');
    Route::post('/test',        [RingostatController::class, 'test'])->name('test');
    Route::post('/sync-all',    [RingostatController::class, 'syncAll'])->name('sync-all');
});

// Operacyjne endpointy — kazdy zalogowany user
Route::post('/callback',  [RingostatController::class, 'callback'])->name('callback');
Route::get('/sip-status', [RingostatController::class, 'sipStatus'])->name('sip-status');
Route::get('/calls',      [RingostatController::class, 'listCalls'])->name('calls');         // JSON z Ringostat API
Route::get('/calls-log',  [RingostatController::class, 'callsLog'])->name('calls-log');      // Inertia page (DB)
