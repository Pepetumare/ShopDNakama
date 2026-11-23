<?php

namespace Tests\Feature;

use App\Models\CatalogPaymentCursor;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomPaymentRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentLeaseFencingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires disposable MariaDB 127.0.0.1:33317/nakama_com01_test.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame(33317, (int) config('database.connections.mysql.port'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->assertStringContainsString('aud004', DB::selectOne('select @@datadir as d')->d);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    #[TestWith([false, 'slow-refund'])]
    #[TestWith([true, 'slow-refund'])]
    #[TestWith([false, 'slow-error'])]
    #[TestWith([true, 'slow-error'])]
    public function test_expired_http_result_cannot_write_financial_state_or_current_checkpoint(bool $custom, string $mode): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'status' => 'pending', 'payment_status' => 'pending', 'total' => 10000, 'subtotal' => 10000]);
        if ($custom) {
            $user = User::factory()->create();
            $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
                'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
            $order->forceFill(['custom_request_id' => $quote->id, 'user_id' => $user->id])->save();
        }
        $order->forceFill(['mercadopago_preference_id' => 'lease-pref-'.$order->id])->save();
        $order->items()->create(['product_name' => 'Lease fixture', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        if ($custom) {
            app(CustomPaymentRecovery::class)->locked($order, fn ($locked, $intent) => $intent->update(['state' => 'ready']));
        }
        $gate = sys_get_temp_dir().'/aud004-fix-'.Str::uuid();
        $worker = fn ($mode) => new Process([PHP_BINARY, base_path('tests/Support/payment-lease-fencing-worker.php'),
            (string) $order->id, $mode, $gate], base_path(), timeout: 25);
        $old = $worker($mode);
        try {
            $old->start();
            $this->assertTrue($old->waitUntil(fn ($type, $out) => str_contains($out, 'get_entered')));
            DB::statement('SET SESSION innodb_lock_wait_timeout=2');
            DB::transaction(fn () => Order::whereKey($order->id)->lockForUpdate()->firstOrFail());
            CatalogPaymentCursor::where('order_id', $order->id)->update(['lease_expires_at' => now()->subSecond()]);
            $new = $worker('takeover');
            $new->mustRun();
            $this->assertSame('paid', $order->fresh()->payment_status);
            $cursor = DB::table('catalog_payment_cursors')->where('order_id', $order->id)->first();
            $work = DB::table('catalog_payment_work')->where('order_id', $order->id)->first();
            $intent = $custom ? $order->customPaymentIntent()->firstOrFail()->getAttributes() : null;
            $events = DB::table('payment_reconciliation_events')->where('order_id', $order->id)->count();
            touch($gate);
            $this->assertSame(0, $old->wait(), $old->getErrorOutput());
            $this->assertStringContainsString('review_superseded', $old->getOutput());
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->assertEquals($cursor, DB::table('catalog_payment_cursors')->where('order_id', $order->id)->first());
            $this->assertEquals($work, DB::table('catalog_payment_work')->where('order_id', $order->id)->first());
            $this->assertSame($events, DB::table('payment_reconciliation_events')->where('order_id', $order->id)->count());
            if ($custom) {
                $this->assertSame($intent, $order->customPaymentIntent()->firstOrFail()->getAttributes());
            }
            $this->assertSame('approved', $order->paymentTransactions()->sole()->provider_status);
        } finally {
            $old->stop();
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }
}
