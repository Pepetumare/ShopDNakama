<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_catalog_renders_shared_components_and_paginated_mock_products(): void
    {
        $this->get('/catalogo')->assertOk()->assertSee('CATÁLOGO')
            ->assertSee('Mostrando 8 productos')->assertSee('Figura Coleccionable')
            ->assertDontSee('Producto impresión 3D')->assertSee('Página siguiente');
        $this->get('/catalogo?page=2')->assertOk()->assertSee('Producto impresión 3D')
            ->assertSee('Mostrando 4 productos');
    }

    public function test_filters_combine_category_price_and_availability(): void
    {
        $this->get('/catalogo?categoria=figuras&precio=mas-50000&disponibilidad=agotado')
            ->assertOk()->assertSee('Mostrando 1 productos')->assertSee('Figura Coleccionable Edición Especial')
            ->assertDontSee('Pack Manga');
        $this->get('/catalogo?categoria=manga&disponibilidad=agotado')->assertOk()
            ->assertSee('No encontramos productos con esos filtros.');
    }

    public function test_sort_and_search_apply_to_the_mock_collection(): void
    {
        $this->get('/catalogo?categoria=manga&orden=precio-menor')->assertOk()
            ->assertSeeInOrder(['Manga Volumen 1', 'Pack Manga']);
        $this->get('/catalogo?q=Kitsune')->assertOk()->assertSee('Tazón Kitsune')
            ->assertSee('Mostrando 1 productos')->assertDontSee('Pack Manga');
        $this->get('/catalogo?q='.urlencode('<script>alert(1)</script>'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_pagination_preserves_sort_and_filters(): void
    {
        $this->get('/catalogo?disponibilidad=stock&orden=precio-menor')->assertOk()
            ->assertSee('disponibilidad=stock&amp;orden=precio-menor&amp;page=2', false);
    }
}
