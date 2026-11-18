<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\StorefrontListings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __invoke(Request $request, StorefrontListings $listings): View
    {
        $categories = Category::storefront()->orderBy('sort_order')->orderBy('name')->pluck('name', 'slug')->all();
        $prices = ['menos-10000' => 'Menos de $10.000', '10000-30000' => '$10.000 - $30.000', '30000-50000' => '$30.000 - $50.000', 'mas-50000' => 'Más de $50.000'];
        $filters = $request->validate([
            'categoria' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'precio' => ['nullable', 'string'],
            'disponibilidad' => ['nullable', 'string'],
            'orden' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $listings->query($filters, $request->user()?->id);
        match ($filters['orden'] ?? '') {
            'precio-menor' => $query->orderByRaw(Product::EFFECTIVE_PRICE_SQL.' ASC'),
            'precio-mayor' => $query->orderByRaw(Product::EFFECTIVE_PRICE_SQL.' DESC'),
            'nombre' => $query->orderByRaw('COALESCE(listings.series_name, products.name)'),
            'recientes' => $query->latest('products.created_at'),
            default => $query->orderByRaw('COALESCE(listings.series_order, 0)')->latest('products.created_at'),
        };
        $products = $query->orderByDesc('products.id')->paginate(8)->withQueryString();

        return view('store.catalog', compact('products', 'categories', 'prices'));
    }
}
