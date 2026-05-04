<?php

use Illuminate\Support\Facades\Route;
use Modules\Leads\Controllers\LeadController;
use Modules\Leads\Controllers\LeadSearchController;
use Modules\Leads\Controllers\LeadStatusController;

Route::middleware(['2fa'])->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::get('/stats', [LeadController::class, 'stats'])->name('stats');
    Route::post('/', [LeadController::class, 'store'])->name('store');

    // Wyszukiwanie leadów (scraping + AI) — PRZED {lead} route
    Route::get('/search', [LeadSearchController::class, 'index'])->name('search');
    Route::post('/search', [LeadSearchController::class, 'search'])->name('search.run');
    Route::post('/search/import', [LeadSearchController::class, 'import'])->name('search.import');

    Route::get('/{lead}', [LeadController::class, 'show'])->name('show');
    Route::put('/{lead}', [LeadController::class, 'update'])->name('update');
    Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
    Route::patch('/{lead}/status', [LeadController::class, 'updateStatus'])->name('update-status');
    Route::post('/{lead}/notes', [LeadController::class, 'addNote'])->name('add-note');
    Route::post('/{lead}/convert', [LeadController::class, 'convert'])->name('convert');

    // Admin
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/statuses', [LeadStatusController::class, 'index'])->name('statuses.index');
        Route::post('/statuses', [LeadStatusController::class, 'store'])->name('statuses.store');
        Route::put('/statuses/{status}', [LeadStatusController::class, 'update'])->name('statuses.update');
        Route::delete('/statuses/{status}', [LeadStatusController::class, 'destroy'])->name('statuses.destroy');
        Route::post('/statuses/reorder', [LeadStatusController::class, 'reorder'])->name('statuses.reorder');
    });
});
