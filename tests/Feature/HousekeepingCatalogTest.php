<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\StoreTestCase;

class HousekeepingCatalogTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function payload(array $overrides = []): array
    {
        $category = Category::firstOrCreate(['slug' => 'figuras'], ['name' => 'Figuras']);

        return array_merge([
            'name' => 'Figura Samurái', 'category_id' => $category->id, 'price' => 24990,
            'sale_price' => 19990, 'stock' => 3, 'is_active' => 1, 'is_featured' => 1,
            'is_new' => 1, 'is_customizable' => 1, 'sku' => 'SAM-001',
            'description' => 'Descripción guardada desde Housekeeping.', 'short_description' => 'Figura para nakamas.',
            'product_type' => 'Coleccionable',
        ], $overrides);
    }

    public function test_all_catalog_endpoints_reject_guests_and_normal_users(): void
    {
        $product = $this->product();
        $image = $product->images()->create(['image' => 'products/test.webp']);
        $category = $product->category;
        $urls = [
            ['GET', route('housekeeping.categories.index')], ['GET', route('housekeeping.categories.create')],
            ['GET', route('housekeeping.categories.edit', $category)], ['POST', route('housekeeping.categories.store')],
            ['PUT', route('housekeeping.categories.update', $category)], ['PATCH', route('housekeeping.categories.status', $category)],
            ['GET', route('housekeeping.products.index')], ['GET', route('housekeeping.products.create')],
            ['GET', route('housekeeping.products.edit', $product)], ['POST', route('housekeeping.products.store')],
            ['PUT', route('housekeeping.products.update', $product)], ['PATCH', route('housekeeping.products.status', $product)],
            ['DELETE', route('housekeeping.products.images.destroy', [$product, $image])],
        ];
        auth()->logout();
        foreach ($urls as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach ($urls as [$method, $url]) {
            $this->call($method, $url)->assertForbidden();
        }
        $this->assertDatabaseCount('product_images', 1);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_can_create_edit_order_and_toggle_a_category_with_an_image(): void
    {
        $this->get('/housekeeping/categories/create')->assertOk();
        $this->post('/housekeeping/categories', [
            'name' => 'Cultura Japonesa', 'description' => 'Descripción de categoría',
            'is_active' => 1, 'sort_order' => 2, 'image' => UploadedFile::fake()->image('categoria.png'),
        ])->assertSessionHasNoErrors();
        $category = Category::where('slug', 'cultura-japonesa')->firstOrFail();
        Storage::disk('public')->assertExists($category->image);
        $this->get(route('housekeeping.categories.edit', $category))->assertOk()->assertSee('Descripción de categoría');
        $this->put(route('housekeeping.categories.update', $category), [
            'name' => 'Cultura actualizada', 'slug' => $category->slug, 'is_active' => 1, 'sort_order' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Cultura actualizada', $category->fresh()->name);
        $this->assertSame(0, $category->fresh()->sort_order);
        $this->get('/')->assertOk()->assertSee('Cultura actualizada');
        $this->patch(route('housekeeping.categories.status', $category), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->is_active);
        $this->get('/')->assertOk()->assertDontSee('Cultura actualizada');
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_category_validation_rejects_duplicates_negative_order_and_unsafe_files(): void
    {
        $category = Category::create(['name' => 'Manga']);
        $this->post('/housekeeping/categories', [
            'name' => '', 'slug' => $category->slug, 'is_active' => 1, 'sort_order' => -1,
            'image' => UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ])->assertSessionHasErrors(['name', 'slug', 'sort_order', 'image']);
        $this->assertDatabaseCount('categories', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_category_image_replacement_and_removal_preserve_other_data(): void
    {
        $category = Category::create(['name' => 'Figuras', 'image' => 'categories/old.png']);
        Storage::disk('public')->put('categories/old.png', 'old');
        $data = ['name' => $category->name, 'slug' => $category->slug, 'is_active' => 1, 'sort_order' => 0];
        $this->put(route('housekeeping.categories.update', $category), $data + ['image' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing('categories/old.png');
        Storage::disk('public')->assertExists($category->fresh()->image);
        $path = $category->fresh()->image;
        $this->put(route('housekeeping.categories.update', $category), $data + ['remove_image' => 1])->assertSessionHasNoErrors();
        $this->assertNull($category->fresh()->image);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_product_creation_uploads_images_and_publishes_to_all_public_pages(): void
    {
        $this->get('/housekeeping/products/create')->assertOk();
        $this->post('/housekeeping/products', $this->payload([
            'uploads' => [UploadedFile::fake()->image('front.png'), UploadedFile::fake()->image('back.jpg')],
        ]))->assertSessionHasNoErrors();
        $product = Product::where('slug', 'figura-samurai')->firstOrFail();
        $this->assertSame('SAM-001', $product->sku);
        $this->assertTrue($product->is_featured && $product->is_new);
        $this->assertFalse($product->is_customizable);
        $this->assertSame(2, $product->images()->count());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->image);
        }
        $this->get('/')->assertOk()->assertSee($product->name);
        $this->get('/catalogo')->assertOk()->assertSee($product->name)->assertSee('$19.990');
        $this->get(route('producto.show', $product))->assertOk()->assertSee('SAM-001')->assertSee('Últimas unidades')->assertDontSee('¿Quieres personalizarlo?')->assertDontSee('Solicitar personalización');
        $this->get(route('housekeeping.products.edit', $product))->assertOk()->assertSee('Imagen principal');
    }

    public function test_product_updates_stock_prices_and_all_flags_without_removing_existing_images(): void
    {
        $product = $this->product(['name' => 'Figura original', 'sku' => 'SAM-001']);
        $image = $product->images()->create(['image' => 'products/existing.png', 'is_primary' => true]);
        $this->put(route('housekeeping.products.update', $product), $this->payload([
            'name' => 'Figura editada', 'slug' => $product->slug, 'sale_price' => null, 'stock' => 0,
            'is_new' => 0, 'is_customizable' => 0, 'is_featured' => 0,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertNull($product->fresh()->sale_price);
        $this->assertFalse($product->fresh()->is_new || $product->fresh()->is_customizable || $product->fresh()->is_featured);
        $this->assertTrue($image->fresh()->is_primary);
        $this->get('/')->assertOk()->assertDontSee('Figura editada');
        $this->get('/catalogo')->assertOk()->assertSee('Figura editada')->assertSee('Agotado');
        $this->patch(route('housekeeping.products.status', $product), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->get('/catalogo')->assertOk()->assertDontSee('Figura editada');
        $this->get(route('producto.show', $product))->assertNotFound();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_product_validation_rejects_invalid_values_and_does_not_store_files(): void
    {
        $existing = $this->product(['slug' => 'ocupado', 'sku' => 'SAM-001']);
        $this->post('/housekeeping/products', $this->payload([
            'name' => '', 'slug' => $existing->slug, 'stock' => -1, 'price' => -1,
            'sale_price' => 500, 'category_id' => 999999,
            'uploads' => [UploadedFile::fake()->createWithContent('bad.txt', 'not an image')],
        ]))->assertSessionHasErrors(['name', 'slug', 'sku', 'stock', 'price', 'sale_price', 'category_id', 'uploads.0']);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->post('/housekeeping/products', $this->payload(['sku' => null, 'price' => 10000, 'sale_price' => 10000]))->assertSessionHasErrors('sale_price');
    }

    public function test_product_list_filters_name_sku_category_and_state_with_grouped_search(): void
    {
        $data = $this->payload();
        $wanted = $this->product(['name' => 'Buscado Nakama', 'sku' => 'FIND-01', 'category_id' => $data['category_id'], 'is_active' => true]);
        $this->product(['name' => 'Oculto Nakama', 'sku' => 'FIND-02', 'is_active' => false]);
        $this->get('/housekeeping/products?q=FIND&category_id='.$wanted->category_id.'&state=active')
            ->assertOk()->assertSee('Buscado Nakama')->assertDontSee('Oculto Nakama');
        $this->get('/housekeeping/products?q=Buscado')->assertOk()->assertSee('FIND-01')->assertDontSee('FIND-02');
        $this->get('/housekeeping/products?state=inactive')->assertOk()->assertSee('Oculto Nakama')->assertDontSee('Buscado Nakama');
    }

    public function test_gallery_primary_order_alt_text_and_individual_deletion(): void
    {
        $product = $this->product(['sku' => 'SAM-001']);
        $first = $product->images()->create(['image' => 'products/first.png', 'is_primary' => true, 'sort_order' => 1]);
        $second = $product->images()->create(['image' => 'products/second.png', 'sort_order' => 2]);
        Storage::disk('public')->put($first->image, 'first');
        Storage::disk('public')->put($second->image, 'second');
        $this->put(route('housekeeping.products.update', $product), $this->payload([
            'slug' => $product->slug, 'primary_image_id' => $second->id,
            'images_meta' => [
                ['id' => $first->id, 'sort_order' => 9, 'alt_text' => 'Vista secundaria'],
                ['id' => $second->id, 'sort_order' => 0, 'alt_text' => 'Vista principal'],
            ],
        ]))->assertSessionHasNoErrors();
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertSame(9, $first->fresh()->sort_order);
        $this->get(route('producto.show', $product))->assertOk()->assertSeeInOrder(['alt="Vista principal"', 'alt="Vista secundaria"'], false);
        $this->delete(route('housekeeping.products.images.destroy', [$product, $second]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('product_images', ['id' => $second->id]);
        Storage::disk('public')->assertMissing($second->image);
        Storage::disk('public')->assertExists($first->image);
        $this->assertTrue($first->fresh()->is_primary);
        $this->delete(route('housekeeping.products.images.destroy', [$product, $first]))->assertSessionHasNoErrors();
        $this->get(route('producto.show', $product))->assertOk()->assertSee('product-placeholder.svg');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_foreign_images_cannot_be_deleted_edited_or_made_primary(): void
    {
        $product = $this->product();
        $other = $this->product(['name' => 'Otro producto']);
        $foreign = $other->images()->create(['image' => 'products/foreign.png', 'sort_order' => 4, 'is_primary' => true]);
        $this->delete(route('housekeeping.products.images.destroy', [$product, $foreign]))->assertNotFound();
        $this->put(route('housekeeping.products.update', $product), $this->payload([
            'primary_image_id' => $foreign->id,
            'images_meta' => [['id' => $foreign->id, 'sort_order' => 0, 'alt_text' => 'Ajena']],
        ]))->assertSessionHasErrors(['primary_image_id', 'images_meta.0.id']);
        $this->assertSame(4, $foreign->fresh()->sort_order);
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_deleting_an_image_does_not_remove_a_file_referenced_elsewhere(): void
    {
        $product = $this->product();
        $other = $this->product(['name' => 'Otro producto']);
        $image = $product->images()->create(['image' => 'products/shared.png']);
        $other->images()->create(['image' => $image->image]);
        Storage::disk('public')->put($image->image, 'shared');
        $this->delete(route('housekeeping.products.images.destroy', [$product, $image]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists('products/shared.png');
    }
}
