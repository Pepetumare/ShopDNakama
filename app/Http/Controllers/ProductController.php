<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Request $request, Product $product): View
    {
        $product->load(['category', 'images']);
        abort_unless(Product::storefront()->whereKey($product->id)->exists(), 404);
        if ($request->user()) {
            $product->loadExists(['favoritedBy as is_favorite' => fn ($users) => $users->whereKey($request->user()->id)]);
        }
        $category = $product->category->name;
        $related = Product::storefront()->with(['category', 'images'])->withFavoriteState($request->user()?->id)->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)->latest()->orderByDesc('id')->limit(4)->get();

        return view('product.show', compact('product', 'category', 'related'));
    }
}
