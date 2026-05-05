<?php

use Illuminate\Support\Facades\Route;
use Modules\DailyReport\Controllers\DailyReportController;

// Tylko admin/manager — sprawdzane przez middleware role + License::guard w kontrolerze
Route::middleware(['role:admin'])->group(function () {
    Route::get('/', [DailyReportController::class, 'index'])->name('index');
});
