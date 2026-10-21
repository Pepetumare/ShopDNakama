<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['categoria' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:255']]);
        $categories = Category::storefront()->orderBy('sort_order')->orderBy('name')->get();
        $products = Product::storefront()->with(['category', 'images'])->withFavoriteState($request->user()?->id)->where('is_featured', true)
            ->storeFilters($filters)->latest()->orderByDesc('id')->limit(6)->get();

        return view('store.home', compact('categories', 'products'));
    }
}
