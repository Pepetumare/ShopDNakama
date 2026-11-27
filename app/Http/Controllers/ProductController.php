<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\StorefrontListings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Request $request, Product $product, StorefrontListings $listings): View|RedirectResponse
    {
        $product->load(['category', 'images']);
        abort_unless(Product::storefront()->whereKey($product->id)->exists(), 404);
        $volume = $product->collectionVolume;
        if ($volume && ($collection = ProductCollection::published()->find($volume->product_collection_id))) {
            return to_route('collections.show', ['collection' => $collection, 'tomo' => $volume->volume_number]);
        }
        if ($request->user()) {
            $product->loadExists(['favoritedBy as is_favorite' => fn ($users) => $users->whereKey($request->user()->id)]);
        }
        $category = $product->category->name;
        $related = $listings->query(['categoria' => $product->category->slug], $request->user()?->id)
            ->whereKeyNot($product->id)->latest('products.created_at')->orderByDesc('products.id')->limit(4)->get();

        return view('product.show', compact('product', 'category', 'related'));
    }
}
