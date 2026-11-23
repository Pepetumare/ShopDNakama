<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\StoreTestCase;

class CatalogPaymentSearchContractTest extends StoreTestCase
{
    private function response(array $rows, mixed $total, mixed $reportedOffset = 0): void
    {
        config(['services.mercadopago.access_token' => 'TEST-fake']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['results' => $rows, 'paging' => ['total' => $total, 'offset' => $reportedOffset]])]);
    }

    public function test_catalog_has_unique_id_order_and_duplicate_rows_keep_the_raw_next_offset(): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'total' => 10000]);
        $this->response([['id' => 123], ['id' => 123]], 3);
        $this->assertSame(['ids' => ['123'], 'next' => 2], app(MercadoPagoService::class)->searchPayments($order, 0));
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['sort'] === 'id' && $query['criteria'] === 'asc' && (int) $query['limit'] === 10;
        });
    }

    #[TestWith([2.5, 0])]
    #[TestWith(['invalid', 0])]
    #[TestWith([5, 10])]
    public function test_invalid_total_or_mismatched_offset_is_not_accepted(mixed $total, mixed $offset): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'total' => 10000]);
        $this->response([['id' => 123]], $total, $offset);
        $this->expectException(RuntimeException::class);
        app(MercadoPagoService::class)->searchPayments($order, 0);
    }

    public function test_shrinking_remote_set_can_end_a_sweep_beyond_its_new_total(): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'total' => 10000]);
        $this->response([], 2, 8);
        $this->assertSame(['ids' => [], 'next' => null], app(MercadoPagoService::class)->searchPayments($order, 8));
    }

    public function test_personalized_search_keeps_its_existing_date_created_order(): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'total' => 10000]);
        $order->setAttribute('custom_request_id', 42);
        $this->response([['id' => 123]], 1);
        app(MercadoPagoService::class)->searchPayments($order, 0);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['sort'] === 'date_created' && $query['criteria'] === 'asc';
        });
    }

    public function test_sdk_cannot_truncate_a_fractional_payment_identifier(): void
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'total' => 10000]);
        $this->response([['id' => 123.5]], 1);
        $this->expectException(RuntimeException::class);
        app(MercadoPagoService::class)->searchPayments($order, 0);
    }
}
