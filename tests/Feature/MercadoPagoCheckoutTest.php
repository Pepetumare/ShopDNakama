<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\MercadoPagoService;
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

    private function checkout(): Order
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $this->mock(MercadoPagoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createPreference')->once()->andReturn(['id' => 'pref-1', 'url' => 'https://www.mercadopago.cl/checkout/test']);
        });
        $this->post('/checkout', $this->data())->assertRedirect('https://www.mercadopago.cl/checkout/test');

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
        $this->post('/checkout', $this->data())->assertSessionHasErrors('cart');
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
        $this->post('/checkout', $this->data(['total' => 1, 'subtotal' => 1, 'shipping_cost' => 0, 'payment_status' => 'paid', 'price' => 1]))->assertRedirect();
        $this->assertDatabaseHas('orders', ['status' => 'pending', 'payment_status' => 'pending', 'total' => 21990, 'mercadopago_preference_id' => 'pref-1']);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_approved_webhook_confirms_payment_and_deducts_stock_once(): void
    {
        $order = $this->checkout();
        $this->notify($order)->assertOk();
        $this->notify($order)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmado', $order->status);
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertNotNull($order->stock_deducted_at);
        $this->assertSame(6, $order->items->first()->product->stock);
        $this->get('/mi-cuenta/pedidos/'.$order->id)->assertOk()->assertSee('Mercado Pago')->assertSee('123');
    }

    public function test_rejected_webhook_does_not_mark_paid_or_change_stock(): void
    {
        $order = $this->checkout();
        $this->notify($order, 'rejected')->assertOk();
        $this->assertSame('rejected', $order->fresh()->payment_status);
        $this->assertSame(8, $order->items->first()->product->stock);
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
        $product->update(['stock' => 1]);
        $this->post('/checkout', $this->data())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_preference_failure_preserves_pending_order_and_cart(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1]);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('createPreference')->once()->andThrow(new \RuntimeException('API unavailable')));
        $this->post('/checkout', $this->data())->assertRedirect('/checkout')->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending', 'mercadopago_preference_id' => null]);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_submitting_again_after_redirect_does_not_create_another_order(): void
    {
        $this->checkout();
        $this->post('/checkout', $this->data())->assertSessionHasErrors('cart');
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
