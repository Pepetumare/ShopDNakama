<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\StoreTestCase;

class PaymentShippingAmountTest extends StoreTestCase
{
    public static function amounts(): array
    {
        return [
            'separate shipping' => [10000, 3990, 3990, 13990, true],
            'free shipping' => [10000, 0, 0, 10000, true],
            'shipping absent' => [10000, null, 0, 10000, true],
            'financing is not order value' => [10000, 3990, 3990, 16000, true],
            'provider coupon does not change order value' => [10000, 3990, 3990, 12990, true],
            'underpayment' => [9000, 3990, 3990, 13990, false],
            'overpayment' => [11000, 3990, 3990, 13990, false],
            'compensating wrong shipping' => [11000, 2990, 3990, 13990, false],
        ];
    }

    private function fakePayment(Order $order, mixed $amount, mixed $shipping, int $paid): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['services.mercadopago.access_token' => 'test-only']);
        Http::fake(['*/v1/payments/321' => Http::response([
            'id' => 321, 'status' => 'approved', 'transaction_amount' => $amount,
            'currency_id' => 'CLP', 'external_reference' => $order->number,
            'preference_id' => $order->mercadopago_preference_id,
            'transaction_details' => ['total_paid_amount' => $paid],
        ] + ($shipping === null ? [] : ['shipping_amount' => $shipping]))]);
    }

    #[DataProvider('amounts')]
    public function test_provider_amounts_are_reconciled_without_buyer_financing(mixed $amount, mixed $shipping, int $expectedShipping, int $paid, bool $valid): void
    {
        $order = Order::create(['customer_name' => 'Test', 'customer_email' => 'test@example.test',
            'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'mercadopago',
            'subtotal' => 10000, 'shipping_cost' => $expectedShipping, 'total' => 10000 + $expectedShipping]);
        $order->forceFill(['mercadopago_preference_id' => 'pref-test'])->save();
        $order->items()->create(['product_id' => $this->product()->id, 'product_name' => 'Test', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        $this->fakePayment($order, $amount, $shipping, $paid);
        $service = app(PaymentReconciliation::class);
        $result = $service->verify('321', 'test', (string) Str::uuid());
        $this->assertSame($valid ? 200 : 422, $result['http']);
        $this->assertSame($valid ? 'paid' : 'pending', $order->fresh()->payment_status);
        $service->verify('321', 'test', (string) Str::uuid());
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame($valid ? 7 : 8, $order->items->first()->product->fresh()->stock);
    }

    public function test_custom_payment_can_resume_only_after_audited_resolution_and_fresh_valid_amounts(): void
    {
        config(['services.mercadopago.shipping_cost' => 3990]);
        $buyer = User::factory()->create();
        $quote = $buyer->customRequests()->create(['customer_name' => 'Test', 'customer_email' => $buyer->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
        $order = app(CustomQuoteCheckout::class)->orderFor($quote, $buyer, ['shipping_address_line1' => 'Test', 'shipping_city' => 'Test', 'shipping_region' => 'Test']);
        $order->forceFill(['mercadopago_preference_id' => 'pref-test'])->save();
        $order->customPaymentIntent()->update(['state' => 'ready', 'preference_id' => 'pref-test']);
        $service = app(PaymentReconciliation::class);
        $this->fakePayment($order, 10000, null, 13990);
        $this->assertSame(422, $service->verify('321', 'test', (string) Str::uuid())['http']);
        $this->fakePayment($order, 10000, 3990, 13990);
        $this->assertSame('held_for_review', $service->verify('321', 'test', (string) Str::uuid())['result']);
        $admin = User::factory()->create(['role' => 'admin']);
        $service->annotate(PaymentTransaction::firstOrFail(), $admin->id, 'Verified separate shipping', true);
        $this->assertSame('updated', $service->verify('321', 'test', (string) Str::uuid())['result']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('approved', $quote->fresh()->status);
        $this->assertDatabaseHas('payment_reconciliation_events', ['outcome' => 'resolved_manually', 'actor_id' => $admin->id]);
        $this->fakePayment($order, 9000, 3990, 13990);
        $this->assertSame(422, $service->verify('321', 'test', (string) Str::uuid())['http']);
        $this->assertNull(PaymentTransaction::firstOrFail()->resolved_at);
    }

    public static function invalidAmounts(): array
    {
        return [[-1], ['invalid'], [true], [0.001], [1000000000000]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_shipping_fails_closed(mixed $shipping): void
    {
        config(['services.mercadopago.access_token' => 'test-only']);
        Http::fake(['*' => Http::response(['id' => 321, 'transaction_amount' => 10000, 'shipping_amount' => $shipping])]);
        $this->expectException(\Throwable::class);
        app(MercadoPagoService::class)->getPayment('321');
    }
}
