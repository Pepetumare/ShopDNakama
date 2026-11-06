<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockReservationConcurrencyTest extends TestCase
{
    public function test_two_transactions_competing_for_one_unit_create_one_reservation(): void
    {
        $this->race(false);
    }

    public function test_two_requests_for_the_same_attempt_send_one_preference_post(): void
    {
        $this->race(true);
    }

    private function race(bool $sameAttempt): void
    {
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires an isolated MySQL-family server and the dedicated nakama_com01_test database.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $category = Category::create(['name' => 'Race '.Str::uuid()]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Last unit', 'price' => 10000, 'stock' => 1]);
        $buyers = User::factory()->count($sameAttempt ? 1 : 2)->create();
        $workers = [];
        foreach ($buyers as $buyer) {
            $buyer->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
            if ($sameAttempt) {
                $request = \Illuminate\Http\Request::create('/checkout');
                $request->setUserResolver(fn () => $buyer);
                $view = app(\App\Http\Controllers\CheckoutController::class)->index(
                    $request, app(\App\Services\ShoppingCart::class), app(\App\Services\StockReservations::class)
                );
                $token = $view->getData()['attempt']->token;
                for ($i = 0; $i < 2; $i++) {
                    $workers[] = new Process([PHP_BINARY, base_path('tests/Support/reservation-worker.php'), (string) $buyer->id, $token], base_path(), timeout: 30);
                }
                continue;
            }
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/reservation-worker.php'), (string) $buyer->id], base_path(), timeout: 30);
        }

        try {
            DB::beginTransaction();
            try {
                $lock = $sameAttempt ? $buyers->first() : $product;
                $lock->newQuery()->whereKey($lock->id)->lockForUpdate()->firstOrFail();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'waiting-for-lock')), $worker->getErrorOutput());
                }
            } finally {
                DB::rollBack();
            }

            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim(last(explode("\n", trim($worker->getOutput()))));
            }
            if ($sameAttempt) {
                $this->assertContains('reserved', $results);
                $this->assertEmpty(array_diff($results, ['reserved', 'waiting']));
                $this->assertSame(1, CheckoutAttempt::where('user_id', $buyers->first()->id)->count());
            } else {
                $this->assertEqualsCanonicalizing(['reserved', 'out-of-stock'], $results);
            }
            $this->assertSame(1, array_sum(array_map(fn (Process $worker) => substr_count($worker->getOutput(), 'provider-create'), $workers)));
            $this->assertSame(1, Order::whereIn('user_id', $buyers->modelKeys())->count());
            $this->assertSame(1, DB::table('stock_reservations')->where('product_id', $product->id)->where('status', 'active')->count());
            $this->assertSame(1, $product->fresh()->stock);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }
}
