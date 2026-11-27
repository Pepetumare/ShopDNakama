<?php

namespace Tests\Feature;

use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomQuoteCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomPaymentConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NAKAMA_RESERVATION_RACE') !== '1') {
            $this->markTestSkipped('Requires isolated MariaDB at 127.0.0.1:33317 / nakama_com01_test.');
        }
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame(33317, (int) config('database.connections.mysql.port'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('nakama_com01_test', DB::connection()->getDatabaseName());
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as version')->version);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['services.mercadopago.collector_id' => '42']);
    }

    private function order(): Order
    {
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);

        return app(CustomQuoteCheckout::class)->orderFor($quote, $user, [
            'shipping_address_line1' => 'Race 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM',
        ]);
    }

    private function worker(Order $order, string $mode, string $marker, string $gate): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/custom-payment-worker.php'), (string) $order->id, $mode, $marker, $gate], base_path(), timeout: 30);
    }

    public function test_two_overlapping_requests_send_once_and_hold_no_database_locks_during_http(): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud001-post-'.Str::uuid();
        $gate = $marker.'-gate';
        $first = $this->worker($order, 'publish', $marker, $gate);
        $second = $this->worker($order, 'publish', $marker, $gate);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $output) => str_contains($output, 'post-started')), $first->getErrorOutput());
            $second->mustRun();
            $this->assertStringContainsString('pending', $second->getOutput());
            // The first process is still inside the provider call. A separate connection can lock every row.
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            DB::transaction(function () use ($order) {
                CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail();
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $intent = CustomPaymentIntent::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                $this->assertSame('sending', $intent->state);
            });
            touch($gate);
            $this->assertSame(0, $first->wait(), $first->getOutput().$first->getErrorOutput());
            $this->assertSame("POST\n", file_get_contents($marker));
            $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
            $this->assertSame(1, CustomPaymentIntent::where('order_id', $order->id)->count());
            $this->assertSame('InnoDB', DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'nakama_com01_test' AND TABLE_NAME = 'custom_payment_intents'")->ENGINE);
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

    #[TestWith(['crash_before', false])]
    #[TestWith(['crash_after', true])]
    #[TestWith(['crash_persist', true])]
    public function test_process_death_never_reopens_publication(string $mode, bool $remoteExists): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud001-crash-'.Str::uuid();
        $gate = $marker.'-gate';
        touch($gate);
        $crash = $this->worker($order, $mode, $marker, $gate);
        $recover = $this->worker($order, 'recover', $marker, $gate);
        try {
            $this->assertSame(17, $crash->run(), $crash->getErrorOutput());
            $this->assertSame('sending', $order->customPaymentIntent()->value('state'));
            $order->customPaymentIntent()->update(['sending_at' => now()->subMinutes(3)]);
            $recover->mustRun();
            $this->assertSame($remoteExists ? 'ready' : 'reconciliation_required', $order->customPaymentIntent()->value('state'));
            $this->assertSame($remoteExists ? "POST\n" : '', is_file($marker) ? file_get_contents($marker) : '');
        } finally {
            $crash->stop();
            $recover->stop();
            foreach ([$marker, $gate] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_early_webhook_can_finish_while_publisher_is_waiting_without_duplicate_application(): void
    {
        $order = $this->order();
        $marker = sys_get_temp_dir().'/aud001-webhook-'.Str::uuid();
        $gate = $marker.'-gate';
        $publisher = $this->worker($order, 'publish', $marker, $gate);
        $webhook = $this->worker($order, 'webhook', $marker, $gate);
        try {
            $publisher->start();
            $this->assertTrue($publisher->waitUntil(fn ($type, $output) => str_contains($output, 'post-started')));
            $webhook->mustRun();
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
            touch($gate);
            // Publisher must recheck the now-paid order before releasing any checkout URL.
            $this->assertSame(0, $publisher->wait());
            $this->assertStringContainsString('blocked', $publisher->getOutput());
            $this->assertStringNotContainsString('https://', $publisher->getOutput());
            $this->assertSame("POST\n", file_get_contents($marker));
            $this->assertSame(1, $order->paymentTransactions()->count());
            $this->assertSame('approved', $order->customRequest->fresh()->status);
        } finally {
            $publisher->stop();
            $webhook->stop();
            foreach ([$marker, $gate] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
