<?php

use Illuminate\Support\Facades\Route;
use Modules\Fakturownia\Controllers\FakturowniaController;

Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',       [FakturowniaController::class, 'config'])->name('config');
    Route::post('/credentials', [FakturowniaController::class, 'saveCredentials'])->name('credentials');
    Route::post('/test',        [FakturowniaController::class, 'test'])->name('test');
});
