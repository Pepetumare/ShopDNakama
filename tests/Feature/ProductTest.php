<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Support\Price;
use Illuminate\Support\Facades\Storage;
use Tests\StoreTestCase;

class ProductTest extends StoreTestCase
{
    public function test_product_uses_slug_and_shows_persisted_details(): void
    {
        $product = $this->product(['name' => 'Billetera Gran Ola', 'sku' => 'SDN-REAL', 'description' => 'Descripción guardada', 'price' => 14990, 'is_new' => true]);
        $this->get('/producto/billetera-gran-ola')->assertOk()->assertSee('SDN-REAL')->assertSee('Descripción guardada')
            ->assertSee('$14.990')->assertSee('En stock')->assertSee('Nuevo')->assertSee('Shop D. Nakama');
        $this->get('/catalogo')->assertOk()->assertSee(route('producto.show', $product));
        $this->get('/producto/no-existe')->assertNotFound();
        $product->update(['name' => 'Nombre editado']);
        $this->assertSame('billetera-gran-ola', $product->fresh()->slug);
    }

    public function test_only_active_products_from_the_same_category_are_related(): void
    {
        $product = $this->product(['name' => 'Producto principal']);
        for ($i = 1; $i <= 5; $i++) {
            $this->product(['name' => 'Relacionado '.$i]);
        }
        $this->product(['name' => 'Relacionado oculto', 'is_active' => false]);
        $category = Category::create(['name' => 'Otra categoría']);
        $this->product(['name' => 'No relacionado', 'category_id' => $category->id]);
        $response = $this->get(route('producto.show', $product))->assertOk()->assertSee('Relacionado 5')
            ->assertDontSee('Relacionado oculto')->assertDontSee('No relacionado');
        $this->assertSame(4, substr_count($response->getContent(), '<article class="product-card"'));
        $this->assertSame(1, substr_count($response->getContent(), '<header '));
        $this->assertSame(1, substr_count($response->getContent(), '<footer '));
    }

    public function test_inactive_products_and_categories_cannot_be_opened_directly(): void
    {
        $product = $this->product(['is_active' => false]);
        $this->get(route('producto.show', $product))->assertNotFound();
        $product->update(['is_active' => true]);
        $product->category->update(['is_active' => false]);
        $this->get(route('producto.show', $product))->assertNotFound();
    }

    public function test_sale_customization_and_all_stock_states_are_real(): void
    {
        $product = $this->product(['price' => 19990, 'sale_price' => 15990, 'stock' => 3, 'is_customizable' => true]);
        $this->get(route('producto.show', $product))->assertOk()->assertSee('Oferta')->assertSee('$15.990')->assertSee('$19.990')
            ->assertSee('Últimas unidades')->assertSee('3 unidades disponibles')->assertDontSee('¿Quieres personalizarlo?')->assertDontSee('Solicitar personalización');
        $product->update(['stock' => 0]);
        $this->get(route('producto.show', $product))->assertOk()->assertSee('Agotado')->assertSee('0 unidades disponibles');
        $product->update(['stock' => 6, 'sale_price' => 29990]);
        $this->get(route('producto.show', $product))->assertOk()->assertSee('En stock')->assertSee('$19.990')->assertDontSee('$29.990');
        $this->assertSame('$12.990', Price::clp('12990.00'));
    }

    public function test_gallery_orders_primary_images_and_falls_back_for_missing_files(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $this->get(route('producto.show', $product))->assertOk()->assertSee('images/product-placeholder.svg');
        Storage::disk('public')->put('products/primary.svg', file_get_contents(public_path('images/product-placeholder.svg')));
        $product->images()->create(['image' => 'products/missing.webp', 'alt_text' => 'Imagen faltante', 'sort_order' => 0]);
        $product->images()->create(['image' => 'products/primary.svg', 'alt_text' => 'Imagen principal', 'sort_order' => 2, 'is_primary' => true]);
        $this->get(route('producto.show', $product))->assertOk()->assertSee('products/primary.svg')
            ->assertSeeInOrder(['alt="Imagen principal"', 'alt="Imagen faltante"'], false);
        $this->get('/catalogo')->assertOk()->assertSee('products/primary.svg');
        $this->assertTrue($product->images->first()->product->is($product));
    }
}
