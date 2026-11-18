<?php

namespace App\Http\Controllers;

use App\Models\ProductCollection;
use App\Models\ProductCollectionVolume;
use App\Support\Price;
use App\Support\StoreImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function __invoke(Request $request, ProductCollection $collection): View
    {
        abort_unless(ProductCollection::published()->whereKey($collection->id)->exists(), 404);
        $collection->load('category');
        $volumes = $collection->volumes()
            ->whereHas('product', fn (Builder $product) => $product->storefront())
            ->with(['product' => fn ($product) => $product->with('images')->withFavoriteState($request->user()?->id)])
            ->orderBy('sort_order')->orderBy('volume_number')->get();
        abort_if($volumes->isEmpty(), 404);

        $requested = $request->integer('tomo');
        $selected = $volumes->firstWhere('volume_number', $requested)
            ?? $volumes->first(fn (ProductCollectionVolume $volume) => $volume->product->stock > 0)
            ?? $volumes->first();

        $details = $volumes->mapWithKeys(function (ProductCollectionVolume $volume) {
            $product = $volume->product;
            $images = $product->images->map(fn ($image) => [
                'url' => StoreImage::url($image->image),
                'alt' => $image->alt_text ?: $product->name,
            ])->all();

            return [$volume->volume_number => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku ?: 'Por confirmar',
                'stock' => $product->stock,
                'availability' => $product->stock_label,
                'price' => Price::clp($product->current_price),
                'previousPrice' => $product->on_sale ? Price::clp($product->price) : null,
                'description' => $product->description ?: $product->short_description,
                'images' => $images ?: [['url' => StoreImage::url(null), 'alt' => $product->name]],
            ]];
        });

        return view('product.collection', [
            'collection' => $collection,
            'volumes' => $volumes,
            'details' => $details,
            'selected' => $selected->volume_number,
            'initial' => $details[$selected->volume_number],
        ]);
    }
}
