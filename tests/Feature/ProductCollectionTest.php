<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductCollectionVolume;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\StoreTestCase;

class ProductCollectionTest extends StoreTestCase
{
    public function test_admin_can_manage_series_cover_publication_and_membership(): void
    {
        Storage::fake('public');
        $product = $this->product(['sku' => 'SD-004', 'stock' => 0]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['name' => 'Sakamoto Days', 'category_id' => $product->category_id,
            'description' => 'Una serie de manga', 'is_published' => 0, 'sort_order' => 8, 'seo_title' => 'Sakamoto en Chile'];

        $this->get(route('housekeeping.collections.create'))->assertOk();
        $this->post(route('housekeeping.collections.store'), $payload + ['image' => UploadedFile::fake()->image('cover.png')])
            ->assertSessionHasNoErrors();
        $collection = ProductCollection::whereSlug('sakamoto-days')->firstOrFail();
        Storage::disk('public')->assertExists($collection->image);

        $this->post(route('housekeeping.collections.volumes.store', $collection),
            ['product_id' => $product->id, 'volume_number' => 4, 'sort_order' => 1])->assertSessionHasNoErrors();
        $volume = $collection->volumes()->sole();
        $this->assertTrue($product->collectionVolume->is($volume));
        $this->get(route('collections.show', $collection))->assertNotFound();
        $this->get(route('housekeeping.collections.index'))->assertOk()->assertSee('Borrador');
        $this->get(route('housekeeping.collections.edit', $collection))->assertOk()->assertSee('Agotado')->assertSee($product->name);

        $this->put(route('housekeeping.collections.update', $collection), array_replace($payload, [
            'name' => 'Sakamoto Days en español', 'is_published' => 1,
            'image' => UploadedFile::fake()->image('updated.png'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('sakamoto-days', $collection->fresh()->slug);
        Storage::disk('public')->assertExists($collection->fresh()->image);
        Storage::disk('public')->assertMissing($collection->image);
        $this->get(route('collections.show', $collection))->assertOk()->assertSee('Sakamoto en Chile');
        $this->patch(route('housekeeping.collections.volumes.update', [$collection, $volume]),
            ['volume_number' => 5, 'sort_order' => 10])->assertSessionHasNoErrors();
        $this->assertSame(10, $volume->fresh()->sort_order);
        $this->assertSame(5, $volume->fresh()->volume_number);

        $this->delete(route('housekeeping.collections.volumes.destroy', [$collection, $volume]))->assertSessionHasNoErrors();
        $this->assertModelMissing($volume);
        $this->assertSame($product->only(['sku', 'price', 'stock']), $product->fresh()->only(['sku', 'price', 'stock']));
        $this->get(route('producto.show', $product))->assertOk();
    }

    public function test_admin_routes_require_role_and_scope_child_binding_to_its_collection(): void
    {
        $product = $this->product();
        $series = $this->series($product);
        $otherSeries = $this->series($product, ['name' => 'Otra serie']);
        $volume = $this->associate($series, $product, 1);

        $routes = [
            ['GET', 'index', []], ['GET', 'create', []], ['POST', 'store', []],
            ['GET', 'edit', [$series]], ['PUT', 'update', [$series]],
            ['POST', 'volumes.store', [$series]], ['PATCH', 'volumes.update', [$series, $volume]],
            ['DELETE', 'volumes.destroy', [$series, $volume]],
        ];
        foreach ($routes as [$method, $route, $arguments]) {
            $this->call($method, route('housekeeping.collections.'.$route, $arguments))->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach ($routes as [$method, $route, $arguments]) {
            $this->call($method, route('housekeeping.collections.'.$route, $arguments))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patch(route('housekeeping.collections.volumes.update', [$otherSeries, $volume]))->assertNotFound();
        $this->delete(route('housekeeping.collections.volumes.destroy', [$otherSeries, $volume]))->assertNotFound();
        $this->assertModelExists($volume);
    }

    public function test_membership_is_unique_in_http_validation_and_the_database(): void
    {
        $first = $this->product();
        $second = $this->product();
        $series = $this->series($first);
        $otherSeries = $this->series($first, ['name' => 'Otra serie']);
        $this->associate($series, $first, 1);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson(route('housekeeping.collections.volumes.store', $series),
            ['product_id' => $second->id, 'volume_number' => 1, 'sort_order' => 0])->assertUnprocessable()->assertJsonValidationErrors('volume_number');
        $this->postJson(route('housekeeping.collections.volumes.store', $otherSeries),
            ['product_id' => $first->id, 'volume_number' => 2, 'sort_order' => 0])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertUniqueViolation(fn () => $this->associate($series, $second, 1));
        $this->assertUniqueViolation(fn () => $this->associate($otherSeries, $first, 2));
        $this->assertUniqueViolation(fn () => $this->series($first, ['slug' => $series->slug]));
        $this->assertDatabaseCount('product_collection_volumes', 1);
    }

    public function test_catalog_groups_before_pagination_and_preserves_search_filters_and_featured(): void
    {
        $category = Category::create(['name' => 'Series']);
        $first = $this->product(['name' => 'Primer libro', 'sku' => 'SKU-COL-01', 'price' => 12500]);
        $series = $this->series($first, ['category_id' => $category->id, 'name' => 'Sakamoto Days COLECCION-TEST']);
        $this->associate($series, $first, 1);
        $second = $this->product(['name' => 'Segundo libro', 'price' => 14000, 'sale_price' => 8500, 'stock' => 0]);
        $this->associate($series, $second, 2);
        for ($i = 1; $i <= 8; $i++) {
            $this->product(['name' => 'Elemento COLECCION-TEST '.$i, 'is_featured' => false]);
        }

        $this->get('/catalogo?q=COLECCION-TEST')->assertOk()->assertSee('Mostrando 8 productos');
        $this->get('/catalogo?q=COLECCION-TEST&page=2')->assertOk()->assertSee('Mostrando 1 productos');
        $this->get('/catalogo?q=Sakamoto')->assertOk()->assertSee('Mostrando 1 productos')->assertSee($series->name);
        $this->get('/catalogo?q=SKU-COL-01&categoria=series&disponibilidad=stock')->assertOk()->assertSee($series->name);
        $this->get('/catalogo?q=Segundo&categoria=ofertas&precio=menos-10000')->assertOk()->assertSee($series->name)->assertSee('$8.500');
        $this->get('/catalogo?categoria=series&disponibilidad=agotado')->assertOk()->assertDontSee(route('collections.show', $series));
        $this->get('/catalogo?categoria=manga')->assertOk()->assertSee('Elemento')->assertDontSee(route('collections.show', $series));
        $this->get('/')->assertOk()->assertSee($series->name)->assertDontSee('Primer libro')->assertDontSee('Segundo libro');
        $series->update(['is_published' => false]);
        $this->get('/catalogo?q=libro')->assertOk()->assertSee('Primer libro')->assertSee('Segundo libro');
    }

    public function test_selector_contains_only_public_registered_volumes_and_old_urls_resolve(): void
    {
        Storage::fake('public');
        $first = $this->product(['name' => 'Tomo uno', 'stock' => 5, 'sku' => 'VOL-UNO']);
        $series = $this->series($first, ['seo_description' => 'Toda la serie']);
        $this->associate($series, $first, 1);
        $this->associate($series, $this->product(['stock' => 0]), 2);
        $fourth = $this->product(['name' => 'Tomo cuatro', 'sku' => 'VOL-CUATRO', 'price' => 14990, 'stock' => 2]);
        Storage::disk('public')->put('products/four.png', 'fixture');
        $fourth->images()->create(['image' => 'products/four.png', 'is_primary' => true, 'alt_text' => 'Portada cuatro']);
        $this->associate($series, $fourth, 4);
        foreach ([6, 11, 12] as $number) {
            $this->associate($series, $this->product(), $number);
        }
        $this->associate($series, $this->product(['is_active' => false]), 7);

        $url = route('collections.show', ['collection' => $series, 'tomo' => 4]);
        $this->get(route('producto.show', $fourth))->assertRedirect($url);
        $response = $this->get($url)->assertOk()->assertSee('VOL-CUATRO')->assertSee('$14.990')
            ->assertSee('products/four.png')->assertSee('Toda la serie');
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $buttons = $xpath->query('//fieldset[@class="manga-volumes"]//button');
        $this->assertSame(['1', '2', '4', '6', '11', '12'], array_map(fn ($button) => trim($button->textContent), iterator_to_array($buttons)));
        $this->assertTrue($buttons->item(1)->hasAttribute('disabled'));
        $this->assertSame('Tomo 2, agotado', $buttons->item(1)->getAttribute('aria-label'));
        $this->assertFalse($buttons->item(2)->hasAttribute('disabled'));
        $this->assertSame('true', $buttons->item(2)->getAttribute('aria-pressed'));
        $this->assertSame(1, $xpath->query('//*[@aria-live="polite"]')->length);
        $this->assertSame((string) $fourth->id, $xpath->query('//input[@name="product_id"]')->item(0)->getAttribute('value'));
        $this->assertSame('2', $xpath->query('//input[@name="quantity"]')->item(0)->getAttribute('max'));
        $series->update(['is_published' => false]);
        $this->get($url)->assertNotFound();
        $this->get(route('producto.show', $fourth))->assertOk();
    }

    public function test_cart_uses_real_products_prices_and_stock_for_separate_volumes(): void
    {
        $first = $this->product(['name' => 'Tomo uno', 'stock' => 2, 'price' => 10990]);
        $second = $this->product(['name' => 'Tomo dos', 'stock' => 1, 'price' => 14990, 'sale_price' => 11990]);
        $soldOut = $this->product(['stock' => 0]);
        $series = $this->series($first);
        foreach ([$first, $second, $soldOut] as $index => $product) {
            $this->associate($series, $product, $index + 1);
        }
        $url = route('collections.show', $series);

        $this->from($url)->post(route('cart.store'), ['product_id' => $first->id, 'quantity' => 2, 'price' => 1])->assertRedirect($url);
        $this->from($url)->post(route('cart.store'), ['product_id' => $second->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->get(route('cart.index'))->assertOk()->assertSee('Tomo uno')->assertSee('Tomo dos')->assertSee('$33.970');
        foreach ([$first, $second, $soldOut] as $product) {
            $this->postJson(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->postJson(route('cart.store'), ['product_id' => 999999, 'quantity' => 1])->assertNotFound();
        $this->assertSame(2, $first->fresh()->stock);
        $this->assertSame(1, $second->fresh()->stock);
    }

    private function series(Product $product, array $overrides = []): ProductCollection
    {
        return ProductCollection::create($overrides + ['name' => 'Sakamoto Days', 'category_id' => $product->category_id, 'is_published' => true]);
    }

    private function associate(ProductCollection $series, Product $product, int $number): ProductCollectionVolume
    {
        return $series->volumes()->create(['product_id' => $product->id, 'volume_number' => $number]);
    }

    private function assertUniqueViolation(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database accepted a duplicate.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }
}
