<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\StoreDemoSeeder;
use Tests\StoreTestCase;

class StoreModelsTest extends StoreTestCase
{
    public function test_generated_slugs_are_unique_and_category_relationships_work(): void
    {
        $first = $this->product(['name' => 'Figura Samurái']);
        $second = $this->product(['name' => 'Figura Samurái']);
        $this->assertSame('figura-samurai', $first->slug);
        $this->assertSame('figura-samurai-2', $second->slug);
        $this->assertTrue($first->category->products->contains($first));
        $this->assertSame('Manga', $first->category->name);
    }

    public function test_demo_seeder_is_idempotent_and_preserves_existing_values(): void
    {
        $existing = $this->product(['slug' => 'pack-manga', 'name' => 'Mi pack real', 'price' => 12345, 'stock' => 37]);
        $this->seed(StoreDemoSeeder::class);
        $this->seed(StoreDemoSeeder::class);
        $this->assertSame(12, Product::count());
        $this->assertSame(7, Category::count());
        $this->assertSame('Mi pack real', $existing->fresh()->name);
        $this->assertSame('12345.00', $existing->fresh()->price);
        $this->assertSame(37, $existing->fresh()->stock);
        $this->get('/catalogo')->assertOk()->assertSee('Mostrando 8 productos');
        $this->get('/producto/billetera-gran-ola')->assertOk();
    }
}
