<?php

namespace Tests\Feature;

use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomPaymentPending;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\StoreTestCase;

/** Regression specifications from the independent audit; failures intentionally expose defects. */
class Aud001AdversarialReviewTest extends StoreTestCase
{
    private function order(): Order
    {
        config(['services.mercadopago.collector_id' => '42', 'services.mercadopago.shipping_cost' => 3990]);
        $buyer = User::factory()->create();
        $quote = $buyer->customRequests()->create(['product_type' => 'mug', 'quantity' => 1,
            'customer_name' => $buyer->name, 'customer_email' => $buyer->email, 'status' => 'quoted', 'quoted_price' => 10000]);

        return app(CustomQuoteCheckout::class)->orderFor($quote, $buyer, [
            'shipping_address_line1' => 'Audit 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM',
        ]);
    }

    private function detail(Order $order, string $id): array
    {
        $intent = $order->customPaymentIntent()->firstOrFail();

        return ['id' => $id, 'init_point' => 'https://www.mercadopago.cl/audit/'.$id,
            'external_reference' => $order->number, 'collector_id' => 42,
            'metadata' => ['order_id' => $order->id, 'custom_payment_intent' => $intent->token],
            'items' => $intent->snapshot['items'], 'shipments' => ['cost' => 3990]];
    }

    public function test_old_early_approved_payment_remains_scheduled_after_recovery_and_transient_payment_read_failure(): void
    {
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subDays(8)])->save();
        $order->customPaymentIntent()->update(['state' => CustomPaymentIntent::UNCERTAIN,
            'sending_at' => now()->subDays(8), 'preference_id' => 'audit-real']);
        $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => '991',
            'custom_payment_intent_id' => $order->customPaymentIntent->id, 'amount' => $order->total,
            'currency' => 'CLP', 'provider_status' => 'approved', 'approved_at' => now()->subDays(8),
            'last_verified_at' => now()->subDays(8), 'correlation_id' => (string) Str::uuid()]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('audit-real')->once()->andReturn($this->detail($order, 'audit-real'));
        $provider->shouldReceive('searchPayments')->andReturn(['ids' => [], 'next' => null]);
        $provider->shouldReceive('getPayment')->with('991')->andThrow(new \RuntimeException('Controlled transient read failure'));
        $service = app(PaymentReconciliation::class);
        $this->assertSame(1, $service->reviewBatch());
        $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->travel(3)->hours();

        // Must remain discoverable until the financially observed approval is applied or explicitly resolved.
        $this->assertSame(1, $service->reviewBatch());
    }

    public function test_admin_can_verify_a_correct_id_when_the_persisted_unverified_id_is_wrong(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => CustomPaymentIntent::UNCERTAIN,
            'sending_at' => now()->subMinutes(5), 'preference_id' => 'audit-wrong']);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('audit-wrong')->andThrow(new \RuntimeException('Controlled missing preference'));
        $provider->shouldReceive('customPreference')->with('audit-correct')->andReturn($this->detail($order, 'audit-correct'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('housekeeping.payments.custom-intent', $order), [
            'note' => 'Verified merchant support reference AUD-001-independent', 'verified' => 1, 'preference_id' => 'audit-correct',
        ])->assertSessionHasNoErrors();

        $this->assertSame('audit-correct', $order->fresh()->mercadopago_preference_id);
    }

    public function test_additional_payment_from_another_preference_is_retained_as_a_financial_incident(): void
    {
        $order = $this->order();
        $order->forceFill(['mercadopago_preference_id' => 'audit-selected', 'mercadopago_payment_id' => '990',
            'payment_status' => 'paid', 'status' => 'confirmed'])->save();
        $this->mock(MercadoPagoService::class, function (MockInterface $provider) use ($order) {
            $provider->shouldReceive('getPayment')->with('992')->once()->andReturn([
                'id' => '992', 'external_reference' => $order->number, 'amount' => $order->total,
                'currency' => 'CLP', 'status' => 'approved', 'preference_id' => 'audit-other',
                'approved_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);
        });
        $result = app(PaymentReconciliation::class)->verify('992', 'webhook', (string) Str::uuid());
        $this->assertSame('preference_mismatch', $result['result']);
        $this->assertSame('990', $order->fresh()->mercadopago_payment_id);

        // Reject commercial application while preserving the independently verified money movement.
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '992',
            'order_id' => $order->id, 'provider_status' => 'approved', 'incident' => 'preference_mismatch']);
    }

    public function test_browser_recovery_does_not_release_checkout_while_an_early_approval_is_unapplied(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => CustomPaymentIntent::SENDING, 'sending_at' => now()]);
        $reads = 0;
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->with('993')->andReturnUsing(function () use ($order, &$reads) {
            if (++$reads > 1) {
                throw new ConnectionException('Controlled inability to reverify current financial state');
            }

            return ['id' => '993', 'external_reference' => $order->number, 'amount' => $order->total,
                'currency' => 'CLP', 'status' => 'approved', 'preference_id' => null,
                'approved_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];
        });
        $provider->shouldReceive('customPreferencePage')->andReturn(['ids' => ['audit-real'], 'total' => 1, 'next' => null]);
        $provider->shouldReceive('customPreference')->with('audit-real')->andReturn($this->detail($order, 'audit-real'));
        $result = app(PaymentReconciliation::class)->verify('993', 'webhook', (string) Str::uuid());
        $this->assertSame(503, $result['http']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '993', 'provider_status' => 'approved']);
        $this->travel(3)->minutes();
        $url = null;
        try {
            $url = app(CustomQuoteCheckout::class)->checkoutUrl($order, $order->user, $provider);
        } catch (CustomPaymentPending|ConnectionException) {
            // Conservative result required until the observed approval is reconciled.
        }

        $this->assertNull($url, 'An already observed approval must not lead the buyer back to a payable preference.');
    }
}
