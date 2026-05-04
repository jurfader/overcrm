<?php

use Illuminate\Support\Facades\Route;
use Modules\Ringostat\Controllers\RingostatController;

// Webhook Play Wirtualna Centralka — bez autentykacji Laravel (Basic auth weryfikowana w kontrolerze)
Route::post('/webhook/play-call', [RingostatController::class, 'webhookPlayCall'])
    ->name('webhook.play-call');
