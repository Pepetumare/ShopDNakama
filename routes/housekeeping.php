<?php

use App\Http\Controllers\Housekeeping\CategoryController;
use App\Http\Controllers\Housekeeping\CollectionController;
use App\Http\Controllers\Housekeeping\CustomerController;
use App\Http\Controllers\Housekeeping\CustomRequestController;
use App\Http\Controllers\Housekeeping\OrderController;
use App\Http\Controllers\Housekeeping\PaymentReconciliationController;
use App\Http\Controllers\Housekeeping\ProductController;
use App\Http\Controllers\Housekeeping\StockController;
use App\Http\Controllers\HousekeepingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('housekeeping')->name('housekeeping.')->group(function () {
    Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
    Route::post('/stock/returns', [StockController::class, 'restore'])->name('stock.restore');
    Route::get('/stock/{product}', [StockController::class, 'show'])->name('stock.show');
    Route::post('/stock/{product}/movements', [StockController::class, 'store'])->name('stock.store');
    Route::patch('/stock/{product}/minimum', [StockController::class, 'minimum'])->name('stock.minimum');
    Route::get('/', [HousekeepingController::class, 'index'])->name('dashboard');
    Route::get('/payments', [PaymentReconciliationController::class, 'index'])->name('payments.index');
    Route::get('/orders/{order}/payments', [PaymentReconciliationController::class, 'show'])->name('payments.show');
    Route::post('/orders/{order}/fulfillment-resolution', [PaymentReconciliationController::class, 'resolveFulfillment'])->name('orders.resolve-fulfillment');
    Route::post('/orders/{order}/payments/review', [PaymentReconciliationController::class, 'review'])->middleware('throttle:10,1')->name('payments.review');
    Route::post('/payments/{payment}/observations', [PaymentReconciliationController::class, 'annotate'])->name('payments.annotate');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{user}', [CustomerController::class, 'show'])->name('customers.show');
    Route::resource('categories', CategoryController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::patch('/categories/{category}/status', [CategoryController::class, 'status'])->name('categories.status');
    Route::resource('products', ProductController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('collections', CollectionController::class)->except(['show', 'destroy']);
    Route::post('/collections/{collection}/volumes', [CollectionController::class, 'storeVolume'])->name('collections.volumes.store');
    Route::patch('/collections/{collection}/volumes/{volume}', [CollectionController::class, 'updateVolume'])->scopeBindings()->name('collections.volumes.update');
    Route::delete('/collections/{collection}/volumes/{volume}', [CollectionController::class, 'destroyVolume'])->scopeBindings()->name('collections.volumes.destroy');
    Route::patch('/products/{product}/status', [ProductController::class, 'status'])->name('products.status');
    Route::delete('/products/{product}/images/{image}', [ProductController::class, 'destroyImage'])->scopeBindings()->name('products.images.destroy');
    Route::get('/personalizados', [CustomRequestController::class, 'index'])->name('custom-requests.index');
    Route::get('/personalizados/{customRequest}', [CustomRequestController::class, 'show'])->name('custom-requests.show');
    Route::patch('/personalizados/{customRequest}', [CustomRequestController::class, 'update'])->name('custom-requests.update');
    Route::get('/{module}', [HousekeepingController::class, 'module'])->name('module');
});
