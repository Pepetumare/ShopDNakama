<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\StoreTestCase;

class FinancialTimestampTest extends StoreTestCase
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

    public static function windows(): iterable
    {
        foreach (['+00:00', '+01:00', '-03:00'] as $zone) {
            yield "antes, $zone" => [$zone, '2028-02-29T12:14:59Z', true];
            yield "antes con fracción, $zone" => [$zone, '2028-02-29T13:14:59.999999999+01:00', true];
            yield "límite, $zone" => [$zone, '2028-02-29T09:15:00-03:00', false];
            yield "tardío, $zone" => [$zone, '2028-02-29T13:05:00Z', false];
        }
        yield 'escritura en −03 y lectura en +01' => ['+01:00', '2028-02-29T12:14:59Z', true, '-03:00'];
    }

    #[DataProvider('windows')]
    public function test_absolute_reservation_window(string $zone, string $approved, bool $sale, string $writeZone = '+00:00'): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requiere sesiones MariaDB/MySQL.');
        }
        $phpZone = date_default_timezone_get();
        $sqlZone = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
        try {
            config(['app.timezone' => 'Asia/Tokyo']);
            date_default_timezone_set('Asia/Tokyo');
            DB::statement('SET SESSION time_zone = ?', [$writeZone]);
            $start = Carbon::parse('2028-02-29T12:00:00Z');
            $this->travelTo($start);
            $order = $this->reserve();
            DB::statement('SET SESSION time_zone = ?', [$zone]);
            $rows = app(StockReservations::class)->rows($order);
            foreach ($rows as $row) {
                $this->assertSame($start->timestamp, (int) $row->start_epoch);
                $this->assertSame($start->timestamp + 900, (int) $row->end_epoch);
                $this->assertSame('2028-02-29T12:15:00+00:00', $row->expires_at);
            }
            $this->travelTo($start->copy()->addMinutes(90));
            app(StockReservations::class)->expire();
            $this->observe($order, 'approved', $approved);
            $this->observe($order, 'approved', $approved);
            $this->assertSame($zone, DB::selectOne('SELECT @@session.time_zone AS zone')->zone);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame(1, $order->paymentTransactions()->count());
            $stored = DB::table('payment_transactions')->where('order_id', $order->id)
                ->selectRaw('UNIX_TIMESTAMP(approved_at) AS approved_epoch, UNIX_TIMESTAMP(last_verified_at) AS checked_epoch')->first();
            $instant = Carbon::parse($approved);
            $this->assertEquals($instant->copy()->floorMillisecond()->format('U.v'), $stored->approved_epoch);
            $this->assertSame($start->timestamp + 5400, (int) $stored->checked_epoch);
            $this->assertSame($sale, $order->stock_deducted_at !== null);
            $this->assertSame($sale, $order->fulfillment_issue === null);
            $this->assertDatabaseCount('stock_movements', $sale ? 2 : 0);
            foreach ($order->items as $item) {
                $this->assertSame($sale ? 0 : 1, $item->product->stock);
            }
            if ($sale) {
                $order->assertFulfillable();
            } else {
                $this->expectException(ValidationException::class);
                $order->assertFulfillable();
            }
        } finally {
            date_default_timezone_set($phpZone);
            DB::statement('SET SESSION time_zone = ?', [$sqlZone]);
        }
    }

    #[TestWith(['2040-02-29T12:10:00Z', 'outside_sql_range', 'refunded'])]
    #[TestWith(['2026-02-30T12:01:00Z', 'invalid_format', 'charged_back'])]
    public function test_unusable_approval_is_durable_and_reversible(string $date, string $reason, string $reversal): void
    {
        $this->travelTo(Carbon::parse('2028-02-29T12:00:00Z'));
        $order = $this->reserve();
        $this->travel(90)->minutes();
        app(StockReservations::class)->expire();
        $this->observe($order, 'approved', $date, $date);
        $this->observe($order, 'approved', $date, $date);
        $tx = $order->paymentTransactions()->sole();
        $this->assertSame('approved', $tx->provider_status);
        $this->assertSame((string) $order->id, $tx->provider_payment_id);
        $this->assertSame('100.00', $tx->amount);
        $this->assertSame('CLP', $tx->currency);
        $this->assertSame('test-pref', $tx->preference_id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull($tx->approved_at);
        $this->assertNull($tx->provider_updated_at);
        $this->assertNull($tx->resolved_at);
        $this->assertSame('invalid_approval_date', $tx->incident);
        $this->assertNotEmpty($order->fulfillment_issue);
        $events = PaymentReconciliationEvent::where('payment_transaction_id', $tx->id)->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            foreach (['approved_at', 'updated_at'] as $field) {
                $this->assertSame($date, $event->provider_date_evidence[$field]['value']);
                $this->assertSame($reason, $event->provider_date_evidence[$field]['reason']);
            }
        }
        $this->observe($order, $reversal, null);
        $this->observe($order, 'approved', $date);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame($reversal, $tx->fresh()->provider_status);
        $this->assertNotNull($order->fulfillment_issue);
        $this->assertNull($order->stock_deducted_at);
        $this->assertDatabaseCount('stock_movements', 0);
        foreach ($order->items as $item) {
            $this->assertSame(1, $item->product->stock);
        }
        $this->expectException(ValidationException::class);
        $order->assertFulfillable();
    }

    #[TestWith(['1969-12-31T23:00:00.001-01:00', '1970-01-01 00:00:00.001'])]
    #[TestWith(['1970-01-01T00:00:00.000999Z', null])]
    #[TestWith(['1970-01-01T00:00:01Z', '1970-01-01 00:00:01.000'])]
    #[TestWith(['2038-01-19T04:14:07.999999+01:00', '2038-01-19 03:14:07.999'])]
    #[TestWith(['2038-01-19T03:14:08Z', null])]
    #[TestWith(['1970-01-01T00:00:00Z', null])]
    public function test_provider_update_sql_range(string $updated, ?string $stored): void
    {
        // MariaDB 10.11 can store epoch + 1 ms; MySQL 8.4 starts at epoch + 1 s.
        if ($stored === '1970-01-01 00:00:00.001' && DB::connection() instanceof MySqlConnection
            && ! DB::connection()->isMaria()) {
            $stored = null;
        }
        $order = $this->reserve();
        $this->observe($order, 'approved', now()->toIso8601String(), $updated);
        $tx = $order->paymentTransactions()->sole();
        $this->assertSame((string) $order->id, $tx->provider_payment_id);
        $this->assertSame($tx->provider_payment_id, $order->mercadopago_payment_id);
        $this->assertSame('approved', $tx->provider_status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($stored, $tx->provider_updated_at?->format('Y-m-d H:i:s.v'));
        $this->assertSame($stored ? null : 'invalid_provider_update_date', $tx->incident);
        $this->assertSame($stored !== null, $order->fulfillment_issue === null);
        $this->assertDatabaseCount('stock_movements', $stored ? 2 : 0);
        if ($stored === null) {
            $event = PaymentReconciliationEvent::where('payment_transaction_id', $tx->id)->latest('id')->firstOrFail();
            $this->assertSame($updated, $event->provider_date_evidence['updated_at']['value']);
            $this->assertSame('outside_sql_range', $event->provider_date_evidence['updated_at']['reason']);
            $this->assertNull($tx->resolved_at);
            $this->assertSame(2, DB::table('stock_reservations')->where('order_id', $order->id)->where('status', 'active')->count());
            $this->expectException(ValidationException::class);
            $order->assertFulfillable();
        }
    }

    public function test_mysql_connectors_restore_utc_on_reconnection(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requiere MariaDB.');
        }
        foreach (['mysql', 'mariadb'] as $driver) {
            $options = DB::connection()->getConfig();
            $options['driver'] = $driver;
            $options['timezone'] = config("database.connections.$driver.timezone");
            config(['database.connections.clock_probe' => $options]);
            try {
                $probe = DB::connection('clock_probe');
                $firstId = $probe->selectOne('SELECT CONNECTION_ID() AS id')->id;
                $this->assertSame('+00:00', $probe->selectOne('SELECT @@session.time_zone AS zone')->zone);
                $probe->statement("SET SESSION time_zone = '-03:00'");
                DB::reconnect('clock_probe');
                $this->assertNotEquals($firstId, $probe->selectOne('SELECT CONNECTION_ID() AS id')->id);
                $this->assertSame('+00:00', $probe->selectOne('SELECT @@session.time_zone AS zone')->zone);
            } finally {
                DB::purge('clock_probe');
            }
        }
    }

    public function test_an_open_date_incident_survives_later_preference_linkage(): void
    {
        $order = $this->reserve();
        $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => (string) $order->id,
            'provider_status' => 'approved', 'amount' => 100, 'currency' => 'CLP', 'incident' => 'invalid_approval_date',
            'last_verified_at' => now(), 'correlation_id' => (string) Str::uuid()]);
        $this->observe($order, 'approved', null);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotEmpty($order->fulfillment_issue);
        $this->assertNull($order->paymentTransactions()->sole()->resolved_at);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unproven_historical_window_remains_held(): void
    {
        $order = $this->reserve();
        DB::table('stock_reservations')->where('order_id', $order->id)->update(['absolute_window' => false]);
        $this->observe($order, 'approved', now()->toIso8601String());
        $this->assertSame('unverified_reservation_window', $order->paymentTransactions()->sole()->incident);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull($order->stock_deducted_at);
        $this->assertNotEmpty($order->fulfillment_issue);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
