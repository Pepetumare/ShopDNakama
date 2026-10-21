<?php

namespace Tests;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class StoreTestCase extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'manga'], ['name' => 'Manga']);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Manga de prueba',
            'price' => 12990,
            'stock' => 8,
            'is_featured' => true,
        ], $attributes));
    }
}
