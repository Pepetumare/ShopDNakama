<?php

namespace Tests\Feature;

use App\Models\CatalogPaymentCursor;
use App\Models\CatalogPaymentWork;
use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Tests\StoreTestCase;

class CustomPaymentDurableQueueTest extends StoreTestCase
{
    private function order(): Order
    {
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
        $order = app(CustomQuoteCheckout::class)->orderFor($quote, $user, [
            'shipping_address_line1' => 'Queue 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM']);
        $order->forceFill(['mercadopago_preference_id' => 'queue-pref'])->save();
        $order->customPaymentIntent()->update(['state' => CustomPaymentIntent::READY, 'preference_id' => 'queue-pref',
            'checkout_url' => 'https://www.mercadopago.cl/queue-fake', 'financial_review_required' => true,
            'financial_next_review_at' => now()->subMinute()]);

        return $order;
    }

    public function test_permanent_failure_is_durable_while_later_approval_is_applied_once_and_link_stays_held(): void
    {
        $order = $this->order();
        // Exercise real stock idempotence as well as custom money handling.
        $product = $this->product(['stock' => 3]);
        $order->items()->firstOrFail()->update(['product_id' => $product->id]);
        $offsets = [];
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->andReturnUsing(function ($order, $offset) use (&$offsets) {
            $offsets[] = $offset;

            return ['ids' => $offset === 0 ? array_map('strval', range(1001, 1010)) : ['1011'], 'next' => $offset === 0 ? 10 : null];
        });
        $provider->shouldReceive('getPayment')->andReturnUsing(function ($id) use ($order) {
            if ($id === '1002') {
                throw new \RuntimeException('Permanent detail failure');
            }

            return ['id' => $id, 'external_reference' => $order->number, 'preference_id' => 'queue-pref',
                'currency' => 'CLP', 'amount' => $order->total, 'status' => $id === '1011' ? 'approved' : 'pending'];
        });
        $service = app(PaymentReconciliation::class);
        $service->reviewBatch();
        $this->assertSame(10, CatalogPaymentCursor::sole()->offset);
        $this->assertSame(10, $order->customPaymentIntent()->value('payment_offset'));
        $this->assertDatabaseCount('catalog_payment_work', 10);
        $this->assertDatabaseHas('catalog_payment_pages', ['offset' => 0, 'state' => 'pending']);
        $this->travel(16)->minutes();
        $service->reviewBatch();
        $this->assertSame([0, 10], $offsets);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertFalse(CatalogPaymentCursor::sole()->scan_open);
        $this->assertDatabaseHas('catalog_payment_work', ['payment_id' => '1002', 'last_http' => null, 'verification_version' => 0]);
        $this->assertTrue(CatalogPaymentWork::where('payment_id', '1002')->sole()->next_attempt_at->gt(now()));
        $this->assertTrue($order->customPaymentIntent()->firstOrFail()->financial_review_required);
        $this->assertTrue($order->fresh()->hasCustomPaymentHold());
        $this->assertSame(1, $order->paymentTransactions()->where('provider_payment_id', '1011')->count());
        $this->assertDatabaseCount('stock_movements', 1);
        $stock = $product->fresh()->stock;
        $this->travel(3)->hours();
        $service->reviewBatch();
        $service->reviewOrder($order->fresh(), 'admin');
        $this->assertSame($stock, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, $order->paymentTransactions()->where('provider_payment_id', '1011')->count());
    }

    public function test_unknown_unverified_id_alone_blocks_checkout_without_a_financial_transaction(): void
    {
        $order = $this->order();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->andReturn(['ids' => ['1002'], 'next' => null]);
        $provider->shouldReceive('getPayment')->with('1002')->andThrow(new \RuntimeException('Unknown money cannot be verified'));
        app(PaymentReconciliation::class)->reviewOrder($order, 'scheduler');
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertTrue($order->fresh()->hasCustomPaymentHold());
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertRedirect(route('account.custom-requests.show', $order->customRequest));
        $this->assertTrue($order->customPaymentIntent()->firstOrFail()->financial_review_required);
        $this->assertDatabaseCount('catalog_payment_work', 1);
    }
}
