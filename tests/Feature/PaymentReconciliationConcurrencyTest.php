<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentReconciliationConcurrencyTest extends TestCase
{
    public function test_two_recovery_processes_release_a_proven_unsent_reservation_once(): void
    {
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MariaDB.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame(33317, (int) config('database.connections.mysql.port'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $order = Order::create(['payment_method' => 'mercadopago', 'payment_status' => 'pending',
            'subtotal' => 100, 'total' => 100]);
        $category = Category::create(['name' => 'Recovery '.Str::uuid()]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Hold', 'price' => 100, 'stock' => 1]);
        CheckoutAttempt::create(['order_id' => $order->id, 'state' => CheckoutAttempt::NOT_SENT,
            'token' => bin2hex(random_bytes(32)), 'cart_hash' => str_repeat('a', 64)]);
        $reservation = app(StockReservations::class);
        $reservation->reserve($order, [['product_id' => $product->id, 'quantity' => 1]]);
        DB::table('stock_reservations')->where('order_id', $order->id)
            ->update(['status' => 'expired', 'expires_at' => now()->subMinute()]);
        $command = [PHP_BINARY, base_path('tests/Support/payment-reconciliation-worker.php'), (string) $order->id, 'recover'];
        $workers = [new Process($command, base_path()), new Process($command, base_path())];
        try {
            DB::beginTransaction();
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // One worker waits on the order, the other on the attempt it already owns.
            foreach ($workers as $worker) {
                $worker->start();
            }
            $this->assertTrue($workers[0]->waitUntil(fn ($type, $line) => str_contains($line, 'starting-recovery')));
            $this->assertTrue($workers[1]->waitUntil(fn ($type, $line) => str_contains($line, 'starting-recovery')));
            DB::rollBack();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim(last(explode("\n", trim($worker->getOutput()))));
            }
            $this->assertEqualsCanonicalizing(['released=0', 'released=1'], $results);
            $this->assertSame(1, $reservation->available($product));
            $this->assertSame(0, DB::table('stock_movements')->where('order_id', $order->id)->count());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_annotation_serializes_with_housekeeping_without_retrying(): void
    {
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::create(['customer_name' => 'Race', 'customer_email' => 'race@example.test',
            'status' => 'preparando', 'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100]);
        $order->items()->create(['product_name' => 'Historical', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $order->forceFill(['stock_deducted_at' => now()])->save();
        $payment = $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => (string) ($order->id * 100),
            'amount' => 100, 'currency' => 'CLP', 'provider_status' => 'approved', 'last_verified_at' => now(), 'correlation_id' => (string) Str::uuid()]);
        $gate = sys_get_temp_dir().'/aud003-'.Str::uuid();
        $command = [PHP_BINARY, base_path('tests/Support/order-status-worker.php'), (string) $order->id, (string) $admin->id];
        $annotation = new Process([...$command, 'annotate', $gate], base_path(), timeout: 30);
        $advance = new Process([...$command, 'advance'], base_path(), timeout: 30);
        try {
            $annotation->start();
            $this->assertTrue($annotation->waitUntil(fn ($type, $output) => str_contains($output, 'annotation-held')));
            $advance->start();
            $this->assertTrue($advance->waitUntil(fn ($type, $output) => str_contains($output, 'waiting-for-order')));
            touch($gate);
            foreach ([$annotation, $advance] as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertSame(1, substr_count($worker->getOutput(), 'waiting-for-order'));
            }
            $this->assertSame('enviado', $order->fresh()->status);
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->assertDatabaseHas('payment_reconciliation_events', ['order_id' => $order->id,
                'payment_transaction_id' => $payment->id, 'actor_id' => $admin->id, 'outcome' => 'observation']);
        } finally {
            $annotation->stop();
            $advance->stop();
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }

    #[TestWith(['transition'])]
    #[TestWith(['resolve'])]
    public function test_administrative_update_waits_and_rechecks_a_concurrent_refund(string $operation): void
    {
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $admin = User::factory()->create(['role' => 'admin']);
        $order = Order::create(['customer_name' => 'Race', 'customer_email' => 'race@example.test',
            'status' => 'preparando', 'payment_status' => 'paid', 'subtotal' => 10000, 'total' => 10000]);
        $order->items()->create(['product_name' => 'Historical', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        $order->forceFill(['stock_deducted_at' => now(), 'fulfillment_issue' => $operation === 'resolve' ? 'Verify assignment' : null])->save();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/order-status-worker.php'), (string) $order->id, (string) $admin->id, $operation], base_path(), timeout: 30);
        try {
            DB::beginTransaction();
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail()->update(['payment_status' => 'refunded']);
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'waiting-for-order')));
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertStringContainsString('blocked', $worker->getOutput());
            $this->assertSame('preparando', $order->fresh()->status);
            $this->assertSame('refunded', $order->fresh()->payment_status);
            if ($operation === 'resolve') {
                $this->assertSame('Verify assignment', $order->fresh()->fulfillment_issue);
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_notifications_and_reviews_share_a_single_commercial_application(bool $expired): void
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
        if ($expired) {
            DB::table('stock_reservations')->where('order_id', $order->id)->update([
                'created_at' => now()->subHour(), 'expires_at' => now()->subMinutes(30), 'status' => 'expired',
            ]);
        }
        $firstId = (string) ($order->id * 10 + 1);
        $otherId = (string) ($order->id * 10 + 2);
        $script = base_path('tests/Support/payment-reconciliation-worker.php');
        $workers = array_map(fn ($id) => new Process([PHP_BINARY, $script, (string) $order->id, $id], base_path(), timeout: 30),
            [$firstId, $firstId, $otherId]);
        if ($expired) {
            $workers[] = new Process([PHP_BINARY, $script, (string) $order->id, 'release'], base_path(), timeout: 30);
        }
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

            if ($expired) {
                $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'consumed', 'released_at' => null]);
            }
            $review = new Process([PHP_BINARY, $script, (string) $order->id, 'review', $gate], base_path(), timeout: 30);
            $other = new Process([PHP_BINARY, $script, (string) $order->id, 'review'], base_path(), timeout: 30);
            $workers[] = $review;
            $workers[] = $other;
            $review->start();
            $this->assertTrue($review->waitUntil(fn ($type, $output) => str_contains($output, 'search-started')), $review->getErrorOutput());
            $other->mustRun();
            $this->assertStringContainsString('review_in_progress', $other->getOutput());
            if ($expired) {
                // The scheduler is in provider HTTP. Deliver the canonical webhook again on another connection.
                $webhook = new Process([PHP_BINARY, $script, (string) $order->id, $order->fresh()->mercadopago_payment_id], base_path(), timeout: 30);
                $workers[] = $webhook;
                $webhook->mustRun();
                $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order->id)->count());
            }
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
