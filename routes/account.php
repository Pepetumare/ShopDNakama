<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountOrderController;
use App\Http\Controllers\FavoriteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('mi-cuenta')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'index'])->name('index');
    Route::get('/datos', [AccountController::class, 'edit'])->name('data');
    Route::patch('/datos', [AccountController::class, 'update'])->name('data.update');
    Route::get('/direcciones', [AccountController::class, 'addresses'])->name('addresses');
    Route::get('/favoritos', [FavoriteController::class, 'index'])->name('favorites');
    Route::get('/pedidos', [AccountOrderController::class, 'index'])->name('orders.index');
    Route::get('/pedidos/{order}', [AccountOrderController::class, 'show'])->name('orders.show');
});

Route::middleware('auth')->prefix('favoritos')->name('favorites.')->group(function () {
    Route::post('/{product:slug}', [FavoriteController::class, 'store'])->name('store');
    Route::delete('/{product:slug}', [FavoriteController::class, 'destroy'])->name('destroy');
});
