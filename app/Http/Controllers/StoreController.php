<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\StorefrontListings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(Request $request, StorefrontListings $listings): View
    {
        $filters = $request->validate(['categoria' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:255']]);
        $categories = Category::storefront()->orderBy('sort_order')->orderBy('name')->get();
        $products = $listings->query($filters, $request->user()?->id, featured: true)
            ->orderByRaw('COALESCE(listings.series_order, 0)')->latest('products.created_at')->orderByDesc('products.id')->limit(6)->get();

        return view('store.home', compact('categories', 'products'));
    }
}
