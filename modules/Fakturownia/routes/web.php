<?php

use Illuminate\Support\Facades\Route;
use Modules\Fakturownia\Controllers\FakturowniaController;

Route::middleware(['role:admin'])->group(function () {
    Route::get('/config',       [FakturowniaController::class, 'config'])->name('config');
    Route::post('/credentials', [FakturowniaController::class, 'saveCredentials'])->name('credentials');
    Route::post('/test',        [FakturowniaController::class, 'test'])->name('test');
});

// Integracja z UI kalendarza (legacy CK endpoints — kazdy zalogowany user)
Route::get('/invoices-by-nip',  [FakturowniaController::class, 'invoicesByNip'])->name('invoices-by-nip');
Route::get('/invoice/{id}',     [FakturowniaController::class, 'invoiceDetail'])->name('invoice-detail');
Route::get('/invoice/{id}/pdf', [FakturowniaController::class, 'invoicePdf'])->name('invoice-pdf');
