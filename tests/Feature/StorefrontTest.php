<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontTest extends TestCase
{
    public function test_home_contains_the_store_sections(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Shop D. Nakama')
            ->assertSee('Nuestras categorías')
            ->assertSee('Productos destacados')
            ->assertSee('Impresión 3D')
            ->assertSee('Estampados')
            ->assertSee('¿Por qué elegir Shop D. Nakama?');
    }

    public function test_category_filters_the_sample_products(): void
    {
        $this->get('/?categoria=manga')->assertOk()
            ->assertSee('Pack Manga Aventuras Vol. 1 - 6')
            ->assertDontSee('Figura Coleccionable Héroe del Viento');
    }

    public function test_search_supports_an_empty_result(): void
    {
        $this->get('/?q=producto-inexistente')->assertOk()
            ->assertSee('No encontramos productos con esos filtros.');
    }

    public function test_search_renders_user_input_as_text(): void
    {
        $this->get('/?q='.urlencode('<script>alert(1)</script>'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
