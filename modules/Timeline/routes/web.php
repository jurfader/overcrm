<?php

use Illuminate\Support\Facades\Route;
use Modules\Timeline\Controllers\TimelineController;

Route::middleware(['2fa'])->group(function () {
    Route::get('/', [TimelineController::class, 'index'])->name('index');
});
