<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousekeepingOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, array $attributes = []): Order
    {
        $order = $user->orders()->create(array_merge([
            'customer_name' => $user->name, 'customer_email' => $user->email,
            'customer_phone' => '+56 9 1234 5678', 'shipping_address_line1' => 'Calle Nakama 123',
            'shipping_city' => 'Santiago', 'shipping_region' => 'Metropolitana', 'shipping_country' => 'Chile',
            'subtotal' => 20000, 'shipping_cost' => 3000, 'discount_total' => 1000, 'total' => 22000,
            'payment_method' => 'Transferencia',
        ], $attributes));
        if (isset($attributes['created_at'])) {
            $order->created_at = $attributes['created_at'];
            $order->save();
        }

        return $order;
    }

    public function test_users_only_list_their_own_orders_and_cannot_open_another_users_order(): void
    {
        $user = User::factory()->create();
        $own = $this->order($user, ['number' => 'MI-PEDIDO-001']);
        $other = $this->order(User::factory()->create(), ['number' => 'PEDIDO-AJENO-002']);
        $this->actingAs($user)->get('/mi-cuenta/pedidos')->assertOk()->assertSee($own->number)->assertDontSee($other->number);
        $this->get(route('account.orders.show', $own))->assertOk()->assertSee('Calle Nakama 123')->assertSee('$22.000');
        $this->get(route('account.orders.show', $other))->assertForbidden()->assertDontSee($other->customer_email);
        $this->get('/mi-cuenta/pedidos/999999')->assertNotFound();
    }

    public function test_admin_customer_account_still_only_shows_their_own_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $own = $this->order($admin, ['number' => 'ADMIN-COMPRA']);
        $other = $this->order(User::factory()->create(), ['number' => 'CLIENTE-COMPRA']);
        $this->actingAs($admin)->get('/mi-cuenta/pedidos')->assertOk()->assertSee($own->number)->assertDontSee($other->number);
        $this->get(route('account.orders.show', $other))->assertForbidden();
        $this->get(route('housekeeping.orders.show', $other))->assertOk()->assertSee($other->number);
    }

    public function test_admin_can_see_all_orders_and_snapshot_item_details(): void
    {
        $first = $this->order(User::factory()->create(), ['number' => 'PEDIDO-UNO', 'payment_status' => 'pagado']);
        $second = $this->order(User::factory()->create(), ['number' => 'PEDIDO-DOS']);
        $item = $first->items()->create(['product_name' => 'Manga histórico', 'sku' => 'MANGA-OLD', 'quantity' => 2, 'unit_price' => 10000, 'line_total' => 20000]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/housekeeping/orders')->assertOk()
            ->assertSee($first->number)->assertSee($second->number)->assertSee('Transferencia')->assertSee('Pagado');
        $this->get(route('housekeeping.orders.show', $first))->assertOk()->assertSee('Manga histórico')->assertSee('MANGA-OLD')
            ->assertSee('$20.000')->assertSee('$3.000')->assertSee('$1.000')->assertSee('$22.000')->assertSee('Calle Nakama 123')->assertSee('+56 9 1234 5678');
        $this->assertTrue($item->order->is($first));
    }

    public function test_admin_can_change_each_valid_order_status_without_changing_payment_or_amounts(): void
    {
        $order = $this->order(User::factory()->create());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (array_keys(Order::STATUSES) as $status) {
            $this->patch(route('housekeeping.orders.status', $order), [
                'status' => $status, 'payment_status' => 'pagado', 'total' => 0, 'user_id' => auth()->id(),
            ])->assertSessionHasNoErrors()->assertRedirect(route('housekeeping.orders.show', $order));
            $this->assertSame($status, $order->fresh()->status);
            $this->assertSame('pendiente', $order->fresh()->payment_status);
            $this->assertSame('22000.00', $order->fresh()->total);
        }
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'inventado'])->assertSessionHasErrors('status');
        $this->patch(route('housekeeping.orders.status', $order), [])->assertSessionHasErrors('status');
        $this->assertSame('cancelado', $order->fresh()->status);
    }

    public function test_guests_and_normal_users_cannot_enter_any_order_or_customer_admin_route(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user);
        $routes = [
            ['GET', '/housekeeping/orders'], ['GET', route('housekeeping.orders.show', $order)],
            ['PATCH', route('housekeeping.orders.status', $order)], ['GET', '/housekeeping/customers'],
            ['GET', route('housekeeping.customers.show', $user)],
        ];
        foreach ($routes as [$method, $url]) {
            $this->call($method, $url, ['status' => 'entregado'])->assertRedirect(route('login'));
        }
        $this->actingAs($user);
        foreach ($routes as [$method, $url]) {
            $this->call($method, $url, ['status' => 'entregado'])->assertForbidden();
        }
        $this->assertSame('pendiente', $order->fresh()->status);
    }

    public function test_order_filters_combine_status_payment_date_customer_and_search(): void
    {
        $customer = User::factory()->create(['name' => 'Cliente Filtro', 'email' => 'filtro@example.com']);
        $wanted = $this->order($customer, ['number' => 'SDN-FILTRO-001', 'status' => 'confirmado', 'payment_status' => 'pagado', 'created_at' => '2026-10-10 23:59:59']);
        $this->order($customer, ['number' => 'FUERA-FECHA', 'status' => 'confirmado', 'payment_status' => 'pagado', 'created_at' => '2026-10-11 00:00:00']);
        $this->order($customer, ['number' => 'FUERA-ESTADO', 'status' => 'pendiente', 'payment_status' => 'pagado', 'created_at' => '2026-10-10 12:00:00']);
        $this->order(User::factory()->create(), ['number' => 'FUERA-CLIENTE', 'status' => 'confirmado', 'payment_status' => 'pagado', 'created_at' => '2026-10-10 12:00:00']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $filters = ['status' => 'confirmado', 'payment_status' => 'pagado', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10', 'customer_id' => $customer->id];
        foreach (['SDN-FILTRO', 'Cliente Filtro', 'filtro@example.com'] as $q) {
            $this->get('/housekeeping/orders?'.http_build_query($filters + ['q' => $q]))->assertOk()->assertSee($wanted->number)
                ->assertDontSee('FUERA-FECHA')->assertDontSee('FUERA-ESTADO')->assertDontSee('FUERA-CLIENTE');
        }
        $this->get('/housekeeping/orders?date_to=2026-10-10')->assertOk()->assertSee($wanted->number)->assertDontSee('FUERA-FECHA');
    }

    public function test_invalid_filters_and_empty_orders_are_handled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/housekeeping/orders')->assertOk()->assertSee('No encontramos pedidos');
        $this->getJson('/housekeeping/orders?status=invalid&payment_status=invalid&customer_id=999999')->assertUnprocessable();
        $this->getJson('/housekeeping/orders?date_from=2026-10-12&date_to=2026-10-01')->assertUnprocessable();
        $this->getJson('/housekeeping/orders?q[]=bad')->assertUnprocessable();
        $this->get('/mi-cuenta/pedidos')->assertOk()->assertSee('Todavía no tienes pedidos registrados.');
    }

    public function test_customers_metrics_history_addresses_and_latest_order_are_scoped_to_each_user(): void
    {
        $customer = User::factory()->create(['name' => 'Cliente Comprador', 'email' => 'comprador@example.com']);
        $paid = $this->order($customer, ['number' => 'PAGADO-REAL', 'payment_status' => 'pagado', 'total' => 12000, 'created_at' => '2026-10-01 10:00:00']);
        $this->order($customer, ['number' => 'NO-PAGADO', 'total' => 8000]);
        $this->order($customer, ['number' => 'CANCELADO', 'payment_status' => 'pagado', 'status' => 'cancelado', 'total' => 5000]);
        $this->order($customer, ['number' => 'REEMBOLSADO', 'payment_status' => 'reembolsado', 'total' => 9000]);
        $latest = $this->order($customer, ['number' => 'ULTIMO-PEDIDO', 'created_at' => now()->addMinute()]);
        $foreign = $this->order(User::factory()->create(), ['number' => 'PEDIDO-DE-OTRO']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/housekeeping/customers?q=comprador@example.com')->assertOk()->assertSee('Cliente Comprador')->assertSee('$12.000')->assertSee('ULTIMO-PEDIDO');
        $response = $this->get(route('housekeeping.customers.show', $customer))->assertOk()
            ->assertSee('PAGADO-REAL')->assertSee('NO-PAGADO')->assertSee('ULTIMO-PEDIDO')->assertDontSee($foreign->number)->assertSee('Calle Nakama 123');
        $this->assertEquals(12000, $response->viewData('customer')->total_spent);
        $this->assertSame(5, $response->viewData('customer')->orders_count);
        $this->assertCount(1, $response->viewData('addresses'));
        $this->assertTrue($response->viewData('customer')->latestOrder->is($latest));
        $this->assertTrue($paid->user->is($customer));
    }

    public function test_customer_without_orders_has_zero_spent_and_no_addresses(): void
    {
        $customer = User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.customers.show', $customer))
            ->assertOk()->assertSee('Sin pedidos')->assertSee('No hay direcciones registradas')->assertSee('$0');
    }

    public function test_pagination_preserves_filters_and_account_scope(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 16; $i++) {
            $this->order($user, ['number' => sprintf('NUM-%02d', $i)]);
        }
        $other = $this->order(User::factory()->create(), ['number' => 'AJENO']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/housekeeping/orders?status=pendiente&q=NUM')
            ->assertOk()->assertSee('status=pendiente&amp;q=NUM&amp;page=2', false);
        $this->actingAs($user)->get('/mi-cuenta/pedidos?page=2')->assertOk()->assertSee('NUM-01')->assertDontSee($other->number);
    }

    public function test_order_history_survives_user_deletion_and_keeps_its_customer_snapshot(): void
    {
        $customer = User::factory()->create(['name' => 'Cliente Histórico']);
        $order = $this->order($customer, ['number' => 'HISTORICO']);
        $customer->delete();
        $this->assertNull($order->fresh()->user_id);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.orders.show', $order))
            ->assertOk()->assertSee('Cliente Histórico')->assertSee('HISTORICO');
    }
}
