<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StoreImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\StoreTestCase;

class ProductImageFlowTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function payload($product, array $extra = []): array
    {
        return array_merge([
            'name' => $product->name, 'slug' => $product->slug, 'category_id' => $product->category_id,
            'price' => $product->price, 'stock' => $product->stock,
            'is_active' => 1, 'is_featured' => 1, 'is_new' => 0, 'is_customizable' => 0,
        ], $extra);
    }

    public function test_public_disk_urls_are_same_origin_even_when_app_url_has_another_host_or_port(): void
    {
        config(['app.url' => 'http://wrong-host.test:9999']);
        Storage::disk('public')->put('products/photo.jpg', 'file');
        $this->assertSame('/storage/products/photo.jpg', Storage::disk('public')->url('products/photo.jpg'));
        $this->assertSame('/storage/products/photo.jpg', StoreImage::url('products/photo.jpg'));
        $this->assertStringContainsString('product-placeholder.svg', StoreImage::url('products/missing.jpg'));
        $this->assertStringContainsString('product-placeholder.svg', StoreImage::url(null));
    }

    public function test_uploading_same_named_files_creates_unique_relative_paths_shown_on_every_page(): void
    {
        $product = $this->product();
        $this->put(route('housekeeping.products.update', $product), $this->payload($product, [
            'uploads' => [UploadedFile::fake()->image('same.jpg'), UploadedFile::fake()->image('same.jpg')],
        ]))->assertSessionHasNoErrors();
        $images = $product->images()->get();
        $this->assertCount(2, $images);
        $this->assertNotSame($images[0]->image, $images[1]->image);
        $this->assertSame(1, $images->where('is_primary', true)->count());
        foreach ($images as $image) {
            $this->assertMatchesRegularExpression('~^products/[A-Za-z0-9]+\.jpg$~', $image->image);
            Storage::disk('public')->assertExists($image->image);
        }
        foreach ([route('housekeeping.products.edit', $product), route('housekeeping.products.index'), '/catalogo', route('producto.show', $product), '/'] as $url) {
            $this->get($url)->assertOk()->assertSee('src="/storage/'.$images[0]->image.'"', false)
                ->assertDontSee('onerror=', false);
        }
        $this->delete(route('housekeeping.products.images.destroy', [$product, $images[0]]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($images[0]->image);
        Storage::disk('public')->assertExists($images[1]->image);
        $this->assertTrue($images[1]->fresh()->is_primary);
        $this->get('/catalogo')->assertOk()->assertSee('src="/storage/'.$images[1]->image.'"', false);
        $this->delete(route('housekeeping.products.images.destroy', [$product, $images[1]]))->assertSessionHasNoErrors();
        $this->get('/catalogo')->assertOk()->assertSee('product-placeholder.svg');
    }

    public function test_upload_limits_reject_oversized_files_and_more_than_ten_images(): void
    {
        $product = $this->product();
        $this->put(route('housekeeping.products.update', $product), $this->payload($product, [
            'uploads' => [UploadedFile::fake()->image('large.jpg')->size(5121)],
        ]))->assertSessionHasErrors('uploads.0');
        $this->put(route('housekeeping.products.update', $product), $this->payload($product, [
            'uploads' => array_map(fn () => UploadedFile::fake()->image('image.png'), range(1, 11)),
        ]))->assertSessionHasErrors('uploads');
        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
