<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class StorefrontListings
{
    public function query(array $filters, ?int $userId, bool $featured = false): Builder
    {
        $collections = ProductCollection::published()
            ->join('product_collection_volumes as volumes', 'volumes.product_collection_id', '=', 'product_collections.id')
            ->select(['volumes.product_id as volume_product_id', 'volumes.volume_number', 'volumes.sort_order as volume_order',
                'product_collections.id as series_id', 'product_collections.category_id as series_category_id',
                'product_collections.name as series_name', 'product_collections.sort_order as series_order']);

        $availability = Product::storefront()
            ->join('product_collection_volumes as member', 'member.product_id', '=', 'products.id')
            ->select('member.product_collection_id as availability_series_id')
            ->selectRaw('MAX(CASE WHEN products.stock > 0 THEN member.product_collection_id END) AS stocked_series_id')
            ->selectRaw('MAX(CASE WHEN products.is_featured = 1 THEN 1 ELSE 0 END) AS series_featured')
            ->groupBy('member.product_collection_id');

        $candidates = Product::storefront()
            ->leftJoinSub($collections, 'series', fn (JoinClause $join) => $join->on('series.volume_product_id', '=', 'products.id'))
            ->leftJoinSub($availability, 'availability', fn (JoinClause $join) => $join->on('availability.availability_series_id', '=', 'series.series_id'))
            ->when($featured, fn (Builder $query) => $query->whereRaw('COALESCE(series_featured, products.is_featured) = 1'))
            ->when(filled($filters['q'] ?? null), function (Builder $query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function (Builder $names) use ($term) {
                    $names->where('series_name', 'like', $term)->orWhere('products.name', 'like', $term)
                        ->orWhere('products.sku', 'like', $term);
                });
            });

        $category = $filters['categoria'] ?? null;
        if (filled($category) && $category !== 'ofertas') {
            $candidates->whereIn(DB::raw('COALESCE(series_category_id, products.category_id)'),
                DB::table('categories')->select('id')->where('slug', $category));
        }

        match ($filters['disponibilidad'] ?? null) {
            'stock' => $candidates->where('stock', '>', 0),
            'agotado' => $candidates->where('stock', 0)->whereNull('stocked_series_id'),
            default => null,
        };

        $candidates->storeFilters([
            'precio' => $filters['precio'] ?? null,
            'categoria' => $category === 'ofertas' ? $category : null,
        ]);

        $price = Product::EFFECTIVE_PRICE_SQL;
        $order = match ($filters['orden'] ?? null) {
            'precio-menor' => "$price ASC, ",
            'precio-mayor' => "$price DESC, ",
            'recientes' => 'products.created_at DESC, products.id DESC, ',
            default => '',
        };
        $candidates->select(['products.id', 'series_id', 'series_name', 'series_order', 'stocked_series_id'])
            ->selectRaw("ROW_NUMBER() OVER (
                PARTITION BY series_id, CASE WHEN series_id IS NULL THEN products.id ELSE NULL END
                ORDER BY {$order} CASE WHEN stock > 0 THEN 0 ELSE 1 END, volume_order, volume_number, products.id
            ) AS listing_rank");

        return Product::query()->joinSub($candidates, 'listings', fn (JoinClause $join) => $join->on('listings.id', '=', 'products.id'))
            ->where('listing_rank', 1)
            ->select(['products.*', 'listings.series_id', 'listings.stocked_series_id'])
            ->with(['category', 'images', 'collectionVolume.collection'])
            ->withFavoriteState($userId);
    }
}
