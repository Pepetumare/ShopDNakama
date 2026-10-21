<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\CatalogMedia;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductEditor
{
    public function __construct(private CatalogMedia $media) {}

    public function save(Product $product, array $data, array $uploads): Product
    {
        $paths = [];
        try {
            foreach ($uploads as $upload) {
                $paths[] = $this->media->store($upload, 'products');
            }

            return DB::transaction(function () use ($product, $data, $paths) {
                if ($product->exists) {
                    $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                }
                $product->fill(Arr::except($data, ['uploads', 'images_meta', 'primary_image_id']))->save();
                foreach ($data['images_meta'] ?? [] as $image) {
                    $product->images()->whereKey($image['id'])->update(Arr::only($image, ['sort_order', 'alt_text']));
                }
                $nextOrder = ((int) $product->images()->max('sort_order')) + 1;
                foreach ($paths as $path) {
                    $product->images()->create(['image' => $path, 'alt_text' => $product->name, 'sort_order' => $nextOrder++]);
                }
                $primary = $product->images()->whereKey($data['primary_image_id'] ?? null)->first()
                    ?? $product->images()->first();
                $product->images()->update(['is_primary' => false]);
                if ($primary) {
                    $product->images()->whereKey($primary->id)->update(['is_primary' => true]);
                }

                return $product;
            });
        } catch (Throwable $error) {
            foreach ($paths as $path) {
                $this->media->discard($path);
            }
            throw $error;
        }
    }

    public function removeImage(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 404);
        DB::transaction(function () use ($product, $image) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $image->delete();
            $primary = $product->images()->first();
            $product->images()->update(['is_primary' => false]);
            if ($primary) {
                $product->images()->whereKey($primary->id)->update(['is_primary' => true]);
            }
        });
        $this->media->discard($image->image);
    }
}
