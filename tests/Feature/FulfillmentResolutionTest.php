<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Support\Str;
use Tests\StoreTestCase;

class FulfillmentResolutionTest extends StoreTestCase
{
    private function order(): Order
    {
        $order = Order::create(['customer_name' => 'Test', 'customer_email' => 'test@example.test',
            'payment_status' => 'paid', 'status' => 'pending', 'subtotal' => 100, 'total' => 100]);
        $order->items()->create(['product_id' => $this->product(['stock' => 0])->id, 'product_name' => 'Assigned',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $order->forceFill(['stock_deducted_at' => now(), 'fulfillment_issue' => 'Original stock incident'])->save();

        return $order;
    }

    public function test_resolution_retains_history_is_idempotent_and_allows_preparation(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach ([1, 2] as $attempt) {
            $this->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Assignment checked'])->assertSessionHasNoErrors();
        }
        $this->assertNull($order->fresh()->fulfillment_issue);
        $this->assertDatabaseCount('payment_reconciliation_events', 1);
        $event = $order->getConnection()->table('payment_reconciliation_events')->first();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertNotNull($event->created_at);
        $this->assertStringContainsString('Original stock incident', $event->note);
        $this->assertStringContainsString('Assignment checked', $event->note);
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'preparando'])->assertSessionHasNoErrors();
    }

    public function test_invalid_financial_or_inventory_state_keeps_the_issue(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['payment_status' => 'refunded'], ['payment_status' => 'charged_back'],
            ['payment_status' => 'rejected'], ['payment_status' => 'pending'],
            ['stock_deducted_at' => null], ['stock_restored_at' => now()]] as $state) {
            $order = $this->order();
            $order->forceFill($state)->save();
            $this->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Check'])->assertSessionHasErrors('status');
            $this->assertSame('Original stock incident', $order->fresh()->fulfillment_issue);
        }
        $this->assertDatabaseCount('payment_reconciliation_events', 0);
    }

    public function test_multiple_incidents_require_individual_resolution_before_unblocking(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $service = app(PaymentReconciliation::class);
        foreach (['111', '222'] as $id) {
            $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => $id,
                'amount' => 100, 'currency' => 'CLP', 'provider_status' => 'approved', 'incident' => 'additional_payment',
                'last_verified_at' => now(), 'correlation_id' => (string) Str::uuid()]);
        }
        $payments = $order->paymentTransactions()->get();
        $service->annotate($payments[0], $admin->id, 'Verified first', true);
        $this->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Check'])->assertSessionHasErrors('status');
        $service->annotate($payments[1], $admin->id, 'Verified second', true);
        $this->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Check'])->assertSessionHasNoErrors();
        $this->assertSame(2, $order->paymentTransactions()->whereNotNull('incident')->count());
        $this->assertDatabaseCount('payment_reconciliation_events', 3);
    }

    public function test_non_admin_cannot_resolve_and_reason_is_required(): void
    {
        $order = $this->order();
        $url = route('housekeeping.orders.resolve-fulfillment', $order);
        $this->post($url, ['note' => 'Check'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post($url, ['note' => 'Check'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, [])->assertSessionHasErrors('note');
        $this->assertNotNull($order->fresh()->fulfillment_issue);
    }

    public function test_unconsumed_reservation_cannot_be_cleared_by_resolution(): void
    {
        $order = $this->order();
        app(StockReservations::class)->reserve($order, $order->items->toArray());
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('housekeeping.orders.resolve-fulfillment', $order), ['note' => 'Check'])
            ->assertSessionHasErrors('status');
        $this->assertNotNull($order->fresh()->fulfillment_issue);
        $this->assertDatabaseCount('payment_reconciliation_events', 0);
    }
}
