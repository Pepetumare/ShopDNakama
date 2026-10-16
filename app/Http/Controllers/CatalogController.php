<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $categories = ['manga' => 'Manga', 'figuras' => 'Figuras', 'billeteras' => 'Billeteras', 'merch' => 'Merchandising', 'cultura-japonesa' => 'Cultura Japonesa', 'estampados' => 'Estampados', 'impresion-3d' => 'Impresión 3D'];
        $prices = ['menos-10000' => 'Menos de $10.000', '10000-30000' => '$10.000 - $30.000', '30000-50000' => '$30.000 - $50.000', 'mas-50000' => 'Más de $50.000'];
        // Muestra visual: no representa inventario ni ventas reales.
        $products = collect([
            ['Figura Coleccionable', 'figuras', 24990, null, 'Nuevo', 42, true],
            ['Pack Manga', 'manga', 49990, 59990, 'Oferta', 383, true],
            ['Polerón estampado', 'estampados', 34990, null, 'Nuevo', 557, true],
            ['Billetera japonesa', 'billeteras', 12990, null, null, 212, true],
            ['Tazón Kitsune', 'cultura-japonesa', 9990, null, 'Nuevo', 901, true],
            ['Polera Torii', 'estampados', 15990, 19990, 'Oferta', 557, true],
            ['Llavero', 'merch', 4990, null, null, 901, true],
            ['Lámpara LED', 'merch', 19990, null, null, 729, false],
            ['Producto impresión 3D', 'impresion-3d', 54990, null, 'Nuevo', 729, true],
            ['Figura Coleccionable Edición Especial', 'figuras', 69990, 79990, 'Oferta', 42, false],
            ['Manga Volumen 1', 'manga', 8990, null, 'Nuevo', 383, true],
            ['Billetera Gran Ola', 'billeteras', 14990, null, null, 212, true],
        ])->map(fn ($item, $index) => array_combine(['name', 'category', 'price', 'old_price', 'badge', 'x', 'in_stock', 'id'], [...$item, $index + 1]));

        $category = $request->string('categoria')->toString();
        $query = mb_strtolower($request->string('q')->toString());
        $products = $products->filter(fn ($product) =>
            (! $category || ($category === 'ofertas' ? $product['badge'] === 'Oferta' : $product['category'] === $category))
            && (! $query || str_contains(mb_strtolower($product['name']), $query))
            && match ($request->string('precio')->toString()) {
                'menos-10000' => $product['price'] < 10000,
                '10000-30000' => $product['price'] >= 10000 && $product['price'] < 30000,
                '30000-50000' => $product['price'] >= 30000 && $product['price'] <= 50000,
                'mas-50000' => $product['price'] > 50000,
                default => true,
            }
            && match ($request->string('disponibilidad')->toString()) {
                'stock' => $product['in_stock'], 'agotado' => ! $product['in_stock'], default => true,
            }
        );
        $products = match ($request->string('orden')->toString()) {
            'precio-menor' => $products->sortBy('price'),
            'precio-mayor' => $products->sortByDesc('price'),
            'nuevos' => $products->sortByDesc('id'),
            default => $products->sortBy('id'),
        };
        $page = max(1, min($request->integer('page', 1), max(1, (int) ceil($products->count() / 8))));
        $products = new LengthAwarePaginator($products->values()->forPage($page, 8), $products->count(), 8, $page, ['path' => route('catalogo'), 'query' => $request->except('page')]);

        return view('store.catalog', compact('products', 'categories', 'prices'));
    }
}
