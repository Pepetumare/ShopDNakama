<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\StockReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentReconciliationConcurrencyTest extends TestCase
{
    public function test_concurrent_notifications_and_reviews_share_a_single_commercial_application(): void
    {
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL and the dedicated nakama_com01_test database.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $order = Order::create(['customer_name' => 'Race', 'customer_email' => 'race@example.test',
            'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'mercadopago', 'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'reconciliation-'.$order->id])->save();
        $category = Category::create(['name' => 'Race '.Str::uuid()]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Race product', 'stock' => 1, 'price' => 10000]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Race product', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        app(StockReservations::class)->reserve($order, $order->items->toArray());
        $firstId = (string) ($order->id * 10 + 1);
        $otherId = (string) ($order->id * 10 + 2);
        $script = base_path('tests/Support/payment-reconciliation-worker.php');
        $workers = array_map(fn ($id) => new Process([PHP_BINARY, $script, (string) $order->id, $id], base_path(), timeout: 30),
            [$firstId, $firstId, $otherId]);
        $gate = sys_get_temp_dir().'/nakama-com03-'.Str::uuid();
        try {
            DB::beginTransaction();
            try {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'waiting-for-order')), $worker->getErrorOutput());
                }
            } finally {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
            }
            $this->assertSame(2, DB::table('payment_transactions')->where('order_id', $order->id)->count());
            $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $order->id)->where('incident', 'additional_payment')->count());
            $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->count());
            $this->assertSame(0, $product->fresh()->stock);

            $review = new Process([PHP_BINARY, $script, (string) $order->id, 'review', $gate], base_path(), timeout: 30);
            $other = new Process([PHP_BINARY, $script, (string) $order->id, 'review'], base_path(), timeout: 30);
            $workers[] = $review;
            $workers[] = $other;
            $review->start();
            $this->assertTrue($review->waitUntil(fn ($type, $output) => str_contains($output, 'search-started')), $review->getErrorOutput());
            $other->mustRun();
            $this->assertStringContainsString('review_in_progress', $other->getOutput());
            touch($gate);
            $this->assertSame(0, $review->wait(), $review->getErrorOutput());
            $this->assertStringContainsString('reviewed', $review->getOutput());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }
}
