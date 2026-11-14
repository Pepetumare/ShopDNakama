<?php

namespace Tests\Feature;

use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\StoreTestCase;

class PaymentReconciliationTest extends StoreTestCase
{
    public function test_reversals_preserve_preparation_history_and_block_further_shipping(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['refunded', 'charged_back'] as $status) {
            $order = $this->order();
            $id = (string) (9000 + $order->id);
            $this->notify($order, $id, 'approved')->assertOk();
            $this->patch(route('housekeeping.orders.status', $order), ['status' => 'preparando'])->assertSessionHasNoErrors();
            $this->notify($order, $id, $status)->assertOk();
            $this->assertSame('preparando', $order->fresh()->status);
            $this->assertNotEmpty($order->fresh()->fulfillment_issue);
            $this->patch(route('housekeeping.orders.status', $order), ['status' => 'enviado'])->assertSessionHasErrors('status');
            $this->assertDatabaseHas('payment_transactions', ['order_id' => $order->id, 'provider_status' => $status]);
        }
    }

    private function order(array $attributes = []): Order
    {
        $order = Order::create($attributes + ['customer_name' => 'Comprador', 'customer_email' => 'test@example.test',
            'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'mercadopago',
            'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'pref-'.$order->id])->save();
        $order->items()->create(['product_id' => $this->product(['name' => 'Producto '.$order->id])->id,
            'product_name' => 'Producto', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);

        return $order;
    }

    private function payment(Order $order, string $id, string $status, array $extra = []): array
    {
        return $extra + ['id' => $id, 'status' => $status, 'external_reference' => $order->number,
            'currency' => 'CLP', 'amount' => 10000, 'preference_id' => $order->mercadopago_preference_id];
    }

    private function notify(Order $order, string $id, string $status, array $extra = [])
    {
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->once()->with($id)
            ->andReturn($this->payment($order, $id, $status, $extra));
        config(['services.mercadopago.webhook_secret' => 'synthetic-com03']);
        $ts = (string) time();
        $hash = hash_hmac('sha256', "id:$id;request-id:com03;ts:$ts;", 'synthetic-com03');

        return $this->postJson('/webhooks/mercadopago?type=payment&data.id='.$id, [],
            ['x-request-id' => 'com03', 'x-signature' => "ts=$ts,v1=$hash"]);
    }

    public function test_rejected_then_approved_and_additional_payment_keep_individual_history(): void
    {
        $order = $this->order();
        $this->notify($order, '101', 'pending')->assertOk();
        $this->notify($order, '101', 'rejected')->assertOk();
        $this->notify($order, '102', 'approved')->assertOk();
        $this->notify($order, '102', 'approved')->assertOk();
        $this->notify($order, '103', 'approved')->assertOk();
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '101', 'provider_status' => 'rejected']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '103', 'incident' => 'additional_payment', 'resolved_at' => null]);
        $this->assertDatabaseCount('payment_transactions', 3);
        $this->assertDatabaseCount('payment_reconciliation_events', 5);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('102', $order->fresh()->mercadopago_payment_id);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->notify($order, '103', 'refunded')->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '103', 'provider_status' => 'refunded', 'resolved_at' => null]);
    }

    public function test_refunded_primary_rejects_stale_snapshot_and_another_approval(): void
    {
        $order = $this->order();
        $this->notify($order, '111', 'approved', ['updated_at' => '2026-10-01T10:00:00.125Z'])->assertOk();
        $this->notify($order, '111', 'refunded', ['updated_at' => '2026-10-01T10:00:00.300Z'])->assertOk();
        $this->notify($order, '111', 'approved', ['updated_at' => '2026-10-01T10:00:00.125Z'])->assertOk();
        $this->notify($order, '112', 'approved')->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_reconciliation_events', ['provider_payment_id' => '111', 'outcome' => 'stale_snapshot']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '112', 'incident' => 'payment_after_refund']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_cancelled_late_and_amount_mismatched_payments_are_visible_without_stock_movement(): void
    {
        $cancelled = $this->order(['status' => 'cancelado']);
        $this->notify($cancelled, '121', 'approved')->assertOk();
        $this->assertSame('cancelado', $cancelled->fresh()->status);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '121', 'incident' => 'cancelled_order']);
        $late = $this->order();
        app(StockReservations::class)->reserve($late, $late->items->toArray());
        $this->travel(16)->minutes();
        $this->notify($late, '122', 'approved')->assertOk();
        $this->assertSame('pending', $late->fresh()->status);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '122', 'incident' => 'late_payment']);
        $wrong = $this->order();
        $this->notify($wrong, '123', 'approved', ['amount' => 9900])->assertStatus(422);
        $this->notify($wrong, '124', 'approved', ['external_reference' => 'unknown'])->assertStatus(422);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '123', 'amount' => 9900, 'incident' => 'amount_mismatch']);
        $this->assertDatabaseMissing('payment_transactions', ['provider_payment_id' => '124']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_early_payment_is_recorded_and_review_recovers_only_the_verified_preference_id(): void
    {
        $order = $this->order();
        $order->forceFill(['mercadopago_preference_id' => null])->save();
        $attempt = CheckoutAttempt::create(['order_id' => $order->id, 'state' => CheckoutAttempt::UNCERTAIN,
            'token' => str_repeat('a', 64), 'cart_hash' => str_repeat('b', 64)]);
        app(StockReservations::class)->reserve($order, $order->items->toArray());
        $this->notify($order, '131', 'approved', ['preference_id' => 'verified'])->assertStatus(503);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '131', 'checkout_attempt_id' => $attempt->id]);
        $this->assertDatabaseHas('payment_reconciliation_events', ['provider_payment_id' => '131', 'outcome' => 'awaiting_preference']);
        $this->assertDatabaseCount('stock_movements', 0);

        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('findPreference')->once()->andReturn(['id' => 'verified', 'url' => 'https://example.test/pay']);
        $provider->shouldReceive('searchPayments')->once()->andReturn(['ids' => ['131'], 'next' => null]);
        $provider->shouldReceive('getPayment')->once()->with('131')->andReturn($this->payment($order, '131', 'approved', ['preference_id' => 'verified']));
        $this->assertSame('reviewed', app(PaymentReconciliation::class)->reviewOrder($order, 'scheduler')['outcome']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'mercadopago_preference_id' => 'verified', 'mercadopago_checkout_url' => null]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'consumed']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_admin_permissions_observation_and_manual_resolution_are_audited(): void
    {
        $order = $this->order();
        $this->notify($order, '141', 'approved', ['amount' => 9000])->assertStatus(422);
        $payment = PaymentTransaction::firstOrFail();
        $url = route('housekeeping.payments.annotate', $payment);
        $this->get(route('housekeeping.payments.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post($url, ['action' => 'resolve', 'note' => 'Prueba'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('housekeeping.payments.index'))->assertOk()->assertSee('141');
        $this->get(route('housekeeping.payments.show', $order))->assertOk()->assertSee('amount_mismatch');
        $this->post($url, ['action' => 'observe', 'note' => 'Observación de prueba'])->assertRedirect();
        $this->post($url, ['action' => 'resolve', 'note' => 'Sin confirmación'])->assertSessionHasErrors('verified');
        $this->post($url, ['action' => 'resolve', 'note' => 'Conciliación externa comprobada', 'verified' => 1])->assertRedirect();
        $this->post($url, ['action' => 'resolve', 'note' => 'Repetido', 'verified' => 1])->assertStatus(409);
        $this->assertDatabaseHas('payment_reconciliation_events', ['payment_transaction_id' => $payment->id, 'actor_id' => $admin->id, 'outcome' => 'resolved_manually']);
        $this->assertNotNull($payment->fresh()->resolved_at);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_legacy_check_is_read_only_and_review_records_errors_empty_results_and_lock_contention(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'status' => 'confirmed']);
        $order->forceFill(['mercadopago_payment_id' => '151', 'mercadopago_preference_id' => null])->save();
        $before = $order->fresh()->getAttributes();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->twice()->andReturnUsing(
            fn () => throw new \RuntimeException('Provider unavailable'),
            fn () => ['ids' => [], 'next' => null],
        );
        $provider->shouldReceive('getPayment')->twice()->with('151')->andReturn($this->payment($order, '151', 'refunded'));
        $service = app(PaymentReconciliation::class);
        $lock = Cache::lock('mercadopago-reconciliation-order:'.$order->id, 30);
        $this->assertTrue($lock->get());
        $this->assertSame('review_in_progress', $service->reviewOrder($order, 'scheduler')['outcome']);
        $lock->release();
        $this->assertSame('search_error', $service->reviewOrder($order, 'scheduler')['outcome']);
        $this->assertSame('reviewed', $service->reviewOrder($order, 'scheduler')['outcome']);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '151', 'provider_status' => 'refunded']);
        $this->assertDatabaseHas('payment_reconciliation_events', ['order_id' => $order->id, 'outcome' => 'search_empty']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_scheduler_does_not_release_an_uncertain_reservation_after_an_empty_search(): void
    {
        $order = $this->order();
        $order->forceFill(['mercadopago_preference_id' => null])->save();
        CheckoutAttempt::create(['order_id' => $order->id, 'state' => CheckoutAttempt::UNCERTAIN,
            'token' => str_repeat('c', 64), 'cart_hash' => str_repeat('d', 64)]);
        app(StockReservations::class)->reserve($order, $order->items->toArray());
        $this->travel(16)->minutes();
        $this->artisan('stock:expire-reservations')->assertSuccessful();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('findPreference')->once()->andReturnNull();
        $provider->shouldReceive('searchPayments')->once()->andReturn(['ids' => [], 'next' => null]);
        $this->artisan('payments:reconcile')->expectsOutput('Pedidos revisados: 1')->assertSuccessful();
        $this->artisan('payments:reconcile')->expectsOutput('Pedidos revisados: 0')->assertSuccessful();
        $this->assertDatabaseHas('payment_reconciliation_events', ['order_id' => $order->id, 'outcome' => 'preference_unresolved']);
        $this->assertDatabaseHas('payment_reconciliation_events', ['order_id' => $order->id, 'outcome' => 'search_empty']);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'expired', 'released_at' => null]);
    }

    public function test_sdk_search_rechecks_payment_details_and_validates_the_optional_raw_preference(): void
    {
        $order = $this->order();
        config(['services.mercadopago.access_token' => 'test-only']);
        Http::preventStrayRequests();
        Http::fake([
            '*/v1/payments/search?*' => Http::response(['results' => [['id' => 161]], 'paging' => ['total' => 1]]),
            '*/v1/payments/161' => Http::response(['id' => 161, 'status' => 'approved', 'transaction_amount' => 10000,
                'currency_id' => 'CLP', 'external_reference' => $order->number, 'preference_id' => 'incorrect']),
        ]);
        $this->assertSame('review_incomplete', app(PaymentReconciliation::class)->reviewOrder($order, 'scheduler')['outcome']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), 'external_reference='.$order->number));
        $this->assertDatabaseHas('payment_reconciliation_events', ['order_id' => $order->id, 'outcome' => 'preference_mismatch']);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }
}
