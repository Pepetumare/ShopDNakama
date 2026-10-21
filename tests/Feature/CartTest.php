<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\StoreTestCase;

class CartTest extends StoreTestCase
{
    public function test_guest_can_add_update_remove_and_keep_session_cart(): void
    {
        $product = $this->product();
        $this->from('/catalogo')->post('/carrito', ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect('/catalogo')->assertSessionHas('cart-status', 'Producto agregado');
        $this->get('/carrito')->assertOk()->assertSee($product->name)->assertSee('data-cart-count="2"', false);
        $this->patch('/carrito/'.$product->id, ['quantity' => 4])->assertSessionHas('cart-status', 'Cantidad actualizada');
        $this->get('/carrito')->assertSee('data-cart-count="4"', false);
        $this->delete('/carrito/'.$product->id)->assertSessionHas('cart-status', 'Producto eliminado');
        $this->get('/carrito')->assertSee('Tu carrito está vacío')->assertSee('data-cart-count="0"', false);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_authenticated_cart_persists_without_session_and_repeated_adds_share_one_line(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->post('/carrito', ['product_id' => $product->id, 'quantity' => 2])->assertRedirect();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
        $this->flushSession();
        $this->get('/carrito')->assertOk()->assertSee('data-cart-count="3"', false);
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(3, $user->cart->items->first()->quantity);
        $this->assertTrue($user->cart->user->is($user));
        $this->assertTrue($user->cart->items->first()->product->is($product));
        $this->patch('/carrito/'.$product->id, ['quantity' => 5])->assertRedirect();
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 5]);
        $this->delete('/carrito/'.$product->id)->assertRedirect();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_stock_and_quantity_validation_apply_to_guests_and_users(): void
    {
        $product = $this->product(['stock' => 3]);
        foreach ([null, User::factory()->create()] as $user) {
            if ($user) {
                $this->actingAs($user);
            }
            foreach ([0, -1, 1.5, 'abc', 4] as $quantity) {
                $this->post('/carrito', ['product_id' => $product->id, 'quantity' => $quantity])->assertSessionHasErrors('quantity');
            }
            $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
            $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasErrors('quantity');
            $this->patch('/carrito/'.$product->id, ['quantity' => 4])->assertSessionHasErrors('quantity');
            $this->patch('/carrito/'.$product->id, ['quantity' => 0])->assertSessionHasErrors('quantity');
            $this->get('/carrito')->assertSee('data-cart-count="2"', false);
        }
        $product->update(['stock' => 0]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('quantity');
        $this->get('/carrito')->assertSee('Tu carrito está vacío')->assertSee('Stock insuficiente');
    }

    public function test_missing_inactive_and_hidden_category_products_are_rejected(): void
    {
        $product = $this->product(['is_active' => false]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1])->assertNotFound();
        $this->post('/carrito', ['product_id' => 99999, 'quantity' => 1])->assertNotFound();
        $product->update(['is_active' => true]);
        $product->category->update(['is_active' => false]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_count_sums_units_and_effective_prices_ignore_browser_values(): void
    {
        $sale = $this->product(['price' => 10000, 'sale_price' => 8000]);
        $regular = $this->product(['name' => 'Precio normal', 'price' => 5000, 'sale_price' => 6000]);
        $this->post('/carrito', ['product_id' => $sale->id, 'quantity' => 2, 'price' => 1, 'sale_price' => 0]);
        $this->post('/carrito', ['product_id' => $regular->id, 'quantity' => 3]);
        $this->get('/carrito')->assertOk()->assertSee('data-cart-count="5"', false)
            ->assertViewHas('total', 31000)->assertSee(route('checkout'));
        $sale->update(['sale_price' => 7000]);
        $this->get('/carrito')->assertViewHas('total', 29000);
        $this->get('/checkout')->assertRedirect('/login');
    }

    public function test_user_cannot_modify_another_users_cart(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = $this->product();
        $this->actingAs($owner)->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $cart = $owner->cart;
        $this->actingAs($attacker)->patch('/carrito/'.$product->id, ['quantity' => 1, 'cart_id' => $cart->id, 'user_id' => $owner->id])->assertNotFound();
        $this->delete('/carrito/'.$product->id, ['cart_id' => $cart->id])->assertNotFound();
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 1, 'cart_id' => $cart->id, 'user_id' => $owner->id])->assertRedirect();
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $attacker->cart->id, 'quantity' => 1]);
    }

    public function test_login_merges_guest_cart_once_and_limits_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 3]);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        $this->post('/carrito', ['product_id' => $product->id, 'quantity' => 2]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect()->assertSessionMissing('cart')->assertSessionHas('cart-warning');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'quantity' => 3]);
        $this->get('/carrito')->assertSee('data-cart-count="3"', false);
        $this->get('/carrito')->assertSee('data-cart-count="3"', false);
    }

    public function test_stock_changes_and_unavailable_products_adjust_persisted_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->post('/carrito', ['product_id' => $product->id, 'quantity' => 5]);
        $product->update(['stock' => 2]);
        $this->get('/carrito')->assertSee('data-cart-count="2"', false)->assertSee('Stock insuficiente');
        $this->assertDatabaseHas('cart_items', ['quantity' => 2]);
        $product->update(['is_active' => false]);
        $this->get('/carrito')->assertSee('Tu carrito está vacío');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_registration_preserves_guest_cart_and_prices_handle_zero_or_invalid_sales(): void
    {
        $free = $this->product(['price' => 1000, 'sale_price' => 0]);
        $regular = $this->product(['name' => 'Sin oferta', 'price' => 2000, 'sale_price' => null]);
        $this->post('/carrito', ['product_id' => $free->id, 'quantity' => 1]);
        $this->post('/carrito', ['product_id' => $regular->id, 'quantity' => 2]);
        $this->post('/register', ['name' => 'Nakama', 'email' => 'nakama@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertRedirect()->assertSessionMissing('cart');
        $this->get('/carrito')->assertOk()->assertViewHas('total', 4000)->assertSee('data-cart-count="3"', false);
        $this->assertDatabaseCount('cart_items', 2);
        $regular->update(['sale_price' => 2000]);
        $this->get('/carrito')->assertViewHas('total', 4000);
    }

    public function test_all_product_surfaces_offer_working_cart_forms(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $related = $this->product(['name' => 'Relacionado']);
        $user->favorites()->attach($product);
        $this->actingAs($user);
        foreach (['/', '/catalogo', '/mi-cuenta/favoritos', route('producto.show', $product)] as $url) {
            $this->get($url)->assertOk()->assertSee('action="'.route('cart.store').'"', false)
                ->assertDontSee('Carrito próximamente')->assertDontSee('id="cart-preview-note"', false);
        }
        $this->get(route('producto.show', $product))->assertSee('Agregar al carrito: '.$related->name);
    }
}
