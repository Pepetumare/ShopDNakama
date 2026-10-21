<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'index'])->name('home');
Route::get('/catalogo', CatalogController::class)->name('catalogo');
Route::get('/carrito', [\App\Http\Controllers\CartController::class, 'index'])->name('cart.index');
Route::post('/carrito', [\App\Http\Controllers\CartController::class, 'store'])->name('cart.store')->block();
Route::patch('/carrito/{product}', [\App\Http\Controllers\CartController::class, 'update'])->whereNumber('product')->name('cart.update')->block();
Route::delete('/carrito/{product}', [\App\Http\Controllers\CartController::class, 'destroy'])->whereNumber('product')->name('cart.destroy')->block();
Route::get('/checkout', [\App\Http\Controllers\CheckoutController::class, 'index'])->middleware('auth')->name('checkout');
Route::post('/checkout', [\App\Http\Controllers\CheckoutController::class, 'store'])->middleware('auth')->name('checkout.store')->block();
foreach (['success', 'pending', 'failure'] as $result) {
    Route::get('/checkout/mercadopago/'.$result, [\App\Http\Controllers\CheckoutController::class, 'result'])
        ->defaults('result', $result)->name('checkout.mercadopago.'.$result);
}
Route::post('/webhooks/mercadopago', \App\Http\Controllers\MercadoPagoWebhookController::class)->name('webhooks.mercadopago');
Route::get('/producto/{product:slug}', [ProductController::class, 'show'])->name('producto.show');

Route::get('/dashboard', function () {
    return to_route('account.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

require __DIR__.'/account.php';
require __DIR__.'/housekeeping.php';
