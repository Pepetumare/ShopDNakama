<?php

namespace Tests\Feature;

use App\Models\CatalogPaymentCursor;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CatalogPaymentPaginationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MariaDB 127.0.0.1:33317/nakama_com01_test.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame(33317, (int) config('database.connections.mysql.port'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->assertContains(DB::connection()->getDriverTitle(), ['MySQL', 'MariaDB']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    private function order(): Order
    {
        $order = Order::create(['status' => 'pending', 'payment_status' => 'pending',
            'payment_method' => 'mercadopago', 'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'pref-'.$order->id])->save();
        $order->items()->create(['product_name' => 'Race fixture', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);

        return $order;
    }

    private function worker(Order $order, string $mode, string $marker, string $gate, string $source = 'scheduler'): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/catalog-payment-worker.php'),
            (string) $order->id, $mode, $marker, $gate, $source], base_path(), timeout: 25);
    }

    #[TestWith(['scheduler', 'scheduler'])]
    #[TestWith(['scheduler', 'admin'])]
    #[TestWith(['admin', 'scheduler'])]
    public function test_overlapping_reviews_use_database_lease_even_with_unshared_cache(string $firstSource, string $secondSource): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud004-'.Str::uuid();
        $gate = $marker.'-gate';
        $first = $this->worker($order, 'block', $marker, $gate, $firstSource);
        $second = $this->worker($order, 'run', $marker, $gate, $secondSource);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $out) => str_contains($out, 'search_entered')));
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            DB::transaction(function () use ($order) {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                CatalogPaymentCursor::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            });
            $second->mustRun();
            $this->assertStringContainsString('review_in_progress', $second->getOutput());
            $this->assertSame("0\n", str_replace("\r\n", "\n", file_get_contents($marker)));
            touch($gate);
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(8, CatalogPaymentCursor::where('order_id', $order->id)->sole()->offset);
            $this->assertSame(1, DB::table('catalog_payment_pages')->where('order_id', $order->id)->count());
        } finally {
            $first->stop();
            $second->stop();
            foreach ([$marker, $gate] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_expired_worker_cannot_overwrite_a_newer_admin_checkpoint(): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud004-fence-'.Str::uuid();
        $gate = $marker.'-gate';
        $first = $this->worker($order, 'block-extra', $marker, $gate);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $out) => str_contains($out, 'search_entered')));
            CatalogPaymentCursor::where('order_id', $order->id)->update(['lease_expires_at' => now()->subSecond()]);
            $next = $this->worker($order, 'run', $marker, $gate, 'admin');
            $next->mustRun();
            touch($gate);
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertStringContainsString('review_superseded', $first->getOutput());
            $this->assertSame(8, CatalogPaymentCursor::where('order_id', $order->id)->sole()->offset);
            $this->assertSame(1, DB::table('catalog_payment_pages')->where('order_id', $order->id)->count());
            $this->assertDatabaseHas('catalog_payment_work', ['order_id' => $order->id,
                'payment_id' => (string) (1000000 + $order->id * 1000 + 999), 'verification_version' => 0]);
        } finally {
            $first->stop();
            foreach ([$marker, $gate] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_process_death_after_discovery_retains_ids_and_next_process_continues(): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud004-crash-'.Str::uuid();
        try {
            $first = $this->worker($order, 'crash', $marker, $marker.'-unused');
            $this->assertSame(19, $first->run(), $first->getErrorOutput());
            $this->assertSame(10, DB::table('catalog_payment_work')->where('order_id', $order->id)->count());
            $this->assertDatabaseHas('catalog_payment_pages', ['order_id' => $order->id, 'offset' => 0, 'state' => 'pending']);
            CatalogPaymentCursor::where('order_id', $order->id)->update(['lease_expires_at' => now()->subSecond()]);
            $next = $this->worker($order, 'run', $marker, $marker.'-unused');
            $next->mustRun();
            $this->assertSame("0\n8\n", str_replace("\r\n", "\n", file_get_contents($marker)));
            $this->assertSame(16, CatalogPaymentCursor::where('order_id', $order->id)->sole()->offset);
            $this->assertSame(18, DB::table('catalog_payment_work')->where('order_id', $order->id)->count());
            $this->assertSame(10, DB::table('payment_transactions')->where('order_id', $order->id)->count());
            $this->assertDatabaseHas('catalog_payment_pages', ['order_id' => $order->id, 'offset' => 0, 'state' => 'verified']);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }
}
