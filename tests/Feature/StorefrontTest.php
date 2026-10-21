<?php

namespace Tests\Feature;

use App\Models\Category;
use Tests\StoreTestCase;

class StorefrontTest extends StoreTestCase
{
    public function test_home_contains_the_store_sections_and_only_featured_products(): void
    {
        $this->product(['name' => 'Destacado Nakama']);
        $this->product(['name' => 'Sin destacar', 'is_featured' => false]);
        $this->product(['name' => 'Destacado inactivo', 'is_active' => false]);
        $category = Category::create(['name' => 'Categoría inactiva', 'is_active' => false]);
        $this->product(['name' => 'Destacado oculto', 'category_id' => $category->id]);
        $this->get('/')->assertOk()->assertSee('Shop D. Nakama')->assertSee('Nuestras categorías')
            ->assertSee('Productos destacados')->assertSee('¿Por qué elegir Shop D. Nakama?')
            ->assertSee('Destacado Nakama')->assertDontSee('Sin destacar')->assertDontSee('Destacado inactivo')
            ->assertDontSee('Destacado oculto');
    }

    public function test_category_and_search_filter_featured_products(): void
    {
        $this->product(['name' => 'Manga Aventuras']);
        $category = Category::create(['name' => 'Figuras']);
        $this->product(['name' => 'Figura Nakama', 'category_id' => $category->id]);
        $this->get('/?categoria=manga')->assertOk()->assertSee('Manga Aventuras')->assertDontSee('Figura Nakama');
        $this->get('/?q=Aventuras')->assertOk()->assertSee('Manga Aventuras')->assertDontSee('Figura Nakama');
    }

    public function test_search_supports_an_empty_result_and_escapes_input(): void
    {
        $this->get('/?q=producto-inexistente')->assertOk()->assertSee('No encontramos productos con esos filtros.');
        $this->get('/?q='.urlencode('<script>alert(1)</script>'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
