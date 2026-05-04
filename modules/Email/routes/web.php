<?php

use Illuminate\Support\Facades\Route;
use Modules\Email\Controllers\InboxController;

Route::prefix('inbox')->name('inbox.')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::get('/config/{configId}/message/{uid}', [InboxController::class, 'show'])->name('message');
    Route::post('/send', [InboxController::class, 'send'])->name('send');
});
