<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\ManageCustomQuote;
use App\Services\MercadoPagoService;
use App\Services\PreferenceNotSent;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\StoreTestCase;

class CustomQuotePaymentTest extends StoreTestCase
{
    private MockInterface $mp;

    public function test_custom_order_fulfillment_requires_payment_and_no_incident(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'preparando'])->assertSessionHasErrors('status');
        $this->notify($order)->assertOk();
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'preparando'])->assertSessionHasNoErrors();
        $order->refresh()->forceFill(['fulfillment_issue' => 'Revisar producción'])->save();
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'enviado'])->assertSessionHasErrors('status');
        try {
            app(ManageCustomQuote::class)->update($quote->fresh(), ['status' => 'in_production']);
            $this->fail('Production must be blocked by the incident.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Producción revisada'])
            ->assertSessionHasNoErrors();
        app(ManageCustomQuote::class)->update($quote->fresh(), ['status' => 'in_production']);
        $this->assertSame('in_production', $quote->fresh()->status);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.shipping_cost' => 3990, 'services.mercadopago.webhook_secret' => 'custom-secret']);
        // An unconfigured method is an error, never a real network request.
        $this->mp = $this->mock(MercadoPagoService::class);
    }

    private function quote(?User $user = null, array $attributes = []): CustomRequest
    {
        $user ??= User::factory()->create();

        return $user->customRequests()->create(array_merge([
            'customer_name' => $user->name, 'customer_email' => $user->email,
            'customer_phone' => '912345678', 'product_type' => 'mug', 'variant' => 'white',
            'quantity' => 3, 'status' => 'quoted', 'quoted_price' => 10000, 'admin_notes' => 'NOTA_INTERNA_PRIVADA',
        ], $attributes));
    }

    private function delivery(array $extra = []): array
    {
        return array_merge(['shipping_address_line1' => 'Calle Nakama 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana'], $extra);
    }

    private function accept(CustomRequest $quote): Order
    {
        $this->mp->shouldReceive('createPreference')->once()->andReturn(['id' => 'custom-pref', 'url' => 'https://www.mercadopago.cl/custom-checkout']);
        $this->actingAs($quote->user)->post(route('account.custom-requests.accept', $quote), $this->delivery())->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/custom-checkout');

        return $quote->order()->firstOrFail();
    }

    private function notify(Order $order, string $status = 'approved', array $extra = [])
    {
        $payment = array_merge(['id' => '777', 'external_reference' => $order->number, 'status' => $status, 'amount' => $order->total, 'currency' => 'CLP'], $extra);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('getPayment')->with('777')->andReturn($payment));
        $ts = (string) time();
        $signature = hash_hmac('sha256', "id:777;request-id:custom-notification;ts:$ts;", 'custom-secret');

        return $this->postJson('/webhooks/mercadopago?data.id=777', ['type' => 'payment', 'data' => ['id' => '777']], ['x-request-id' => 'custom-notification', 'x-signature' => "ts=$ts,v1=$signature"]);
    }

    public function test_owner_sees_quote_price_and_button_only_when_quoted(): void
    {
        $quote = $this->quote();
        $this->actingAs($quote->user);
        foreach (array_keys(CustomRequest::STATUSES) as $status) {
            $quote->update(['status' => $status]);
            $response = $this->get(route('account.custom-requests.show', $quote))->assertOk()->assertSee('$10.000')->assertDontSee('NOTA_INTERNA_PRIVADA');
            if ($status === 'quoted') {
                $response->assertSee('Aceptar cotización y pagar')->assertSee('Rechazar cotización')->assertSee('$13.990');
            } else {
                $response->assertDontSee('Aceptar cotización y pagar')->assertDontSee('Rechazar cotización');
            }
        }
    }

    public function test_custom_manufacturing_does_not_create_physical_stock_movements(): void
    {
        $order = $this->accept($this->quote());
        $this->notify($order)->assertOk();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertNull($order->fresh()->stock_deducted_at);
    }

    public function test_custom_order_linked_to_existing_product_deducts_and_audits_once(): void
    {
        $product = $this->product(['stock' => 8]);
        $order = $this->accept($this->quote());
        $order->items()->first()->update(['product_id' => $product->id, 'quantity' => 3]);
        $this->notify($order)->assertOk();
        $this->notify($order)->assertOk();
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['type' => 'sale', 'order_id' => $order->id, 'quantity' => -3]);
    }

    public function test_guests_other_users_and_admins_cannot_consent_for_owner(): void
    {
        $quote = $this->quote();
        foreach (['accept', 'decline'] as $action) {
            $this->post(route('account.custom-requests.'.$action, $quote), $this->delivery())->assertRedirect(route('login'));
        }
        foreach ([User::factory()->create(), User::factory()->create(['role' => 'admin'])] as $user) {
            $this->actingAs($user)->get(route('account.custom-requests.show', $quote))->assertForbidden();
            foreach (['accept', 'decline'] as $action) {
                $this->post(route('account.custom-requests.'.$action, $quote), $this->delivery())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_price_or_state_cannot_be_accepted(): void
    {
        $quote = $this->quote();
        $this->actingAs($quote->user);
        foreach ([['quoted_price' => null], ['quoted_price' => 0], ['quoted_price' => -1], ['quoted_price' => 10000, 'status' => 'pending'], ['status' => 'approved'], ['status' => 'cancelled']] as $data) {
            $quote->update($data);
            $this->post(route('account.custom-requests.accept', $quote), $this->delivery())->assertSessionHasErrors('quote');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_acceptance_uses_database_price_and_configured_shipping_and_creates_pending_lot(): void
    {
        $quote = $this->quote();
        $product = $this->product();
        $this->mp->shouldReceive('createPreference')->once()->withArgs(fn (Order $order) => $order->custom_request_id === $quote->id && $order->items->count() === 1 && (float) $order->total === 13990.0)->andReturn(['id' => 'custom-pref', 'url' => 'https://www.mercadopago.cl/custom-checkout']);
        $this->actingAs($quote->user)->post(route('account.custom-requests.accept', $quote), $this->delivery(['quoted_price' => 1, 'price' => 1, 'total' => 1, 'shipping_cost' => 0, 'payment_status' => 'paid', 'user_id' => 999]))->assertSessionHasNoErrors()->assertRedirect();
        $order = $quote->order()->firstOrFail();
        $this->assertSame($quote->user_id, $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('mercadopago', $order->payment_method);
        $this->assertSame('10000.00', $order->subtotal);
        $this->assertSame('3990.00', $order->shipping_cost);
        $this->assertSame('13990.00', $order->total);
        $this->assertSame('Calle Nakama 123', $order->shipping_address_line1);
        $this->assertSame('custom-pref', $order->mercadopago_preference_id);
        $item = $order->items->first();
        $this->assertNull($item->product_id);
        $this->assertSame('Personalizado '.$quote->request_number, $item->product_name);
        $this->assertStringContainsString('Cantidad solicitada: 3', $item->description);
        $this->assertSame(1, $item->quantity);
        $this->assertSame('10000.00', $item->unit_price);
        $this->assertSame('quoted', $quote->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_double_acceptance_reuses_one_order_one_item_and_one_preference(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->post(route('account.custom-requests.accept', $quote))->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/custom-checkout');
        $this->get(route('account.custom-requests.show', $quote))->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame($order->id, $quote->order()->first()->id);
    }

    public function test_confirmed_preflight_failure_can_retry_the_same_pending_order(): void
    {
        $quote = $this->quote();
        $this->mp->shouldReceive('createPreference')->once()->andThrow(new PreferenceNotSent('Local preflight only'));
        $this->actingAs($quote->user)->post(route('account.custom-requests.accept', $quote), $this->delivery())->assertRedirect(route('account.custom-requests.show', $quote))->assertSessionHasErrors('payment');
        $order = $quote->order()->firstOrFail();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->mercadopago_preference_id);
        $this->mock(MercadoPagoService::class, fn (MockInterface $mock) => $mock->shouldReceive('createPreference')->once()->andReturn(['id' => 'recovered-pref', 'url' => 'https://www.mercadopago.cl/recovered']));
        $this->post(route('account.custom-requests.accept', $quote))->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/recovered');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($order->id, $quote->order()->first()->id);
    }

    public function test_existing_preference_without_cached_url_is_recovered_without_recreation(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $order->forceFill(['mercadopago_checkout_url' => null])->save();
        // The durable intent now also caches the verified URL; losing the order cache needs no HTTP.
        $this->post(route('account.custom-requests.accept', $quote))->assertRedirect('https://www.mercadopago.cl/custom-checkout');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_approved_webhook_pays_order_and_approves_quote_once_without_stock_changes(): void
    {
        $quote = $this->quote(null, ['product_type' => '3d_print', 'project_name' => 'Figura Nakama']);
        $product = $this->product();
        $order = $this->accept($quote);
        $this->notify($order)->assertOk();
        $approvedAt = $quote->fresh()->updated_at;
        $this->travel(1)->minutes();
        $this->notify($order)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('777', $order->fresh()->mercadopago_payment_id);
        $this->assertSame('approved', $quote->fresh()->status);
        $this->assertTrue($quote->fresh()->updated_at->equalTo($approvedAt));
        $this->assertTrue($quote->fresh()->ready_for_production);
        $this->assertNull($order->fresh()->stock_deducted_at);
        $this->assertNull($order->fresh()->fulfillment_issue);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
        $this->travelBack();
    }

    public function test_rejected_payment_leaves_quote_unapproved_and_can_reuse_preference(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->notify($order, 'rejected')->assertOk();
        $this->assertSame('rejected', $order->fresh()->payment_status);
        $this->assertSame('quoted', $quote->fresh()->status);
        $this->post(route('account.custom-requests.accept', $quote))->assertRedirect('https://www.mercadopago.cl/custom-checkout');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_success_url_and_invalid_webhook_cannot_approve_quote(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->get('/checkout/mercadopago/success?external_reference='.$order->number.'&status=approved')->assertOk();
        foreach ([['amount' => 1], ['currency' => 'USD'], ['external_reference' => 'not-this-order']] as $extra) {
            $this->notify($order, 'approved', $extra)->assertStatus(422);
        }
        $this->postJson('/webhooks/mercadopago?data.id=777', ['type' => 'payment', 'data' => ['id' => '777']])->assertUnauthorized();
        $this->assertSame('quoted', $quote->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_user_can_decline_quote_without_creating_an_order(): void
    {
        $quote = $this->quote();
        $this->actingAs($quote->user)->post(route('account.custom-requests.decline', $quote))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('cancelled', $quote->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->post(route('account.custom-requests.accept', $quote), $this->delivery())->assertSessionHasErrors('quote');
    }

    public function test_customer_can_decline_a_legacy_quoted_request_without_price(): void
    {
        $quote = $this->quote(null, ['quoted_price' => null]);
        $this->actingAs($quote->user)->get(route('account.custom-requests.show', $quote))->assertOk()->assertSee('Rechazar cotización')->assertDontSee('Aceptar cotización y pagar');
        $this->post(route('account.custom-requests.decline', $quote))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $quote->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_late_payment_for_declined_pending_order_is_flagged_without_approving_request(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->post(route('account.custom-requests.decline', $quote))->assertSessionHasNoErrors();
        $this->notify($order)->assertOk();
        $this->assertSame('cancelled', $quote->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('cancelado', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->fulfillment_issue);
        $this->assertFalse($quote->fresh()->ready_for_production);
    }

    public function test_admin_sees_associated_order_and_payment_customer_sees_it_in_order_history(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->get('/mi-cuenta/pedidos')->assertOk()->assertSee('Personalizado '.$quote->request_number)->assertSee($order->number);
        $this->get(route('account.orders.show', $order))->assertOk()->assertSee('Ver solicitud personalizada')->assertSee('Personalizado '.$quote->request_number);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.custom-requests.show', $quote))->assertOk()->assertSee($order->number)->assertSee('Cotización pendiente de pago')->assertSee('Mercado Pago Payment ID');
        $this->notify($order)->assertOk();
        $this->get(route('housekeeping.custom-requests.show', $quote))->assertOk()->assertSee('Pago confirmado')->assertSee('Pagado — listo para producción')->assertSee('777');
        $this->get('/housekeeping/personalizados')->assertOk()->assertSee('Pagado — listo para producción');
    }

    public function test_admin_price_automatically_quotes_and_must_be_positive(): void
    {
        $quote = $this->quote(null, ['status' => 'reviewing', 'quoted_price' => null]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([0, -1] as $price) {
            $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => 'reviewing', 'quoted_price' => $price])->assertSessionHasErrors('quoted_price');
        }
        $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => 'reviewing', 'quoted_price' => 15000])->assertSessionHasNoErrors();
        $this->assertSame('quoted', $quote->fresh()->status);
        $this->assertSame('15000.00', $quote->fresh()->quoted_price);
    }

    public function test_admin_cannot_change_accepted_price_or_produce_before_confirmed_payment(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => 'quoted', 'quoted_price' => 20000])->assertSessionHasErrors('quoted_price');
        foreach (['approved', 'in_production', 'ready', 'completed'] as $status) {
            $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => $status])->assertSessionHasErrors('status');
        }
        $this->assertSame('10000.00', $quote->fresh()->quoted_price);
        $this->assertSame('10000.00', $order->fresh()->subtotal);
    }

    public function test_paid_request_can_follow_production_flow_and_duplicate_webhook_does_not_rewind_it(): void
    {
        $quote = $this->quote(null, ['product_type' => '3d_print']);
        $order = $this->accept($quote);
        $this->notify($order)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['in_production', 'ready', 'completed'] as $status) {
            $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => $status, 'admin_notes' => 'Seguimiento privado'])->assertSessionHasNoErrors();
            $this->notify($order)->assertOk();
            $this->assertSame($status, $quote->fresh()->status);
        }
        $this->patch(route('housekeeping.custom-requests.update', $quote), ['status' => 'quoted'])->assertSessionHasErrors('status');
        $this->actingAs($quote->user)->post(route('account.custom-requests.decline', $quote))->assertSessionHasErrors('quote');
        $this->get(route('account.custom-requests.show', $quote))->assertOk()->assertDontSee('Seguimiento privado');
    }

    public function test_refunded_order_is_not_payable_or_ready_for_production(): void
    {
        $quote = $this->quote();
        $order = $this->accept($quote);
        $this->notify($order)->assertOk();
        $this->notify($order, 'refunded')->assertOk();
        $this->assertFalse($quote->fresh()->ready_for_production);
        $this->post(route('account.custom-requests.accept', $quote))->assertSessionHasErrors('quote');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('housekeeping.custom-requests.update', $quote), ['status' => 'in_production'])->assertSessionHasErrors('status');
    }

    public function test_database_rejects_a_second_order_for_the_same_request(): void
    {
        $quote = $this->quote();
        $this->accept($quote);
        $this->expectException(QueryException::class);
        $quote->order()->create(['user_id' => $quote->user_id]);
    }
}
