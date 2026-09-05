<?php

use App\Http\Controllers\ReceiptImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Foto struk kini disimpan di disk privat 'receipts' (temuan audit #2) dan
// HANYA disajikan lewat route ini untuk PEMILIK expense-nya. Middleware
// Authenticate milik Filament dipakai agar tamu diarahkan ke halaman login
// panel, dan route model binding {expense} otomatis ter-scope
// OwnedByUserScope (expense milik user lain tidak pernah resolve → 404).
Route::get('/receipt-image/{expense}', ReceiptImageController::class)
    ->middleware([\Filament\Http\Middleware\Authenticate::class])
    ->name('receipt-image.show');
