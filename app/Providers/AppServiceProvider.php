<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('components.store.header', function ($view) {
            $view->with('favoriteCount', request()->user()?->favorites()->count() ?? 0);
            $view->with('cartCount', app(\App\Services\ShoppingCart::class)->lines(request())->sum('quantity'));
        });
    }
}
