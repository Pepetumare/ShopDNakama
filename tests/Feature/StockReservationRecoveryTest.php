<?php

namespace Tests\Feature;

use App\Models\CatalogPaymentCursor;
use App\Models\CatalogPaymentWork;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\StoreTestCase;

class StockReservationRecoveryTest extends StoreTestCase
{
    private function order(): Order
    {
        return Order::create(['payment_method' => 'mercadopago', 'subtotal' => 100, 'total' => 100]);
    }

    private function approve(Order $order, string $instant): void
    {
        $order->forceFill(['mercadopago_preference_id' => 'test-pref'])->save();
        $id = (string) $order->id;
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->once()->with($id)
            ->andReturn(['id' => $id, 'status' => 'approved', 'amount' => 100, 'currency' => 'CLP',
                'preference_id' => 'test-pref', 'external_reference' => $order->number, 'approved_at' => $instant]);
        app(PaymentReconciliation::class)->verify($id, 'webhook', (string) Str::uuid());
        $this->assertSame('paid', $order->refresh()->payment_status);
    }

    #[TestWith(['2026-02-30T12:01:00Z', false])]
    #[TestWith(['2026-03-02T24:00:00Z', false])]
    #[TestWith(['2026-03-02T12:60:00Z', false])]
    #[TestWith(['2026-03-02T12:01:60Z', false])]
    #[TestWith(['2026-03-02T12:01:00-00:00', false])]
    #[TestWith(['2026-03-02T12:01:00+01:60', false])]
    #[TestWith(['2026-03-02T12:01:00+14:01', false])]
    #[TestWith(['2026-03-02T12:01:00', false])]
    #[TestWith(['2026-03-02T12:15:00.000001Z', false])]
    #[TestWith(['2026-03-02T09:15:00-03:00', false])]
    #[TestWith(['2026-03-03T02:14:59.999999999+14:00', true])]
    #[TestWith(['2026-03-02T12:14:59.9Z', true])]
    public function test_approval_window(string $instant, bool $sale): void
    {
        $this->travelTo(Carbon::parse('2026-03-02T12:00:00Z'));
        $order = $this->order();
        $product = $this->product(['stock' => 1]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Test', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $reservations = app(StockReservations::class);
        $reservations->reserve($order, $order->items->toArray());
        $this->travel(20)->minutes();
        $reservations->expire();
        $this->approve($order, $instant);

        $this->assertSame($sale ? 'consumed' : 'expired', $reservations->rows($order)->first()->status);
        $this->assertSame($sale ? 0 : 1, $product->fresh()->stock);
        $this->assertSame($sale, $order->fulfillment_issue === null);
        $this->assertDatabaseCount('stock_movements', $sale ? 1 : 0);
    }

    public function test_invalid_approval_date_cannot_authorize_an_active_reservation(): void
    {
        $order = $this->order();
        $product = $this->product(['stock' => 1]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Test', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        app(StockReservations::class)->reserve($order, $order->items->toArray());
        $this->approve($order, '2026-02-30T12:01:00Z');

        $this->assertDatabaseHas('payment_transactions', ['order_id' => $order->id, 'provider_status' => 'approved',
            'approved_at' => null, 'incident' => 'invalid_approval_date']);
        $this->assertNotNull($order->fulfillment_issue);
        $this->assertNull($order->stock_deducted_at);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_second_unbacked_sku_blocks_the_entire_active_order(): void
    {
        $order = $this->order();
        $first = $this->product(['name' => 'First', 'stock' => 5]);
        $second = $this->product(['name' => 'Second', 'stock' => 1]);
        foreach ([$first, $second] as $product) {
            $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name,
                'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]);
        }
        $reservations = app(StockReservations::class);
        $reservations->reserve($order, $order->items->toArray());
        $reservations->reserve($this->order(), [['product_id' => $second->id, 'quantity' => 1]]);
        $this->approve($order, now()->toIso8601String());

        $this->assertNotEmpty($order->fulfillment_issue);
        $this->assertSame(5, $first->fresh()->stock);
        $this->assertSame(1, $second->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(['active'], $reservations->rows($order)->pluck('status')->unique()->values()->all());
    }

    private function expiredAttempt(string $state): array
    {
        $order = $this->order();
        $service = app(StockReservations::class);
        $service->reserve($order, [['product_id' => $this->product()->id, 'quantity' => 1]]);
        $this->travel(20)->minutes();
        $service->expire();
        $attempt = CheckoutAttempt::create(['order_id' => $order->id, 'state' => $state,
            'token' => bin2hex(random_bytes(32)), 'cart_hash' => str_repeat('a', 64)]);

        return [$order, $attempt];
    }

    public function test_listing_includes_simultaneous_holds_without_changing_the_decision(): void
    {
        [$order] = $this->expiredAttempt(CheckoutAttempt::UNCERTAIN);
        $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => '300', 'currency' => 'CLP',
            'amount' => 100, 'provider_status' => 'in_process', 'incident' => 'amount_mismatch',
            'last_verified_at' => now(), 'correlation_id' => (string) Str::uuid()]);
        CatalogPaymentCursor::create(['order_id' => $order->id, 'search_failures' => 1, 'next_run_at' => now()]);
        CatalogPaymentWork::create(['order_id' => $order->id, 'payment_id' => '301', 'next_attempt_at' => now()]);
        Artisan::call('stock:held-reservations');
        $line = Artisan::output();
        foreach (['Pago pendiente', 'Incidencia financiera abierta', 'Checkout incierto', 'Preferencia posiblemente publicada',
            'Búsqueda remota fallida', 'Pago descubierto sin verificación'] as $reason) {
            $this->assertStringContainsString($reason, $line);
        }
        $this->assertNotNull(app(StockReservations::class)->recover($order)['hold']);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'expired']);
    }

    #[TestWith([254])]
    #[TestWith([255])]
    public function test_cli_preserves_the_complete_unicode_reference(int $length): void
    {
        [$order, $attempt] = $this->expiredAttempt(CheckoutAttempt::NOT_SENT);
        $note = str_repeat('🧾', $length - 4).' FIN';
        $args = ['order' => $order->number, '--verified' => true, '--reason' => $note];
        $this->artisan('stock:release-reservation', $args)->assertSuccessful();
        $rows = app(StockReservations::class);
        $saved = $rows->rows($order)->first();
        $this->assertSame("Preferencia no enviada (intento {$attempt->id}). ".$note, $saved->release_reason);
        $args['--reason'] = 'Otro motivo';
        $this->artisan('stock:release-reservation', $args)->assertSuccessful();
        $this->assertEquals($saved, $rows->rows($order)->first());
    }

    public function test_final_field_capacity_is_checked_before_any_release(): void
    {
        [$order, $attempt] = $this->expiredAttempt(CheckoutAttempt::NOT_SENT);
        $prefix = "Preferencia no enviada (intento {$attempt->id}). ";
        $fits = str_repeat('ñ', 320 - mb_strlen($prefix));
        $service = app(StockReservations::class);
        $before = $service->rows($order)->first();
        $result = $service->recover($order, $fits.'ñ');
        $this->assertSame(0, $result['released']);
        $this->assertStringContainsString('320', $result['hold']);
        $this->assertEquals($before, $service->rows($order)->first());
        $this->assertSame(1, $service->recover($order, $fits)['released']);
        $this->assertSame($prefix.$fits, $service->rows($order)->first()->release_reason);
    }
}
