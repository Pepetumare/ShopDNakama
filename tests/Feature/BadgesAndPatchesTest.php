<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\StoreTestCase;

class BadgesAndPatchesTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
        $this->mock(MercadoPagoService::class);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['product_type' => 'badges', 'quantity' => 5, 'attachments' => [UploadedFile::fake()->image('design.png')]], $extra);
    }

    private function historical(User $user, string $type): CustomRequest
    {
        return $user->customRequests()->create([
            'product_type' => $type, 'quantity' => 5, 'customer_name' => $user->name, 'customer_email' => $user->email,
            'size' => $type === 'badges' ? '58 mm' : null,
            'variant' => $type === 'prints' ? 'Bolso de tela histórico' : null,
            'print_position' => $type === 'prints' ? 'Centro histórico' : null,
            'instructions' => 'Instrucciones originales', 'status' => 'quoted', 'quoted_price' => 12000,
        ]);
    }

    public function test_public_badge_configuration_has_only_fixed_standard_measurement(): void
    {
        $html = $this->get('/personalizados')->assertOk()->assertSee('Medida estándar')->assertSee('Chapita personalizada')
            ->assertSee('Parche personalizado')->assertDontSee('Estampados')->assertDontSee('Ubicación del estampado')->getContent();
        $this->assertSame(1, preg_match('~<template x-if="product === \'badges\'">(.*?)</template>~s', $html, $fragment));
        $this->assertStringContainsString('<dt>Medida</dt><dd>Estándar</dd>', $fragment[1]);
        $this->assertDoesNotMatchRegularExpression('/<(input|select|textarea)\b/', $fragment[1]);
        $this->assertStringNotContainsString('25 mm', $html);
        $this->assertStringNotContainsString('58 mm', $html);
        $this->assertStringNotContainsString("product === 'prints'", $html);
    }

    public function test_new_badge_request_saves_without_size_and_ignores_forged_dimensions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/personalizados', $this->payload([
            'size' => '999 mm', 'dimensions' => '999 × 999', 'width' => 999, 'height' => 999, 'depth' => 999,
            'dimension_unit' => 'mm', 'shape' => 'circular', 'variant' => 'Otra medida', 'border_color' => 'Negro',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $request = CustomRequest::firstOrFail();
        foreach (['size', 'dimensions', 'width', 'height', 'depth', 'dimension_unit', 'shape', 'variant', 'border_color'] as $field) {
            $this->assertNull($request->$field);
        }
        $this->assertSame(5, $request->quantity);
        $this->assertSame('Chapita personalizada', $request->product_label);
        $this->assertNull($request->instructions);
        Storage::disk('custom_orders')->assertExists($request->files->first()->stored_path);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Medida estándar')->assertSee('Estándar')->assertDontSee('999 mm');
    }

    public function test_badge_history_is_preserved_while_housekeeping_shows_standard_measurement(): void
    {
        $user = User::factory()->create();
        $request = $this->historical($user, 'badges');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.custom-requests.show', $request))
            ->assertOk()->assertSee('Chapita personalizada')->assertSee('Medida estándar')->assertSee('Estándar')->assertDontSee('58 mm');
        $this->actingAs($user)->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Estándar');
        $this->assertSame('58 mm', $request->fresh()->size);
        $this->assertSame('Instrucciones originales', $request->fresh()->instructions);
    }

    public function test_patch_saves_dimensions_shape_border_and_private_design_in_cm(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload([
            'product_type' => 'patch', 'width' => 8.5, 'height' => 5, 'dimension_unit' => 'mm',
            'shape' => 'rectangular', 'border_color' => 'Rojo', 'instructions' => 'Borde reforzado',
            'variant' => 'Ignored', 'print_position' => 'Ignored', 'depth' => 20,
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $request = CustomRequest::firstOrFail();
        $this->assertSame('patch', $request->product_type);
        $this->assertSame('Parche personalizado', $request->product_label);
        $this->assertEquals(8.5, $request->width);
        $this->assertEquals(5, $request->height);
        $this->assertSame('cm', $request->dimension_unit);
        $this->assertSame('rectangular', $request->shape);
        $this->assertSame('Rojo', $request->border_color);
        foreach (['variant', 'print_position', 'depth'] as $field) {
            $this->assertNull($request->$field);
        }
        $this->assertSame('reference', $request->files->first()->file_type);
        Storage::disk('custom_orders')->assertExists($request->files->first()->stored_path);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Parche personalizado')->assertSee('Rectangular')->assertSee('Rojo')->assertSee('Borde reforzado');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.custom-requests.show', $request))
            ->assertOk()->assertSee('Ancho')->assertSee('Alto')->assertSee('8.5')->assertSee('Rectangular')->assertSee('Color de borde')->assertSee('Rojo')->assertSee('Borde reforzado');
    }

    public function test_every_patch_shape_accepts_pdf_with_optional_border_and_instructions(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (array_keys(CustomRequest::PATCH_SHAPES) as $shape) {
            $this->post('/personalizados', $this->payload([
                'product_type' => 'patch', 'width' => 4, 'height' => 4, 'shape' => $shape,
                'attachments' => [UploadedFile::fake()->createWithContent('reference.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")],
            ]))->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->assertSame($shape, $request->shape);
            $this->assertNull($request->border_color);
            $this->assertNull($request->instructions);
            $this->get(route('custom-requests.files.show', $request->files->first()))->assertDownload();
        }
    }

    public function test_patch_requires_positive_dimensions_and_allowed_shape_and_rejects_models(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/personalizados', $this->payload(['product_type' => 'patch']))->assertSessionHasErrors(['width', 'height', 'shape']);
        $this->post('/personalizados', $this->payload(['product_type' => 'patch', 'width' => 0, 'height' => -1, 'shape' => 'triangle', 'border_color' => str_repeat('x', 101)]))->assertSessionHasErrors(['width', 'height', 'shape', 'border_color']);
        $this->post('/personalizados', $this->payload(['product_type' => 'patch', 'width' => 4, 'height' => 4, 'shape' => 'circular', 'attachments' => [UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\nf 1 2 3\n")->mimeType('application/octet-stream')]]))->assertSessionHasErrors('attachments.0');
        $this->assertDatabaseCount('custom_requests', 0);
    }

    public function test_historical_prints_remain_accessible_with_patch_labels_and_combined_filters(): void
    {
        $user = User::factory()->create();
        $old = $this->historical($user, 'prints');
        $badge = $this->historical($user, 'badges');
        $this->actingAs($user)->post('/personalizados', $this->payload(['product_type' => 'patch', 'width' => 6, 'height' => 4, 'shape' => 'square']))->assertSessionHasNoErrors();
        $new = CustomRequest::latest('id')->firstOrFail();
        $this->get('/mi-cuenta/personalizados')->assertOk()->assertSee($old->request_number)->assertSee($new->request_number)->assertSee('Parche personalizado')->assertDontSee('Estampados');
        $this->get(route('account.custom-requests.show', $old))->assertOk()->assertSee('Parche personalizado')->assertSee('Bolso de tela histórico')->assertSee('Centro histórico')->assertSee('No registrado')->assertDontSee('Estampados');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['patch', 'prints'] as $filter) {
            $this->get('/housekeeping/personalizados?product_type='.$filter)->assertOk()->assertSee($old->request_number)->assertSee($new->request_number)->assertDontSee($badge->request_number)->assertDontSee('Estampados');
        }
        $this->get(route('housekeeping.custom-requests.show', $old))->assertOk()->assertSee('Parche personalizado')->assertSee('Centro histórico');
        $this->assertSame('prints', $old->fresh()->product_type);
        $this->assertSame('Bolso de tela histórico', $old->fresh()->variant);
        $this->assertSame('Centro histórico', $old->fresh()->print_position);
    }

    public function test_old_product_key_is_not_offered_for_new_requests(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload(['product_type' => 'prints', 'variant' => 'Old form', 'print_position' => 'Centro']))->assertSessionHasErrors('product_type');
        $this->assertDatabaseCount('custom_requests', 0);
    }

    public function test_patch_configuration_is_preserved_in_the_order_snapshot(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/personalizados', $this->payload(['product_type' => 'patch', 'width' => 8, 'height' => 5, 'shape' => 'rectangular', 'border_color' => 'Verde']))->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $request->update(['status' => 'quoted', 'quoted_price' => 12000]);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('createPreference')->once()->andReturn(['id' => 'patch-pref', 'url' => 'https://www.mercadopago.cl/patch']));
        $this->post(route('account.custom-requests.accept', $request), ['shipping_address_line1' => 'Calle Nakama 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana'])->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/patch');
        $description = $request->order->items->first()->description;
        foreach (['Parche personalizado', 'Ancho: 8', 'Alto: 5', 'cm', 'Forma: Rectangular', 'Color de borde: Verde'] as $text) {
            $this->assertStringContainsString($text, $description);
        }
    }

    public function test_historical_order_description_changes_only_its_display_label(): void
    {
        $user = User::factory()->create();
        $request = $this->historical($user, 'prints');
        $order = $request->order()->create(['user_id' => $user->id]);
        $original = 'Estampados · Bolso de tela histórico · Cantidad solicitada: 5';
        $item = $order->items()->create(['product_name' => 'Personalizado '.$request->request_number, 'description' => $original, 'quantity' => 1, 'unit_price' => 12000, 'line_total' => 12000]);
        $this->actingAs($user)->get(route('account.orders.show', $order))->assertOk()->assertSee('Parche personalizado · Bolso de tela histórico')->assertDontSee('Estampados');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.orders.show', $order))->assertOk()->assertSee('Parche personalizado · Bolso de tela histórico')->assertDontSee('Estampados');
        $this->assertSame($original, $item->fresh()->description);
        $this->assertSame('prints', $request->fresh()->product_type);
    }
}
