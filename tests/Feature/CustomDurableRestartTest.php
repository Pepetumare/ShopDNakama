<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomPaymentRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomDurableRestartTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_failed_custom_detail_survives_real_process_restarts_and_later_approval(bool $permanent): void
    {
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
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
        $order = Order::create(['payment_method' => 'mercadopago', 'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['custom_request_id' => $quote->id, 'user_id' => $user->id, 'mercadopago_preference_id' => 'restart-'.$order->id])->save();
        $category = Category::create(['name' => 'Restart test', 'slug' => 'restart-'.Str::uuid()]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Restart stock', 'price' => 10000, 'stock' => 3]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        app(CustomPaymentRecovery::class)->locked($order, fn ($locked, $intent) => $intent->update(['state' => 'ready']));
        $worker = fn ($mode) => new Process([PHP_BINARY, base_path('tests/Support/custom-durable-restart-worker.php'),
            (string) $order->id, $mode], base_path(), timeout: 25);
        $base = 70000000 + $order->id * 1000;
        $pending = (string) ($base + 2);
        $first = $worker('fail');
        $first->mustRun(); // Exits completely before any parent assertions or second process.
        $ids = DB::table('catalog_payment_work')->where('order_id', $order->id)->orderBy('payment_id')->pluck('payment_id')->all();
        $this->assertSame(array_map(fn ($i) => (string) ($base + $i), range(1, 10)), $ids);
        $work = DB::table('catalog_payment_work')->where('order_id', $order->id)->where('payment_id', $pending)->first();
        $this->assertNull($work->last_http);
        $this->assertSame(0, (int) $work->verification_version);
        $this->assertTrue(now()->parse($work->next_attempt_at)->gt(now()));
        $this->assertDatabaseHas('catalog_payment_cursors', ['order_id' => $order->id, 'offset' => 10]);
        $this->assertDatabaseHas('catalog_payment_pages', ['order_id' => $order->id, 'offset' => 0, 'state' => 'pending']);
        $this->assertSame(10, $order->customPaymentIntent()->value('payment_offset'));
        $this->assertTrue($order->customPaymentIntent()->firstOrFail()->financial_review_required);
        $this->assertTrue($order->fresh()->hasCustomPaymentHold());
        $this->assertSame(9, $order->paymentTransactions()->count());
        $this->assertSame(3, $product->fresh()->stock);
        // Advance only the isolated retry clock, not a financial result or cursor.
        DB::table('catalog_payment_work')->where('order_id', $order->id)->where('payment_id', $pending)
            ->update(['next_attempt_at' => now()->subSecond()]);
        $second = $worker($permanent ? 'fail' : 'success');
        $second->mustRun();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_transactions', ['order_id' => $order->id,
            'provider_payment_id' => (string) ($base + 11), 'provider_status' => 'approved']);
        $this->assertDatabaseHas('catalog_payment_cursors', ['order_id' => $order->id, 'scan_open' => false]);
        $this->assertSame(0, $order->customPaymentIntent()->value('payment_offset'));
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame($permanent, $order->customPaymentIntent()->firstOrFail()->financial_review_required);
        $this->assertTrue($order->fresh()->hasCustomPaymentHold());
        if ($permanent) {
            $this->assertDatabaseHas('catalog_payment_work', ['order_id' => $order->id, 'payment_id' => $pending, 'last_http' => null]);
            $this->assertDatabaseHas('catalog_payment_pages', ['order_id' => $order->id, 'offset' => 0, 'state' => 'pending']);
            DB::table('catalog_payment_work')->where('order_id', $order->id)->where('payment_id', $pending)
                ->update(['next_attempt_at' => now()->subSecond()]);
        }
        $worker('success')->mustRun();
        $this->assertDatabaseHas('catalog_payment_work', ['order_id' => $order->id, 'payment_id' => $pending, 'last_http' => 200, 'failures' => 0]);
        $this->assertSame(0, DB::table('catalog_payment_pages')->where('order_id', $order->id)->where('state', 'pending')->count());
        $this->assertFalse($order->customPaymentIntent()->firstOrFail()->financial_review_required);
        $this->assertSame(11, $order->paymentTransactions()->count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->count());
    }
}
