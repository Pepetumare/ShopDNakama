<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Mockery;
use Tests\StoreTestCase;

class DesignAssistanceTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
        config(['services.mercadopago.shipping_cost' => 3990]);
        $this->actingAs(User::factory()->create());
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['product_type' => 'mug', 'variant' => 'white', 'quantity' => 3, 'attachments' => [UploadedFile::fake()->image('original.png')]], $extra);
    }

    private function assisted(array $extra = []): CustomRequest
    {
        $payload = $this->payload(array_merge(['design_assistance' => true, 'design_assistance_notes' => 'Quiero el personaje principal al centro, sin cortar las caras.'], $extra));
        $bytes = file_get_contents($payload['attachments'][0]->getRealPath());
        $this->post('/personalizados', $payload)->assertSessionHasNoErrors();
        $request = CustomRequest::latest('id')->firstOrFail();
        $this->assertSame($bytes, Storage::disk('custom_orders')->get($request->files->first()->stored_path));

        return $request;
    }

    public function test_disabled_or_omitted_service_stores_zero_fee_and_discards_notes_and_forged_prices(): void
    {
        foreach ([[], ['design_assistance' => false], ['design_assistance' => '0']] as $input) {
            $this->post('/personalizados', $this->payload($input + ['design_assistance_notes' => '<script>unwanted()</script>', 'design_assistance_fee' => 500, 'design_assistance_price' => 999, 'base_quoted_price' => 1, 'quoted_price' => 1]))->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->assertFalse($request->design_assistance);
            $this->assertSame(0, $request->design_assistance_fee);
            $this->assertNull($request->design_assistance_notes);
            $this->assertNull($request->base_quoted_price);
            $this->assertNull($request->quoted_price);
        }
    }

    public function test_service_charges_backend_fee_for_every_applicable_product_despite_forged_values(): void
    {
        $this->assertSame(500, config('custom_orders.design_assistance_price'));
        foreach ([['product_type' => 'mug', 'variant' => 'white'], ['product_type' => 'shirt_black', 'size' => 'M', 'print_position' => 'Delantera'], ['product_type' => 'shirt_white', 'size' => 'S', 'print_position' => 'Trasera'], ['product_type' => 'badges'], ['product_type' => 'stickers', 'dimensions' => '5 × 5 cm'], ['product_type' => 'patch', 'width' => 5, 'height' => 5, 'shape' => 'square']] as $index => $product) {
            $request = $this->assisted($product + ['design_assistance_fee' => [0, 1, -500, 999999, 'free', 0][$index], 'design_assistance_price' => 0]);
            $this->assertTrue($request->design_assistance);
            $this->assertSame(500, $request->design_assistance_fee);
            $this->assertSame('Quiero el personaje principal al centro, sin cortar las caras.', $request->design_assistance_notes);
        }
    }

    public function test_notes_are_required_with_limits_and_html_is_rejected(): void
    {
        foreach ([null, '', 'corto', str_repeat('x', 2001), '<script>alert(1)</script>', '<b>Diseño central</b>', ['not-text']] as $notes) {
            $this->post('/personalizados', $this->payload(['design_assistance' => true, 'design_assistance_notes' => $notes]))->assertSessionHasErrors('design_assistance_notes');
        }
        $this->post('/personalizados', $this->payload(['design_assistance' => 'yes']))->assertSessionHasErrors('design_assistance');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_service_is_configurable_and_3d_cannot_enable_it(): void
    {
        config(['custom_orders.design_assistance_products' => ['mug', '3d_print']]);
        $this->assertTrue(CustomRequest::supportsDesignAssistance('mug'));
        $this->assertFalse(CustomRequest::supportsDesignAssistance('badges'));
        $this->assertFalse(CustomRequest::supportsDesignAssistance('3d_print'));
        $this->post('/personalizados', $this->payload(['product_type' => 'badges', 'design_assistance' => true, 'design_assistance_notes' => 'Quiero que adapten el fondo']))->assertSessionHasErrors('design_assistance');
        $threeD = ['product_type' => '3d_print', 'project_name' => 'Figura', 'piece_type' => 'figure', 'color' => 'Rojo', 'width' => 5, 'height' => 8, 'depth' => 4, 'dimension_unit' => 'cm', 'usage_type' => 'decorative', 'description' => 'Referencia de figura'];
        $this->post('/personalizados', $this->payload($threeD + ['design_assistance' => true, 'design_assistance_notes' => 'Quiero que adapten el fondo']))->assertSessionHasErrors('design_assistance');
        $this->post('/personalizados', $this->payload($threeD))->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $this->assertFalse($request->design_assistance);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertDontSee('Diseño asistido');
        $this->get('/personalizados')->assertOk()->assertSee('¿Quieres que nuestro equipo adapte tu diseño?')->assertSee('Cuéntanos cómo quieres tu diseño');
    }

    public function test_customer_and_admin_see_notes_and_fee_without_leaking_internal_notes_or_other_requests(): void
    {
        $request = $this->assisted();
        $request->update(['admin_notes' => 'PRIVATE-ASSISTANCE-NOTE']);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Diseño asistido: Sí')->assertSee('Adaptación de diseño por nuestro equipo:')->assertSee('$500')->assertSee($request->design_assistance_notes)->assertDontSee('PRIVATE-ASSISTANCE-NOTE');
        $this->actingAs(User::factory()->create());
        $this->get(route('account.custom-requests.show', $request))->assertForbidden();
        $this->get(route('custom-requests.files.show', $request->files->first()))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('housekeeping.custom-requests.show', $request))->assertOk()->assertSee('DISEÑO ASISTIDO')->assertSee('Solicitado')->assertSee($request->design_assistance_notes)->assertSee('$500')->assertSee('Precio base cotizado');
        // Historical text is still escaped even if it bypassed the new validation.
        $request->update(['design_assistance_notes' => '<script>unsafe()</script>']);
        $this->get(route('housekeeping.custom-requests.show', $request))->assertOk()->assertDontSee('<script>unsafe()</script>', false)->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false);
    }

    public function test_quotes_include_fee_once_and_freeze_the_agreed_total_when_accepted(): void
    {
        $request = $this->assisted();
        $owner = $request->user;
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([10000, 10000, 12000] as $base) {
            $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'quoted', 'base_quoted_price' => $base, 'design_assistance_fee' => 0])->assertSessionHasNoErrors();
            $request->refresh();
            $this->assertSame($base.'.00', $request->base_quoted_price);
            $this->assertSame(($base + 500).'.00', $request->quoted_price);
            $this->assertSame(500, $request->design_assistance_fee);
            $this->assertSame('quoted', $request->status);
        }
        $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'quoted', 'admin_notes' => 'Revisado'])->assertSessionHasNoErrors();
        $this->assertSame('12500.00', $request->fresh()->quoted_price);
        $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'quoted', 'quoted_price' => 10000])->assertSessionHasErrors('quoted_price');
        $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'quoted', 'base_quoted_price' => 9999999999.99])->assertSessionHasErrors('base_quoted_price');
        $this->actingAs($owner)->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('$12.000')->assertSee('$500')->assertSee('$16.490');
    }

    public function test_order_and_real_mercadopago_sdk_payload_charge_service_once_using_only_server_totals(): void
    {
        config(['app.url' => 'https://nakama.example', 'services.mercadopago.access_token' => 'TEST-fake']);
        $request = $this->assisted();
        $owner = $request->user;
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'pending', 'base_quoted_price' => 10000])->assertSessionHasNoErrors();
        $transport = Mockery::mock(MPHttpClient::class);
        $payload = null;
        $transport->shouldReceive('send')->once()->andReturnUsing(function (MPRequest $request) use (&$payload) {
            $payload = json_decode($request->getPayload(), true, flags: JSON_THROW_ON_ERROR);

            return new MPResponse(201, ['id' => 'assisted-pref', 'init_point' => 'https://www.mercadopago.cl/assisted-checkout']);
        });
        $originalClient = MercadoPagoConfig::getHttpClient();
        $originalToken = MercadoPagoConfig::getAccessToken();
        MercadoPagoConfig::setHttpClient($transport);
        try {
            $this->actingAs($owner)->post(route('account.custom-requests.accept', $request), ['shipping_address_line1' => 'Nakama 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana', 'design_assistance' => false, 'design_assistance_fee' => 0, 'quoted_price' => 1, 'total' => 1, 'shipping_cost' => 0])->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/assisted-checkout');
            $order = $request->order()->firstOrFail();
            $this->assertSame('10500.00', $order->subtotal);
            $this->assertSame('14490.00', $order->total);
            $this->assertCount(2, $order->items);
            $this->assertSame('10000.00', $order->items[0]->line_total);
            $this->assertSame('500.00', $order->items[1]->line_total);
            $this->assertEquals(14490, array_sum(array_map(fn ($item) => $item['unit_price'] * $item['quantity'], $payload['items'])) + $payload['shipments']['cost']);
            $this->assertSame('Diseño asistido por Shop D. Nakama', $payload['items'][1]['title']);
            $this->post(route('account.custom-requests.accept', $request))->assertRedirect('https://www.mercadopago.cl/assisted-checkout');
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 2);
            $this->get(route('account.orders.show', $order))->assertOk()->assertSee('Diseño asistido por Shop D. Nakama')->assertSee('$14.490');
            $this->actingAs($admin)->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'quoted', 'base_quoted_price' => 11000])->assertSessionHasErrors('quoted_price');
            $this->assertSame('10500.00', $request->fresh()->quoted_price);
        } finally {
            MercadoPagoConfig::setHttpClient($originalClient);
            MercadoPagoConfig::setAccessToken($originalToken);
        }
    }

    public function test_existing_unassisted_quotes_keep_their_final_price_and_checkout_behavior(): void
    {
        $owner = User::factory()->create();
        $request = $owner->customRequests()->create(['product_type' => 'mug', 'quantity' => 2, 'customer_name' => $owner->name, 'customer_email' => $owner->email, 'status' => 'quoted', 'quoted_price' => 10000]);
        $this->assertFalse($request->fresh()->design_assistance);
        $this->assertSame(0, $request->fresh()->design_assistance_fee);
        $this->assertNull($request->base_quoted_price);
        $this->assertSame('10000.00', $request->effective_base_quoted_price);
        $this->actingAs($owner)->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Diseño asistido: No')->assertSee('$13.990');
        $this->mock(MercadoPagoService::class)->shouldReceive('createPreference')->once()->andReturn(['id' => 'old-pref', 'url' => 'https://www.mercadopago.cl/old']);
        $this->post(route('account.custom-requests.accept', $request), ['shipping_address_line1' => 'Nakama 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana'])->assertSessionHasNoErrors();
        $order = $request->order()->firstOrFail();
        $this->assertSame('13990.00', $order->total);
        $this->assertCount(1, $order->items);
    }
}
