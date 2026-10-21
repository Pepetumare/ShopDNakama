<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\StoreTestCase;

class FavoritesTest extends StoreTestCase
{
    public function test_guests_cannot_add_remove_or_view_favorites(): void
    {
        $product = $this->product();
        $this->post(route('favorites.store', $product))->assertRedirect(route('login'));
        $this->delete(route('favorites.destroy', $product))->assertRedirect(route('login'));
        $this->get('/mi-cuenta/favoritos')->assertRedirect(route('login'));
        $this->assertDatabaseCount('favorites', 0);
        $this->get('/catalogo')->assertOk()->assertSee('data-favorites-count="0"', false)
            ->assertSee('aria-pressed="false"', false);
    }

    public function test_user_can_add_a_favorite_with_timestamps_and_reload_its_persisted_state(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['name' => 'Figura favorita']);
        $this->actingAs($user)->from('/catalogo')->post(route('favorites.store', $product))
            ->assertRedirect('/catalogo')->assertSessionHas('favorite-status');
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'product_id' => $product->id]);
        $row = DB::table('favorites')->first();
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
        $this->assertTrue($user->favorites->first()->is($product));
        $this->assertTrue($product->favoritedBy->first()->is($user));
        $this->get('/catalogo')->assertOk()->assertSee('aria-label="Quitar de favoritos: Figura favorita"', false)
            ->assertSee('aria-pressed="true"', false);
        $this->get('/catalogo')->assertOk()->assertSee('aria-pressed="true"', false);
    }

    public function test_repeated_adds_do_not_duplicate_the_user_product_pair(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('favorites.store', $product), ['user_id' => $other->id])->assertRedirect();
        }
        $this->assertDatabaseCount('favorites', 1);
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'product_id' => $product->id]);
        $this->assertDatabaseMissing('favorites', ['user_id' => $other->id]);
        $index = collect(DB::getSchemaBuilder()->getIndexes('favorites'))->firstWhere('name', 'favorites_user_id_product_id_unique');
        $this->assertTrue($index['unique']);
        $this->assertSame(['user_id', 'product_id'], $index['columns']);
    }

    public function test_removing_favorites_is_idempotent_and_does_not_affect_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $user->favorites()->attach($product);
        $other->favorites()->attach($product);
        $this->actingAs($user)->from('/mi-cuenta/favoritos')->delete(route('favorites.destroy', $product), ['user_id' => $other->id])
            ->assertRedirect('/mi-cuenta/favoritos');
        $this->delete(route('favorites.destroy', $product))->assertRedirect();
        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $other->id, 'product_id' => $product->id]);
        $this->get('/catalogo')->assertOk()->assertSee('data-favorites-count="0"', false)->assertSee('aria-pressed="false"', false);
    }

    public function test_favorites_page_only_contains_the_authenticated_users_products(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->product(['name' => 'Mi producto guardado']);
        $foreign = $this->product(['name' => 'Producto favorito ajeno']);
        $user->favorites()->attach($own);
        $other->favorites()->attach($foreign);
        $this->actingAs($user)->get('/mi-cuenta/favoritos?user_id='.$other->id)->assertOk()
            ->assertSee($own->name)->assertDontSee($foreign->name)->assertSee('product-card')->assertSee('aria-pressed="true"', false);
    }

    public function test_header_count_matches_the_real_list_after_adds_and_removals(): void
    {
        $user = User::factory()->create();
        $first = $this->product();
        $second = $this->product(['name' => 'Segundo favorito']);
        $this->actingAs($user)->post(route('favorites.store', $first))->assertRedirect();
        $this->post(route('favorites.store', $second))->assertRedirect();
        $this->post(route('favorites.store', $second))->assertRedirect();
        foreach (['/', '/catalogo', '/mi-cuenta/favoritos', route('producto.show', $first)] as $url) {
            $this->get($url)->assertOk()->assertSee('data-favorites-count="2"', false)->assertSee('aria-label="Favoritos: 2"', false);
        }
        $this->delete(route('favorites.destroy', $first))->assertRedirect();
        $this->get('/catalogo')->assertOk()->assertSee('data-favorites-count="1"', false);
        $this->get('/mi-cuenta/favoritos')->assertOk()->assertSee('Tienes 1 producto guardado.');
    }

    public function test_hearts_are_marked_on_home_catalog_detail_and_related_products(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['name' => 'Favorito principal']);
        $related = $this->product(['name' => 'Favorito relacionado']);
        $user->favorites()->attach([$product->id, $related->id]);
        $this->actingAs($user);
        foreach (['/', '/catalogo', route('producto.show', $product)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('aria-label="Quitar de favoritos: Favorito principal"', false)
                ->assertSee('aria-label="Quitar de favoritos: Favorito relacionado"', false);
            $this->assertSame(2, substr_count($response->getContent(), 'aria-pressed="true"'));
            $response->assertDontSee('favorite = !favorite', false);
        }
        $this->actingAs(User::factory()->create())->get('/catalogo')->assertOk()
            ->assertSee('aria-label="Agregar a favoritos: Favorito principal"', false)->assertDontSee('aria-pressed="true"', false);
    }

    public function test_empty_favorites_page_has_a_catalog_link(): void
    {
        $this->actingAs(User::factory()->create())->get('/mi-cuenta/favoritos')->assertOk()
            ->assertSee('Aún no tienes favoritos')->assertSee('Guarda productos para encontrarlos rápidamente después.')
            ->assertSee('Explorar catálogo')->assertSee(route('catalogo'));
    }

    public function test_missing_or_inactive_products_cannot_be_added_but_saved_unavailable_products_can_be_removed(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['name' => 'Guardado no disponible']);
        $user->favorites()->attach($product);
        $product->update(['is_active' => false]);
        $this->actingAs($user)->post(route('favorites.store', $product))->assertNotFound();
        $this->get('/mi-cuenta/favoritos')->assertOk()->assertSee('Guardado no disponible')
            ->assertSee('No disponible')->assertSee('data-favorites-count="1"', false)->assertDontSee(route('producto.show', $product));
        $this->delete(route('favorites.destroy', $product))->assertRedirect();
        $this->assertDatabaseCount('favorites', 0);
        $this->post('/favoritos/no-existe')->assertNotFound();
        $this->delete('/favoritos/no-existe')->assertNotFound();
        $category = Category::create(['name' => 'Inactiva', 'is_active' => false]);
        $hidden = $this->product(['category_id' => $category->id]);
        $this->post(route('favorites.store', $hidden))->assertNotFound();
    }

    public function test_favorites_list_is_paginated_without_leaking_other_users_data(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 13; $i++) {
            $user->favorites()->attach($this->product(['name' => sprintf('Favorito %02d', $i)]));
        }
        $this->actingAs($user)->get('/mi-cuenta/favoritos')->assertOk()->assertSee('Tienes 13 productos guardados.')
            ->assertSee('Página 1 de 2')->assertDontSee('Favorito 01');
        $this->get('/mi-cuenta/favoritos?page=2')->assertOk()->assertSee('Favorito 01')->assertSee('Página 2 de 2');
        $lastPageProduct = $user->favorites()->orderBy('products.id')->first();
        $this->from('/mi-cuenta/favoritos?page=2')->delete(route('favorites.destroy', $lastPageProduct))->assertRedirect('/mi-cuenta/favoritos?page=2');
        $this->get('/mi-cuenta/favoritos?page=2')->assertRedirect(route('account.favorites', ['page' => 1]));
    }
}
