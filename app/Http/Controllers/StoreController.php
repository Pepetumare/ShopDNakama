<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(Request $request): View
    {
        $categories = [
            ['name' => 'Manga', 'slug' => 'manga', 'x' => 33],
            ['name' => 'Figuras', 'slug' => 'figuras', 'x' => 169],
            ['name' => 'Billeteras', 'slug' => 'billeteras', 'x' => 294],
            ['name' => 'Merchandising', 'slug' => 'merch', 'x' => 426],
            ['name' => 'Impresión 3D', 'slug' => 'impresion-3d', 'x' => 552],
            ['name' => 'Estampados', 'slug' => 'estampados', 'x' => 680],
            ['name' => 'Cultura Japonesa', 'slug' => 'cultura-japonesa', 'x' => 808],
            ['name' => 'Ofertas', 'slug' => 'ofertas', 'x' => 944],
        ];
        // Presentación visual: estos productos no representan inventario comercial.
        $products = collect([
            ['name' => 'Figura Coleccionable Héroe del Viento', 'category' => 'figuras', 'price' => 24990, 'old_price' => null, 'badge' => 'Nuevo', 'x' => 42],
            ['name' => 'Billetera Estilo Japonés Gran Ola', 'category' => 'billeteras', 'price' => 12990, 'old_price' => null, 'badge' => null, 'x' => 212],
            ['name' => 'Pack Manga Aventuras Vol. 1 - 6', 'category' => 'manga', 'price' => 49990, 'old_price' => 59990, 'badge' => 'Oferta', 'x' => 383],
            ['name' => 'Polerón Estampado Nakama', 'category' => 'estampados', 'price' => 29990, 'old_price' => null, 'badge' => null, 'x' => 557],
            ['name' => 'Lámpara LED Anime Edición Especial', 'category' => 'impresion-3d', 'price' => 19990, 'old_price' => null, 'badge' => null, 'x' => 729],
            ['name' => 'Llavero Metálico Nakama', 'category' => 'merch', 'price' => 8990, 'old_price' => null, 'badge' => null, 'x' => 901],
        ]);
        $category = $request->string('categoria')->toString();
        $query = mb_strtolower($request->string('q')->toString());
        $products = $products->filter(fn ($product) => (! $category || ($category === 'ofertas' ? $product['badge'] === 'Oferta' : $product['category'] === $category))
            && (! $query || str_contains(mb_strtolower($product['name']), $query))
        );

        return view('store.home', compact('categories', 'products'));
    }
}
