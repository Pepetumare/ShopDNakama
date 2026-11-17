<?php

namespace Tests\Feature;

use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomQuoteCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomPaymentFindingsConcurrencyTest extends TestCase
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

    private function order(bool $ready = true): Order
    {
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
        $order = app(CustomQuoteCheckout::class)->orderFor($quote, $user, [
            'shipping_address_line1' => 'Race findings 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM',
        ]);
        $order->customPaymentIntent()->update(['state' => $ready ? CustomPaymentIntent::READY : CustomPaymentIntent::UNCERTAIN,
            'sending_at' => now()->subMinutes(5), 'preference_id' => 'findings-pref-'.$order->id,
            'checkout_url' => $ready ? 'https://www.mercadopago.cl/findings/'.$order->id : null]);
        if ($ready) {
            $order->forceFill(['mercadopago_preference_id' => 'findings-pref-'.$order->id,
                'mercadopago_checkout_url' => 'https://www.mercadopago.cl/findings/'.$order->id])->save();
        }

        return $order;
    }

    private function worker(Order $order, string $mode, string $gate, ?User $admin = null): Process
    {
        $args = [PHP_BINARY, base_path('tests/Support/custom-payment-findings-worker.php'), (string) $order->id, $mode, $gate];
        if ($admin) {
            $args[] = (string) $admin->id;
        }

        return new Process($args, base_path(), timeout: 30);
    }

    private function cleanup(array $workers, string $gate): void
    {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        if (is_file($gate)) {
            unlink($gate);
        }
    }

    #[TestWith(['approve', 'paid'])]
    #[TestWith(['discrepant', 'pending'])]
    public function test_committed_approval_before_final_url_decision_blocks_the_url(string $mode, string $expectedStatus): void
    {
        $order = $this->order();
        $gate = sys_get_temp_dir().'/aud001-url-'.Str::uuid();
        $url = $this->worker($order, 'url', $gate);
        $approval = $this->worker($order, $mode, $gate);
        try {
            $url->start();
            $this->assertTrue($url->waitUntil(fn ($type, $output) => str_contains($output, 'before-url-decision')), $url->getErrorOutput());
            $approval->mustRun();
            $this->assertSame($expectedStatus, $order->fresh()->payment_status);
            touch($gate);
            $this->assertSame(0, $url->wait(), $url->getErrorOutput());
            $this->assertStringContainsString('url-held', $url->getOutput());
            $this->assertStringNotContainsString('https://', $url->getOutput());
            $this->assertSame(1, $order->paymentTransactions()->count());
        } finally {
            $this->cleanup([$url, $approval], $gate);
        }
    }

    public function test_concurrent_discrepant_notifications_create_one_incident_and_no_commercial_application(): void
    {
        $order = $this->order();
        $gate = sys_get_temp_dir().'/aud001-discrepant-'.Str::uuid();
        $workers = [$this->worker($order, 'discrepant', $gate), $this->worker($order, 'discrepant', $gate)];
        try {
            DB::beginTransaction();
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'request-lock-started')), $worker->getErrorOutput());
            }
            DB::commit();
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertStringContainsString('preference_mismatch', $worker->getOutput());
            }
            $this->assertSame(1, $order->paymentTransactions()->where('incident', 'preference_mismatch')->count());
            $this->assertSame(2, DB::table('payment_reconciliation_events')->where('order_id', $order->id)->where('outcome', 'preference_mismatch')->count());
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertNull($order->fresh()->stock_deducted_at);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            $this->cleanup($workers, $gate);
        }
    }

    public function test_concurrent_admin_recovery_claims_once_and_preserves_both_observations(): void
    {
        $order = $this->order(ready: false);
        $order->customPaymentIntent()->update(['preference_id' => 'unverified-old']);
        $admin = User::factory()->create(['role' => 'admin']);
        $gate = sys_get_temp_dir().'/aud001-admin-'.Str::uuid();
        $first = $this->worker($order, 'admin-recover', $gate, $admin);
        $second = $this->worker($order, 'admin-recover', $gate, $admin);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $output) => str_contains($output, 'preference-read-held')), $first->getErrorOutput());
            $second->mustRun();
            $this->assertStringContainsString('lookup_deferred', $second->getOutput());
            touch($gate);
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame('ready', $order->customPaymentIntent->fresh()->state);
            $this->assertSame(1, $order->customPaymentIntent->events()->where('outcome', 'recovered')->count());
            $this->assertSame(2, $order->customPaymentIntent->events()->where('outcome', 'observation')->where('actor_id', $admin->id)->count());
        } finally {
            $this->cleanup([$first, $second], $gate);
        }
    }

    public function test_scheduler_and_manual_review_share_exclusion_and_no_http_database_locks(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['financial_review_required' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $gate = sys_get_temp_dir().'/aud001-review-'.Str::uuid();
        $scheduler = $this->worker($order, 'scheduler-held', $gate);
        $manual = $this->worker($order, 'manual-review', $gate, $admin);
        try {
            $scheduler->start();
            $this->assertTrue($scheduler->waitUntil(fn ($type, $output) => str_contains($output, 'payment-search-held')), $scheduler->getErrorOutput());
            $manual->mustRun();
            $this->assertStringContainsString('review_in_progress', $manual->getOutput());
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            DB::transaction(fn () => Order::whereKey($order->id)->lockForUpdate()->firstOrFail());
            touch($gate);
            $this->assertSame(0, $scheduler->wait(), $scheduler->getErrorOutput());
            $intent = $order->customPaymentIntent->fresh();
            $this->assertTrue($intent->financial_review_required);
            $this->assertTrue($intent->financial_next_review_at->gt(now()));
        } finally {
            $this->cleanup([$scheduler, $manual], $gate);
        }
    }

    public function test_preference_recovery_does_not_override_concurrently_observed_refund(): void
    {
        $order = $this->order(ready: false);
        $order->forceFill(['payment_status' => 'paid', 'status' => 'confirmed', 'mercadopago_payment_id' => (string) ($order->id * 10 + 1)])->save();
        $order->customRequest->update(['status' => 'approved']);
        $gate = sys_get_temp_dir().'/aud001-refund-'.Str::uuid();
        $recovery = $this->worker($order, 'recover-held', $gate);
        $refund = $this->worker($order, 'refund', $gate);
        $review = $this->worker($order, 'refund-review', $gate);
        try {
            $recovery->start();
            $this->assertTrue($recovery->waitUntil(fn ($type, $output) => str_contains($output, 'preference-read-held')), $recovery->getErrorOutput());
            $refund->mustRun();
            $this->assertSame('refunded', $order->paymentTransactions()->value('provider_status'));
            touch($gate);
            $this->assertSame(0, $recovery->wait(), $recovery->getErrorOutput());
            $review->mustRun();
            $this->assertSame('refunded', $order->fresh()->payment_status);
            $this->assertSame(1, $order->paymentTransactions()->count());
            $this->assertSame('ready', $order->customPaymentIntent->fresh()->state);
            $this->assertNotNull($order->fresh()->fulfillment_issue);
        } finally {
            $this->cleanup([$recovery, $refund, $review], $gate);
        }
    }
}
