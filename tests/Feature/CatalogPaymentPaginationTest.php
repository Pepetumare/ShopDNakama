<?php

namespace Tests\Feature;

use App\Models\CatalogPaymentCursor;
use App\Models\CatalogPaymentPage;
use App\Models\CatalogPaymentWork;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\StoreTestCase;

class CatalogPaymentPaginationTest extends StoreTestCase
{
    private array $remote = [];

    private array $offsets = [];

    private array $searchErrors = [];

    private array $verificationErrors = [];

    private array $statuses = [];

    private function order(): Order
    {
        $product = $this->product(['price' => 10000, 'stock' => 3]);
        $order = Order::create(['payment_method' => 'mercadopago', 'status' => 'pending',
            'payment_status' => 'pending', 'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'pref-'.$order->id])->save();
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);

        return $order;
    }

    private function provider(): void
    {
        $provider = $this->mock(MercadoPagoService::class);
        $fixtureTransactionLevel = DB::transactionLevel();
        $provider->shouldReceive('searchPayments')->andReturnUsing(function (Order $order, int $offset) use ($fixtureTransactionLevel) {
            $this->assertSame($fixtureTransactionLevel, DB::transactionLevel());
            $this->offsets[] = $offset;
            if (($this->searchErrors[$offset] ?? 0) > 0) {
                $this->searchErrors[$offset]--;
                throw new \RuntimeException('Simulated HTTP failure');
            }
            $ids = array_slice($this->remote, $offset, 10);

            return ['ids' => $ids, 'next' => $offset + count($ids) < count($this->remote) ? $offset + count($ids) : null];
        });
        $provider->shouldReceive('getPayment')->andReturnUsing(function (string $id) use ($fixtureTransactionLevel) {
            $this->assertSame($fixtureTransactionLevel, DB::transactionLevel());
            if (($this->verificationErrors[$id] ?? 0) > 0) {
                $this->verificationErrors[$id]--;
                throw new \RuntimeException('Payment GET failed');
            }
            $order = Order::firstOrFail();

            return ['id' => $id, 'external_reference' => $order->number, 'preference_id' => $order->mercadopago_preference_id,
                'currency' => 'CLP', 'amount' => 10000, 'status' => $this->statuses[$id] ?? 'pending'];
        });
    }

    private function tick(): int
    {
        $count = app(PaymentReconciliation::class)->reviewBatch();
        $this->travel(16)->minutes();

        return $count;
    }

    public function test_twenty_five_payments_and_unknown_approvals_in_later_pages_survive_new_service_instances(): void
    {
        $order = $this->order();
        $this->remote = array_map('strval', range(101, 125));
        $this->statuses = ['113' => 'approved', '124' => 'approved'];
        $this->provider();
        foreach (range(1, 3) as $pass) {
            $this->assertSame(1, $this->tick());
        }
        $this->assertSame([0, 8, 16], $this->offsets);
        $this->assertDatabaseCount('catalog_payment_work', 25);
        $this->assertDatabaseCount('payment_transactions', 25);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '113', 'provider_status' => 'approved']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '124', 'incident' => 'additional_payment']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertFalse(CatalogPaymentCursor::sole()->scan_open);
    }

    public function test_intermediate_search_error_does_not_advance_or_lose_discovered_work(): void
    {
        $this->order();
        $this->remote = array_map('strval', range(101, 125));
        $this->searchErrors = [8 => 1];
        $this->provider();
        $this->tick();
        $this->tick();
        $this->assertSame(8, CatalogPaymentCursor::sole()->offset);
        $this->assertDatabaseCount('catalog_payment_pages', 1);
        $this->assertSame('search_error', CatalogPaymentCursor::sole()->last_outcome);
        foreach (range(1, 4) as $pass) {
            $this->tick();
        }
        $this->assertSame([0, 8, 8, 16], $this->offsets);
        $this->assertDatabaseCount('payment_transactions', 25);
    }

    public function test_failed_payment_get_retains_id_and_incomplete_page_while_later_discovery_continues(): void
    {
        $this->order();
        $this->remote = array_map('strval', range(101, 125));
        $this->verificationErrors = ['113' => 1];
        $this->provider();
        $this->tick();
        $this->tick();
        $this->assertDatabaseHas('catalog_payment_work', ['payment_id' => '113', 'last_outcome' => 'verification_error']);
        $this->assertDatabaseHas('catalog_payment_pages', ['offset' => 8, 'state' => 'pending']);
        $this->assertSame(16, CatalogPaymentCursor::sole()->offset);
        $this->tick();
        $this->tick();
        $this->assertDatabaseCount('payment_transactions', 25);
        $this->assertGreaterThanOrEqual(2, CatalogPaymentWork::where('payment_id', '113')->sole()->attempts);
        $this->assertDatabaseHas('catalog_payment_pages', ['offset' => 8, 'state' => 'verified']);
    }

    public function test_new_lower_and_higher_ids_and_removed_rows_are_revisited_by_full_sweeps(): void
    {
        $this->order();
        $this->remote = array_map('strval', range(101, 125));
        $this->provider();
        $this->tick();
        $this->remote = array_map('strval', [99, ...range(105, 130)]);
        foreach (range(1, 4) as $pass) {
            $this->tick();
        }
        $this->assertDatabaseMissing('payment_transactions', ['provider_payment_id' => '99']);
        $this->travel(3)->hours();
        foreach (range(1, 5) as $pass) {
            $this->tick();
        }
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '99']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '130']);
        $this->assertEmpty(array_diff($this->remote, PaymentTransaction::pluck('provider_payment_id')->all()));
        $this->assertDatabaseHas('catalog_payment_work', ['payment_id' => '101']);
        $this->assertGreaterThanOrEqual(2, CatalogPaymentCursor::sole()->generation);
    }

    public function test_old_orders_open_incidents_and_known_ids_remain_eligible_without_search_results(): void
    {
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subYears(2)])->save();
        $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => '999',
            'amount' => 10000, 'currency' => 'CLP', 'provider_status' => 'pending', 'incident' => 'stock_unavailable',
            'last_verified_at' => now()->subYear(), 'correlation_id' => (string) Str::uuid()]);
        $this->provider();
        $this->assertSame(1, $this->tick());
        $this->assertDatabaseHas('catalog_payment_work', ['payment_id' => '999', 'last_http' => 200]);
        $this->assertNotNull(PaymentTransaction::where('provider_payment_id', '999')->sole()->incident);
        $this->travel(3)->hours();
        $this->assertSame(1, $this->tick());
        $this->assertGreaterThanOrEqual(2, CatalogPaymentWork::where('payment_id', '999')->sole()->attempts);
    }

    public function test_repeated_ids_and_admin_offset_hint_cannot_skip_the_durable_cursor(): void
    {
        $order = $this->order();
        $this->remote = ['101', '101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111'];
        $this->provider();
        $first = app(PaymentReconciliation::class)->reviewOrder($order, 'admin', offset: 1000);
        $this->assertSame(8, $first['next']);
        app(PaymentReconciliation::class)->reviewOrder($order, 'scheduler');
        $this->assertSame([0, 8], $this->offsets);
        $this->assertDatabaseCount('catalog_payment_work', 11);
        $this->assertDatabaseCount('payment_transactions', 11);
    }

    public function test_failure_in_one_order_does_not_abort_other_orders_or_monopolize_a_batch(): void
    {
        $orders = collect(range(1, 7))->map(fn () => $this->order());
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->andReturnUsing(function (Order $order) use ($orders) {
            if ($order->id === $orders->first()->id) {
                throw new \RuntimeException('Unavailable');
            }

            return ['ids' => [], 'next' => null];
        });
        $this->assertSame(5, $this->tick());
        $this->assertLessThanOrEqual(5, CatalogPaymentCursor::count());
        $this->tick();
        $this->assertSame(7, CatalogPaymentCursor::count());
        $this->assertGreaterThanOrEqual(6, CatalogPaymentPage::count());
    }

    public function test_permanently_failing_id_does_not_hide_later_pages_or_become_verified(): void
    {
        $this->order();
        $this->remote = array_map('strval', range(101, 125));
        $this->verificationErrors = ['113' => 50];
        $this->provider();
        foreach (range(1, 5) as $pass) {
            $this->tick();
        }
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '125']);
        $this->assertDatabaseHas('catalog_payment_pages', ['offset' => 8, 'state' => 'pending']);
        $this->assertNull(CatalogPaymentWork::where('payment_id', '113')->sole()->last_verified_at);
        $this->assertFalse(CatalogPaymentCursor::sole()->scan_open);
    }
}
