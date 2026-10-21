<?php

use App\Http\Controllers\Housekeeping\CategoryController;
use App\Http\Controllers\Housekeeping\CustomerController;
use App\Http\Controllers\Housekeeping\OrderController;
use App\Http\Controllers\Housekeeping\ProductController;
use App\Http\Controllers\HousekeepingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('housekeeping')->name('housekeeping.')->group(function () {
    Route::get('/', [HousekeepingController::class, 'index'])->name('dashboard');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{user}', [CustomerController::class, 'show'])->name('customers.show');
    Route::resource('categories', CategoryController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::patch('/categories/{category}/status', [CategoryController::class, 'status'])->name('categories.status');
    Route::resource('products', ProductController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::patch('/products/{product}/status', [ProductController::class, 'status'])->name('products.status');
    Route::delete('/products/{product}/images/{image}', [ProductController::class, 'destroyImage'])->scopeBindings()->name('products.images.destroy');
    Route::get('/{module}', [HousekeepingController::class, 'module'])->name('module');
});
