<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreDemoSeeder extends Seeder
{
    // Explicit opt-in only: never called by DatabaseSeeder or migrations.
    // firstOrCreate preserves all existing category and product values.
    public function run(): void
    {
        DB::transaction(function () {
            $categories = [];
            foreach (['manga' => 'Manga', 'figuras' => 'Figuras', 'billeteras' => 'Billeteras', 'merch' => 'Merchandising', 'cultura-japonesa' => 'Cultura Japonesa', 'estampados' => 'Estampados', 'impresion-3d' => 'Impresión 3D'] as $slug => $name) {
                $categories[$slug] = Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => count($categories)]);
            }
            $items = [
                ['figura-coleccionable', 'Figura Coleccionable', 'figuras', 24990, null, 8],
                ['pack-manga', 'Pack Manga', 'manga', 59990, 49990, 12],
                ['poleron-estampado', 'Polerón estampado', 'estampados', 34990, null, 3],
                ['billetera-japonesa', 'Billetera japonesa', 'billeteras', 12990, null, 8],
                ['tazon-kitsune', 'Tazón Kitsune', 'cultura-japonesa', 9990, null, 4],
                ['polera-torii', 'Polera Torii', 'estampados', 19990, 15990, 8],
                ['llavero', 'Llavero', 'merch', 4990, null, 8],
                ['lampara-led', 'Lámpara LED', 'merch', 19990, null, 0],
                ['producto-impresion-3d', 'Producto impresión 3D', 'impresion-3d', 54990, null, 8],
                ['figura-coleccionable-edicion-especial', 'Figura Coleccionable Edición Especial', 'figuras', 79990, 69990, 0],
                ['manga-volumen-1', 'Manga Volumen 1', 'manga', 8990, null, 8],
                ['billetera-gran-ola', 'Billetera Gran Ola', 'billeteras', 14990, null, 8],
            ];
            foreach ($items as [$slug, $name, $category, $price, $salePrice, $stock]) {
                Product::firstOrCreate(['slug' => $slug], [
                    'name' => $name, 'category_id' => $categories[$category]->id,
                    'short_description' => 'Diseño inspirado en el anime y la cultura japonesa. Para verdaderos nakamas.',
                    'description' => $name.'. Producto de demostración para desarrollo de Shop D. Nakama.',
                    'price' => $price, 'sale_price' => $salePrice, 'stock' => $stock,
                    'is_featured' => true, 'is_new' => $salePrice === null,
                    'is_customizable' => in_array($category, ['estampados', 'impresion-3d']),
                    'product_type' => $categories[$category]->name,
                ]);
            }
        });
    }
}
