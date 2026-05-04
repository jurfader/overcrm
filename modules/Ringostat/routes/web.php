<?php

use Illuminate\Support\Facades\Route;
use Modules\Ringostat\Controllers\RingostatController;

Route::middleware(['2fa'])->group(function () {
    Route::get('/', [RingostatController::class, 'index'])->name('index');
    Route::get('/stats', [RingostatController::class, 'stats'])->name('stats');
    Route::post('/callback', [RingostatController::class, 'callback'])->name('callback');
    Route::get('/client-calls/{clientId}', [RingostatController::class, 'clientCalls'])->name('client-calls');
    Route::get('/visit-calls/{visitId}', [RingostatController::class, 'visitCalls'])->name('visit-calls');
    Route::get('/daily-report-calls', [RingostatController::class, 'dailyReportCalls'])->name('daily-report-calls');
    Route::get('/recording/{callId}', [RingostatController::class, 'streamRecording'])->name('stream-recording');

    Route::middleware('role:admin')->group(function () {
        Route::post('/sync-calls', [RingostatController::class, 'syncCalls'])->name('sync-calls');
        Route::post('/rematch-calls', [RingostatController::class, 'rematchCalls'])->name('rematch-calls');
        Route::post('/test-connection', [RingostatController::class, 'testConnection'])->name('test-connection');
    });
});
