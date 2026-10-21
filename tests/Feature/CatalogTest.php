<?php

namespace Tests\Feature;

use App\Models\Category;
use Tests\StoreTestCase;

class CatalogTest extends StoreTestCase
{
    public function test_catalog_only_shows_active_products_in_active_categories(): void
    {
        $this->product(['name' => 'Visible Nakama']);
        $this->product(['name' => 'Producto oculto', 'is_active' => false]);
        $category = Category::create(['name' => 'Categoría oculta', 'is_active' => false]);
        $this->product(['name' => 'Categoría desactivada', 'category_id' => $category->id]);
        $this->get('/catalogo')->assertOk()->assertSee('Visible Nakama')->assertSee('Mostrando 1 productos')
            ->assertDontSee('Producto oculto')->assertDontSee('Categoría desactivada')->assertDontSee('Categoría oculta');
    }

    public function test_category_price_and_availability_filters_use_the_sale_price(): void
    {
        $category = Category::create(['name' => 'Figuras']);
        $this->product(['name' => 'Figura en oferta', 'category_id' => $category->id, 'price' => 59990, 'sale_price' => 24990]);
        $this->product(['name' => 'Figura agotada', 'category_id' => $category->id, 'price' => 24990, 'stock' => 0]);
        $this->product(['name' => 'Manga fuera del filtro', 'price' => 24990]);
        $this->get('/catalogo?categoria=figuras&precio=10000-30000&disponibilidad=stock')
            ->assertOk()->assertSee('Figura en oferta')->assertSee('$24.990')->assertSee('$59.990')
            ->assertDontSee('Figura agotada')->assertDontSee('Manga fuera del filtro');
        $this->get('/catalogo?disponibilidad=agotado')->assertOk()->assertSee('Figura agotada')->assertDontSee('Figura en oferta');
        $this->get('/catalogo?categoria=ofertas')->assertOk()->assertSee('Figura en oferta')->assertDontSee('Figura agotada');
    }

    public function test_all_price_ranges_respect_their_boundaries(): void
    {
        foreach ([9999, 10000, 29999, 30000, 50000, 50001] as $price) {
            $this->product(['name' => 'Precio '.$price, 'price' => $price]);
        }
        $this->get('/catalogo?precio=menos-10000')->assertOk()->assertSee('Precio 9999')->assertDontSee('Precio 10000');
        $this->get('/catalogo?precio=10000-30000')->assertOk()->assertSee('Precio 10000')->assertSee('Precio 29999')->assertDontSee('Precio 30000');
        $this->get('/catalogo?precio=30000-50000')->assertOk()->assertSee('Precio 30000')->assertSee('Precio 50000')->assertDontSee('Precio 50001');
        $this->get('/catalogo?precio=mas-50000')->assertOk()->assertSee('Precio 50001')->assertDontSee('Precio 50000');
    }

    public function test_real_database_sorting_and_search(): void
    {
        $this->product(['name' => 'Alfa Manga', 'price' => 30000, 'sale_price' => 5000, 'created_at' => now()->subDay()]);
        $this->product(['name' => 'Beta Figura', 'price' => 10000]);
        $this->get('/catalogo?orden=precio-menor')->assertOk()->assertSeeInOrder(['Alfa Manga', 'Beta Figura']);
        $this->get('/catalogo?orden=precio-mayor')->assertOk()->assertSeeInOrder(['Beta Figura', 'Alfa Manga']);
        $this->get('/catalogo?orden=nombre')->assertOk()->assertSeeInOrder(['Alfa Manga', 'Beta Figura']);
        $this->get('/catalogo?orden=recientes')->assertOk()->assertSeeInOrder(['Beta Figura', 'Alfa Manga']);
        $this->get('/catalogo?q=Alfa')->assertOk()->assertSee('Alfa Manga')->assertDontSee('Beta Figura');
        $this->get('/catalogo?q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_database_pagination_preserves_filters(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->product(['name' => sprintf('Producto %02d', $i)]);
        }
        $this->get('/catalogo?disponibilidad=stock&orden=nombre')->assertOk()->assertSee('Mostrando 8 productos')
            ->assertSee('disponibilidad=stock&amp;orden=nombre&amp;page=2', false)->assertDontSee('Producto 09');
        $this->get('/catalogo?disponibilidad=stock&orden=nombre&page=2')->assertOk()
            ->assertSee('Mostrando 2 productos')->assertSee('Producto 09')->assertSee('Producto 10')->assertDontSee('Producto 01');
    }

    public function test_empty_catalog_and_invalid_filters_are_handled(): void
    {
        $this->get('/catalogo')->assertOk()->assertSee('No encontramos productos con esos filtros.');
        $this->getJson('/catalogo?categoria[]=manga')->assertUnprocessable();
    }
}
