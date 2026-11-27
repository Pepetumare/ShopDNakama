<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\PreferenceNotSent;
use App\Services\StockReservations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\StoreTestCase;

class MercadoPagoCheckoutTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.shipping_cost' => 3990]);
    }

    private function data(array $extra = []): array
    {
        return array_merge(['customer_name' => 'Nakama', 'customer_email' => 'buyer@example.com',
            'customer_phone' => '912345678', 'shipping_address_line1' => 'Calle 123',
            'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana', 'delivery_type' => 'despacho'], $extra);
    }

    private function attempt(): string
    {
        return $this->get('/checkout')->assertOk()->viewData('attempt')->token;
    }

    private function submit(array $extra = [])
    {
        return $this->post('/checkout', $this->data($extra + ['attempt' => $this->attempt()]));
    }

    private function checkout(): Order
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $this->mock(MercadoPagoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createPreference')->once()->andReturn(['id' => 'pref-1', 'url' => 'https://www.mercadopago.cl/checkout/test']);
        });
        $this->submit()->assertRedirect('https://www.mercadopago.cl/checkout/test');

        return Order::firstOrFail();
    }

    private function notify(Order $order, string $status = 'approved', array $extra = [])
    {
        config(['services.mercadopago.webhook_secret' => 'test-secret']);
        $payment = array_merge(['id' => '123', 'external_reference' => $order->number,
            'status' => $status, 'amount' => $order->total, 'currency' => 'CLP'], $extra);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('getPayment')->with('123')->andReturn($payment));
        $ts = (string) time();
        $signature = hash_hmac('sha256', "id:123;request-id:test-request;ts:$ts;", 'test-secret');

        return $this->postJson('/webhooks/mercadopago?data.id=123', ['type' => 'payment', 'data' => ['id' => '123']],
            ['x-request-id' => 'test-request', 'x-signature' => "ts=$ts,v1=$signature"]);
    }

    public function test_checkout_requires_a_cart_and_authentication(): void
    {
        $this->get('/checkout')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/checkout')->assertRedirect('/carrito');
        $this->post('/checkout', $this->data())->assertSessionHasErrors('attempt');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_backend_prices_create_pending_order_and_ignore_tampered_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product(['sale_price' => 10000]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $product->update(['sale_price' => 9000]);
        $this->get('/checkout')->assertOk()->assertSee('Pagar con Mercado Pago')->assertViewHas('subtotal', 18000);
        $this->mock(MercadoPagoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createPreference')->once()->withArgs(function (Order $order) {
                return $order->status === 'pending' && $order->payment_status === 'pending'
                    && (float) $order->total === 21990.0 && (float) $order->items->first()->unit_price === 9000.0;
            })->andReturn(['id' => 'pref-1', 'url' => 'https://www.mercadopago.cl/test']);
        });
        $this->submit(['total' => 1, 'subtotal' => 1, 'shipping_cost' => 0, 'payment_status' => 'paid', 'price' => 1])->assertRedirect();
        $this->assertDatabaseHas('orders', ['status' => 'pending', 'payment_status' => 'pending', 'total' => 21990, 'mercadopago_preference_id' => 'pref-1']);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_reservations', ['product_id' => $product->id, 'quantity' => 2, 'status' => 'active']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_approved_webhook_confirms_payment_and_deducts_stock_once(): void
    {
        $order = $this->checkout();
        $this->notify($order)->assertOk();
        $this->notify($order)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertNotNull($order->stock_deducted_at);
        $this->assertSame(6, $order->items->first()->product->stock);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'consumed']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->get('/mi-cuenta/pedidos/'.$order->id)->assertOk()->assertSee('Mercado Pago')->assertSee('123');
    }

    public function test_rejected_webhook_does_not_mark_paid_or_change_stock(): void
    {
        $order = $this->checkout();
        $this->notify($order, 'rejected')->assertOk();
        $this->assertSame('rejected', $order->fresh()->payment_status);
        $this->assertSame(8, $order->items->first()->product->stock);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'active']);
    }

    public function test_return_query_cannot_confirm_payment_and_other_user_cannot_view_order(): void
    {
        $order = $this->checkout();
        $this->get('/checkout/mercadopago/success?external_reference='.$order->number.'&status=approved')->assertOk()->assertSee('Estamos confirmando tu pago');
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->actingAs(User::factory()->create())->get('/mi-cuenta/pedidos/'.$order->id)->assertForbidden();
    }

    public function test_invalid_amount_currency_reference_or_signature_cannot_confirm_payment(): void
    {
        $order = $this->checkout();
        foreach ([['amount' => 1], ['currency' => 'USD'], ['external_reference' => 'other-order']] as $extra) {
            $this->notify($order, 'approved', $extra)->assertStatus(422);
        }
        $this->postJson('/webhooks/mercadopago?data.id=123', ['type' => 'payment', 'data' => ['id' => '123']])->assertUnauthorized();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_checkout_rejects_stock_that_changed_after_adding_to_cart(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $attempt = $this->attempt();
        $product->update(['stock' => 1]);
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_preference_failure_preserves_pending_order_and_cart(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1]);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('createPreference')->once()->andThrow(new PreferenceNotSent('API unavailable')));
        $this->submit()->assertRedirect('/checkout')->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending', 'mercadopago_preference_id' => null]);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_reservations', ['product_id' => $product->id, 'status' => 'released']);
    }

    public function test_second_buyer_cannot_checkout_the_reserved_last_unit_and_cart_is_atomic(): void
    {
        $last = $this->product(['name' => 'Última unidad', 'stock' => 1]);
        $other = $this->product(['name' => 'Otro producto']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $buyer) {
            $this->actingAs($buyer)->post('/carrito', ['product_id' => $last->id, 'quantity' => 1]);
        }
        $this->post('/carrito', ['product_id' => $other->id, 'quantity' => 1]);
        $this->mock(MercadoPagoService::class)->shouldReceive('createPreference')->once()
            ->andReturn(['id' => 'last-pref', 'url' => 'https://www.mercadopago.cl/test']);
        $this->actingAs($first)->submit()->assertRedirect('https://www.mercadopago.cl/test');
        $this->actingAs($second)->submit()->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(1, $last->fresh()->stock);
        $this->assertSame(0, app(StockReservations::class)->available($last->fresh()));
    }

    public function test_pending_and_expired_reservations_hold_stock_until_reconciliation(): void
    {
        $order = $this->checkout();
        $product = $order->items->first()->product;
        $this->notify($order, 'pending')->assertOk();
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'active']);
        $this->travel(16)->minutes();
        $this->artisan('stock:expire-reservations')->expectsOutput('Reservas vencidas: 1')->assertSuccessful();
        $this->artisan('stock:expire-reservations')->expectsOutput('Reservas vencidas: 0')->assertSuccessful();
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'expired']);
        $this->assertSame(6, app(StockReservations::class)->available($product));
        $this->artisan('stock:release-reservation', ['order' => $order->number])->assertFailed();
        $this->artisan('stock:release-reservation', [
            'order' => $order->number, '--verified' => true, '--reason' => 'Proveedor conciliado en simulación',
        ])->assertFailed();
        $this->assertSame(6, app(StockReservations::class)->available($product));
        $this->notify($order)->assertOk();
        $this->assertNotNull($order->fresh()->fulfillment_issue);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_late_payment_is_recorded_without_fulfillment_even_before_scheduler_runs(): void
    {
        $order = $this->checkout();
        $this->travel(16)->minutes();
        $this->notify($order)->assertOk();
        $this->notify($order)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->fulfillment_issue);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_expiry_holds_an_inflight_reservation_without_exposing_the_payment_url(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product(['stock' => 1]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1]);
        $this->mock(MercadoPagoService::class)->shouldReceive('createPreference')->once()->andReturnUsing(function () {
            $this->travel(16)->minutes();
            $this->assertSame(1, app(StockReservations::class)->expire());

            return ['id' => 'expired-pref', 'url' => 'https://example.test/pay'];
        });
        $response = $this->submit();
        $response->assertRedirect(route('account.orders.show', Order::firstOrFail()))->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('orders', ['mercadopago_preference_id' => 'expired-pref', 'mercadopago_checkout_url' => null]);
        $this->assertDatabaseHas('stock_reservations', ['product_id' => $product->id, 'status' => 'expired']);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_submitting_again_after_redirect_does_not_create_another_order(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt();
        $this->post('/checkout', $this->data(['attempt' => $attempt, 'shipping_address_line1' => 'Otra dirección']))
            ->assertSessionHasErrors('payment');
        $this->assertSame('Calle 123', $order->fresh()->shipping_address_line1);
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertRedirect('https://www.mercadopago.cl/checkout/test');
        $this->notify($order)->assertOk();
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect(route('account.orders.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_timeout_recovers_the_same_preference_and_webhook_retries_while_id_is_unknown(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/carrito', ['product_id' => $this->product()->id, 'quantity' => 1]);
        $attempt = $this->attempt();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('createPreference')->once()->andThrow(new \RuntimeException('timeout'));
        $this->submit(['attempt' => $attempt])->assertRedirect('/checkout');
        $order = Order::firstOrFail();
        $this->notify($order)->assertStatus(503);
        $this->assertDatabaseHas('checkout_attempts', ['order_id' => $order->id, 'state' => 'reconciliation_required']);
        $this->mock(MercadoPagoService::class)->shouldReceive('findPreference')->once()->andReturn(['id' => 'recovered', 'url' => 'https://example.test/recovered']);
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect('https://example.test/recovered');
        $this->notify($order)->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_modified_cart_and_other_user_cannot_use_a_draft(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1]);
        $attempt = $this->attempt();
        $this->actingAs(User::factory()->create())->post('/checkout', $this->data(['attempt' => $attempt]))->assertNotFound();
        $this->actingAs($buyer)->patch('/carrito/'.$product->id, ['quantity' => 2]);
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertSessionHasErrors('cart');
        $this->assertNotSame($attempt, $this->attempt());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_failure_persisting_the_preference_is_recovered_without_another_post(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/carrito', ['product_id' => $this->product()->id, 'quantity' => 1]);
        $attempt = $this->attempt();
        $failed = false;
        Order::saving(function (Order $order) use (&$failed) {
            if (! $failed && $order->isDirty('mercadopago_preference_id')) {
                $failed = true;
                throw new \RuntimeException('Simulated persistence failure');
            }
        });
        $provider = $this->mock(MercadoPagoService::class);
        $preference = ['id' => 'persist-recovery', 'url' => 'https://example.test/pay'];
        $provider->shouldReceive('createPreference')->once()->andReturn($preference);
        $provider->shouldReceive('findPreference')->once()->andReturn($preference);
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertRedirect('/checkout');
        $this->assertDatabaseHas('orders', ['mercadopago_preference_id' => null]);
        $this->assertDatabaseHas('stock_reservations', ['status' => 'active']);
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect($preference['url']);
        $this->assertDatabaseHas('orders', ['mercadopago_preference_id' => $preference['id']]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_unresolved_lookup_keeps_the_reservation_and_blocks_recreation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/carrito', ['product_id' => $this->product()->id, 'quantity' => 1]);
        $attempt = $this->attempt();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('createPreference')->once()->andThrow(new \RuntimeException('No response'));
        $provider->shouldReceive('findPreference')->once()->andReturnNull();
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertRedirect('/checkout');
        $this->travel(16)->minutes();
        $this->artisan('stock:expire-reservations')->assertSuccessful();
        $this->assertSame($attempt, $this->attempt());
        for ($request = 0; $request < 2; $request++) {
            $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect('/checkout');
        }
        $this->assertDatabaseHas('stock_reservations', ['status' => 'expired']);
        $this->assertDatabaseHas('checkout_attempts', ['state' => 'reconciliation_required']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_sdk_transport_does_not_retry_post_and_recovers_by_verified_reference(): void
    {
        config(['app.url' => 'https://example.test', 'services.mercadopago.access_token' => 'test-only']);
        Http::preventStrayRequests();
        Http::fake([
            '*/checkout/preferences' => Http::response(['message' => 'Unavailable'], 503),
            '*/checkout/preferences/search?*' => Http::response(['total' => 1, 'elements' => [['id' => 'found']]]),
            '*/checkout/preferences/found' => function () {
                $order = Order::firstOrFail();
                $reservation = app(StockReservations::class)->rows($order)->first();

                return Http::response(['id' => 'found', 'external_reference' => $order->number, 'expires' => true,
                    'expiration_date_to' => Carbon::parse($reservation->expires_at)->toIso8601String(),
                    'items' => $order->items->map(fn ($line) => ['id' => (string) $line->product_id, 'quantity' => $line->quantity,
                        'unit_price' => (float) $line->unit_price, 'currency_id' => 'CLP'])->all(),
                    'shipments' => ['cost' => (float) $order->shipping_cost],
                    'init_point' => 'https://example.test/found']);
            },
        ]);
        $this->actingAs(User::factory()->create());
        $this->post('/carrito', ['product_id' => $this->product()->id, 'quantity' => 1]);
        $attempt = $this->attempt();
        $this->post('/checkout', $this->data(['attempt' => $attempt]))->assertRedirect('/checkout');
        Http::assertSentCount(1);
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect('https://example.test/found');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), 'external_reference='.Order::firstOrFail()->number));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_expired_ready_attempt_remains_protected_and_never_returns_the_url(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt();
        $this->travel(16)->minutes();
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect(route('account.orders.show', $order));
        $this->artisan('stock:expire-reservations')->assertSuccessful();
        $this->artisan('stock:release-reservation', ['order' => $order->number, '--verified' => true, '--reason' => 'Prueba'])->assertFailed();
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'expired']);
        $this->post('/checkout', ['attempt' => $attempt, 'resume' => 1])->assertRedirect(route('account.orders.show', $order));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_refund_is_terminal_and_does_not_deduct_or_restore_stock(): void
    {
        $order = $this->checkout();
        $this->notify($order)->assertOk();
        $this->notify($order, 'refunded')->assertOk();
        $this->notify($order)->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(6, $order->items->first()->product->stock);
    }

    public function test_insufficient_stock_at_confirmation_flags_paid_order_for_review(): void
    {
        $order = $this->checkout();
        $order->items->first()->product->update(['stock' => 1]);
        $this->notify($order)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->fulfillment_issue);
        $this->assertNull($order->stock_deducted_at);
        $this->assertSame(1, $order->items->first()->product->stock);
    }
}
