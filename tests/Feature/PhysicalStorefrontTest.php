<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Tests\StoreTestCase;

class PhysicalStorefrontTest extends StoreTestCase
{
    public function test_services_are_hidden_from_public_pages_without_changing_records(): void
    {
        $physical = $this->product(['name' => 'Figura de colección', 'is_customizable' => true]);
        foreach (['impresion-3d' => 'Impresión 3D', 'estampados' => 'Estampados'] as $slug => $name) {
            $category = Category::create(compact('slug', 'name'));
            $service = $this->product(['name' => 'Servicio '.$slug, 'category_id' => $category->id, 'is_customizable' => true]);
            foreach (['/', '/catalogo', '/catalogo?categoria='.$slug] as $url) {
                $this->get($url)->assertOk()->assertDontSee($service->name)->assertDontSee($name);
            }
            $this->get(route('producto.show', $service))->assertNotFound();
            $this->assertTrue($service->fresh()->is_active);
            $this->assertTrue($service->fresh()->is_customizable);
            $this->assertTrue($category->fresh()->is_active);
        }

        $service = $this->product(['name' => 'Encargo exclusivo', 'product_type' => ' Servicio Personalizado ']);
        $this->get('/catalogo')->assertOk()->assertSee($physical->name)->assertDontSee($service->name);
        $this->get(route('producto.show', $service))->assertNotFound();
        $this->get(route('producto.show', $physical))->assertOk()
            ->assertDontSee('Personalizable')->assertDontSee('Solicitar personalización');
        $this->get('/')->assertOk()->assertDontSee('Crea algo único')->assertDontSee('cotización');
    }

    public function test_navigation_uses_real_category_names_and_slugs(): void
    {
        Category::create(['name' => 'Figuras', 'slug' => 'figuras-anime']);
        Category::create(['name' => 'Cartas Madera', 'slug' => 'cartas-pokemon-madera']);
        $this->get('/')->assertOk()->assertSee('Cartas Madera')
            ->assertSee(route('catalogo', ['categoria' => 'figuras-anime']))
            ->assertSee(route('catalogo', ['categoria' => 'cartas-pokemon-madera']));
    }

    public function test_admin_form_hides_customization_and_preserves_legacy_flag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = $this->product(['is_customizable' => true]);
        $this->get(route('housekeeping.products.edit', $product))->assertOk()
            ->assertDontSee('name="is_customizable"', false)->assertDontSee('Impresión 3D')->assertDontSee('Estampados');
        $this->put(route('housekeeping.products.update', $product), [
            'name' => 'Manga actualizado', 'category_id' => $product->category_id,
            'price' => 12990, 'stock' => 5, 'is_active' => 1, 'is_featured' => 1, 'is_new' => 0,
            'is_customizable' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->is_customizable);
        $this->assertSame('Manga actualizado', $product->fresh()->name);
        $this->get('/housekeeping/impresion-3d')->assertNotFound();
        $this->get('/housekeeping/estampados')->assertNotFound();
    }
}
