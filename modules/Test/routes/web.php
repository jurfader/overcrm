<?php

use Illuminate\Support\Facades\Route;
use Modules\Test\Controllers\TestController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [TestController::class, 'index'])->name('index');
});