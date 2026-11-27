<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\StoreTestCase;

class ProviderTimestampCompatibilityTest extends StoreTestCase
{
    private function reserve(): Order
    {
        $order = Order::create(['subtotal' => 100, 'total' => 100, 'payment_method' => 'mercadopago']);
        $order->forceFill(['mercadopago_preference_id' => 'test-pref'])->save();
        foreach (['Uno', 'Dos'] as $name) {
            $product = $this->product(['name' => $name, 'stock' => 1]);
            $order->items()->create(['product_id' => $product->id, 'product_name' => $name, 'quantity' => 1,
                'unit_price' => 50, 'line_total' => 50]);
        }
        app(StockReservations::class)->reserve($order, $order->items->toArray());

        return $order;
    }

    private function observe(Order $order, string $status, ?string $approved, ?string $updated = null): void
    {
        $id = (string) $order->id;
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->once()->with($id)
            ->andReturn(['id' => $id, 'status' => $status, 'amount' => 100, 'currency' => 'CLP',
                'external_reference' => $order->number, 'preference_id' => 'test-pref',
                'approved_at' => $approved, 'updated_at' => $updated]);
        $result = app(PaymentReconciliation::class)->verify($id, 'webhook', (string) Str::uuid());
        $this->assertSame(200, $result['http']);
        $order->refresh();
    }

    #[TestWith(['1970-01-01T00:00:00.000Z', null])]
    #[TestWith(['1970-01-01T00:00:00.001Z', '1970-01-01 00:00:00.001'])]
    #[TestWith(['1970-01-01T00:00:00.999Z', '1970-01-01 00:00:00.999'])]
    #[TestWith(['1970-01-01T00:00:01.000Z', '1970-01-01 00:00:01.000'])]
    #[TestWith(['2038-01-19T03:14:07.999999Z', '2038-01-19 03:14:07.999'])]
    #[TestWith(['2038-01-19T03:14:08.000Z', null])]
    public function test_provider_update_boundary_keeps_verified_approval(string $date, ?string $stored): void
    {
        if (DB::connection() instanceof MySqlConnection && ! DB::connection()->isMaria()
            && str_starts_with($date, '1970-01-01T00:00:00.')) {
            $stored = null;
        }
        $order = $this->reserve();
        $this->observe($order, 'approved', now()->toIso8601String(), $date);
        $this->observe($order, 'approved', now()->toIso8601String(), $date);
        $transaction = $order->paymentTransactions()->sole();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($stored, $transaction->provider_updated_at?->format('Y-m-d H:i:s.v'));
        $this->assertSame($stored === null, $order->fulfillment_issue !== null);
        $this->assertDatabaseCount('stock_movements', $stored === null ? 0 : 2);
        if ($stored === null) {
            $event = PaymentReconciliationEvent::where('payment_transaction_id', $transaction->id)->latest('id')->firstOrFail();
            $this->assertSame($date, $event->provider_date_evidence['updated_at']['value']);
            $this->assertSame('invalid_provider_update_date', $transaction->incident);
            $this->assertNull($transaction->resolved_at);
            $this->assertSame(2, DB::table('stock_reservations')->where('order_id', $order->id)->where('status', 'active')->count());
            $this->expectException(ValidationException::class);
            $order->assertFulfillable();
        }
    }

    #[TestWith(['refunded'])]
    #[TestWith(['charged_back'])]
    public function test_out_of_range_snapshot_orders_retries_and_reversals(string $terminal): void
    {
        $order = $this->reserve();
        DB::table('stock_reservations')->where('order_id', $order->id)->update(['absolute_window' => false]);
        $this->observe($order, 'approved', '2040-02-29T00:00:00Z', '2040-02-29T00:00:00Z');
        $this->observe($order, $terminal, null, '2030-02-28T00:00:00Z');
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->fulfillment_issue);
        $this->observe($order, $terminal, null, '2040-03-01T00:00:00.000900Z');
        $other = $terminal === 'refunded' ? 'charged_back' : 'refunded';
        $this->observe($order, $other, null, '2040-03-01T00:00:00.000100Z');
        $this->observe($order, $other, null);
        $this->observe($order, 'approved', null, '2040-03-02T00:00:00Z');
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame($terminal, $order->paymentTransactions()->sole()->provider_status);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
