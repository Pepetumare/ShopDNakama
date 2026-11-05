<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\StoreTestCase;

class StockTest extends StoreTestCase
{
    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    private function data(Product $product, array $extra = []): array
    {
        return array_merge(['type' => 'receipt', 'quantity' => 3, 'reason' => 'Ingreso de proveedor', 'version' => $product->fresh()->stock_version, 'token' => (string) Str::uuid()], $extra);
    }

    public function test_all_endpoints_are_admin_only(): void
    {
        $product = $this->product();
        $endpoints = [['GET', route('housekeeping.stock.index')], ['GET', route('housekeeping.stock.show', $product)], ['POST', route('housekeeping.stock.store', $product)], ['PATCH', route('housekeeping.stock.minimum', $product)], ['POST', route('housekeeping.stock.restore')]];
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url)->assertForbidden();
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_movements_are_audited_and_duplicate_submission_is_idempotent(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        foreach (['receipt' => [3, 11], 'issue' => [2, 9], 'damage' => [1, 8], 'count' => [4, 4], 'replacement' => [2, 6]] as $type => [$quantity, $after]) {
            $before = $product->fresh()->stock;
            $data = $this->data($product, compact('type', 'quantity'));
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->post(route('housekeeping.stock.store', $product), $data)->assertSessionHasNoErrors();
            }
            $this->assertSame($after, $product->fresh()->stock);
            $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => $type, 'before' => $before, 'after' => $after, 'quantity' => $after - $before, 'user_id' => $admin->id]);
        }
        $this->assertDatabaseCount('stock_movements', 5);
    }

    public function test_stale_physical_counts_cannot_overwrite_an_intervening_movement_even_if_quantity_returns_to_same_value(): void
    {
        $this->admin();
        $product = $this->product();
        $stale = $this->data($product, ['type' => 'count', 'quantity' => 20]);
        $this->post(route('housekeeping.stock.store', $product), $this->data($product, ['quantity' => 1]))->assertSessionHasNoErrors();
        $this->post(route('housekeeping.stock.store', $product), $this->data($product, ['type' => 'issue', 'quantity' => 1]))->assertSessionHasNoErrors();
        $this->post(route('housekeeping.stock.store', $product), $stale)->assertSessionHasErrors('quantity');
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_insufficient_stock_invalid_inputs_and_overflow_are_rejected(): void
    {
        $this->admin();
        $product = $this->product();
        foreach ([['type' => 'issue', 'quantity' => 9], ['type' => 'damage', 'quantity' => 9], ['quantity' => 2147483647], ['quantity' => -1], ['type' => 'sale'], ['reason' => ''], ['quantity' => 0]] as $extra) {
            $this->post(route('housekeeping.stock.store', $product), $this->data($product, $extra))->assertSessionHasErrors();
        }
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_dashboard_filters_pagination_minimum_and_product_links(): void
    {
        $this->admin();
        $product = $this->product(['name' => 'Inventario visible', 'sku' => 'INV-01', 'stock' => 3]);
        $this->product(['name' => 'Producto agotado', 'stock' => 0]);
        $this->get('/housekeeping/stock?state=low&q=INV')->assertOk()->assertSee('Inventario visible')->assertDontSee('Producto agotado')->assertViewHas('units', 3)->assertViewHas('empty', 1)->assertViewHas('low', 1);
        $this->get(route('housekeeping.stock.show', $product))->assertOk()->assertSee('Registrar movimiento');
        $this->patch(route('housekeeping.stock.minimum', $product), ['minimum_stock' => 1])->assertSessionHasNoErrors();
        $this->assertSame('En stock', $product->fresh()->stock_label);
        $this->get(route('housekeeping.products.index'))->assertOk()->assertSee(route('housekeeping.stock.show', $product), false);
        foreach (range(1, 16) as $i) {
            $this->product(['name' => 'Paginado '.$i]);
        }
        $this->get('/housekeeping/stock?page=2')->assertOk()->assertViewHas('products', fn ($p) => $p->currentPage() === 2 && $p->count() === 3);
    }

    private function paidOrder(Product $product): Order
    {
        $order = Order::create(['payment_method' => 'mercadopago', 'payment_status' => 'paid', 'status' => 'confirmed', 'total' => 100]);
        foreach ([2, 3] as $quantity) {
            $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => 20, 'line_total' => $quantity * 20]);
        }

        return $order;
    }

    private function deduct(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $result = app(StockManager::class)->deductOrder($locked);
            $locked->save();

            return $result;
        });
    }

    public function test_sales_aggregate_duplicate_lines_and_competing_orders_cannot_oversell(): void
    {
        $product = $this->product(['stock' => 8]);
        $first = $this->paidOrder($product);
        $second = $this->paidOrder($product);
        $this->assertTrue($this->deduct($first));
        $this->assertTrue($this->deduct($first));
        $this->assertFalse($this->deduct($second));
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertNull($second->fresh()->stock_deducted_at);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $first->id, 'quantity' => -5]);
    }

    public function test_order_stock_is_all_or_nothing_across_multiple_products(): void
    {
        $product = $this->product(['stock' => 4]);
        $order = $this->paidOrder($product);
        $available = $this->product(['name' => 'Segundo producto', 'stock' => 10]);
        $order->items()->create(['product_id' => $available->id, 'product_name' => $available->name, 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);
        $this->assertFalse($this->deduct($order));
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(10, $available->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_returns_require_refund_and_receipt_and_restore_exactly_once(): void
    {
        $this->admin();
        $product = $this->product();
        $order = $this->paidOrder($product);
        $this->assertTrue($this->deduct($order));
        $data = ['order_number' => $order->number, 'reason' => 'Devolución recibida en buen estado', 'received' => 1];
        $this->post('/housekeeping/stock/returns', $data)->assertSessionHasErrors('order_id');
        $order->update(['payment_status' => 'refunded']);
        $this->post('/housekeeping/stock/returns', array_diff_key($data, ['received' => 1]))->assertSessionHasErrors('received');
        $this->post('/housekeeping/stock/returns', $data)->assertSessionHasNoErrors();
        $this->post('/housekeeping/stock/returns', $data)->assertSessionHasNoErrors();
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->stock_restored_at);
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
