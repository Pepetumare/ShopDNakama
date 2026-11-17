<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CustomQuoteController;
use App\Http\Controllers\CustomRequestController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'index'])->name('home');
Route::get('/catalogo', CatalogController::class)->name('catalogo');
Route::get('/carrito', [CartController::class, 'index'])->name('cart.index');
Route::post('/carrito', [CartController::class, 'store'])->name('cart.store')->block();
Route::patch('/carrito/{product}', [CartController::class, 'update'])->whereNumber('product')->name('cart.update')->block();
Route::delete('/carrito/{product}', [CartController::class, 'destroy'])->whereNumber('product')->name('cart.destroy')->block();
Route::get('/checkout', [CheckoutController::class, 'index'])->middleware('auth')->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('auth')->name('checkout.store')->block();
foreach (['success', 'pending', 'failure'] as $result) {
    Route::get('/checkout/mercadopago/'.$result, [CheckoutController::class, 'result'])
        ->defaults('result', $result)->name('checkout.mercadopago.'.$result);
}
Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class)->name('webhooks.mercadopago');
Route::get('/producto/{product:slug}', [ProductController::class, 'show'])->name('producto.show');
Route::get('/coleccion/{collection}', CollectionController::class)->name('collections.show');

Route::get('/dashboard', function () {
    return to_route('account.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/personalizados', [CustomRequestController::class, 'create'])->name('custom-requests.create');
Route::middleware('auth')->group(function () {
    Route::get('/personalizados/continuar', [CustomRequestController::class, 'continue'])->name('custom-requests.continue');
    Route::post('/personalizados', [CustomRequestController::class, 'store'])->middleware('throttle:10,1')->name('custom-requests.store');
    Route::get('/personalizados/archivos/{file}', [CustomRequestController::class, 'file'])->name('custom-requests.files.show');
    Route::get('/mi-cuenta/personalizados', [CustomRequestController::class, 'index'])->name('account.custom-requests.index');
    Route::get('/mi-cuenta/personalizados/{customRequest}', [CustomRequestController::class, 'show'])->name('account.custom-requests.show');
    Route::post('/mi-cuenta/personalizados/{customRequest}/accept', [CustomQuoteController::class, 'accept'])->name('account.custom-requests.accept');
    Route::post('/mi-cuenta/personalizados/{customRequest}/decline', [CustomQuoteController::class, 'decline'])->name('account.custom-requests.decline');
});

require __DIR__.'/auth.php';

require __DIR__.'/account.php';
require __DIR__.'/housekeeping.php';
