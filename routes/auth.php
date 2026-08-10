<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Brak samorejestracji. OVERCRM jest licencjonowany per instalacja — konta zakłada
// administrator w /users, a pierwsze konto powstaje w kreatorze /setup.
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // 2FA challenge (po logowaniu, przed 2fa middleware)
    Route::get('two-factor/challenge', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('two-factor/verify', [TwoFactorController::class, 'verify'])
        ->name('two-factor.verify');

    // 2FA setup (w ustawieniach profilu)
    Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    Route::post('two-factor/enable', [TwoFactorController::class, 'enable'])
        ->name('two-factor.enable');
    Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])
        ->name('two-factor.disable');
});
