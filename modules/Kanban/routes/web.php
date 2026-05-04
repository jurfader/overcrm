<?php

use Illuminate\Support\Facades\Route;
use Modules\Kanban\Controllers\KanbanController;

Route::middleware(['2fa'])->group(function () {
    Route::get('/', [KanbanController::class, 'index'])->name('index');
});
