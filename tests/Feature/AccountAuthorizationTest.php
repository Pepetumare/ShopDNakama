<?php

namespace Tests\Feature;

use App\Http\Controllers\HousekeepingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_account_or_housekeeping_routes(): void
    {
        foreach (['/mi-cuenta', '/mi-cuenta/datos', '/mi-cuenta/direcciones', '/mi-cuenta/favoritos', '/mi-cuenta/pedidos', '/mi-cuenta/pedidos/ejemplo-001', '/housekeeping', '/housekeeping/productos'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_normal_users_have_the_full_account_menu_without_housekeeping(): void
    {
        $user = User::factory()->create();
        $order = $user->orders()->create(['number' => 'CUENTA-001']);
        $this->actingAs($user)->get('/mi-cuenta')->assertOk()
            ->assertSee('Resumen')->assertSee('Mis datos')->assertSee('Mis direcciones')
            ->assertSee('Mis pedidos')->assertSee('Favoritos')->assertSee('Cerrar sesión')
            ->assertDontSee('Housekeeping');
        $this->get('/')->assertOk()->assertDontSee('Housekeeping');
        foreach (['datos', 'direcciones', 'favoritos', 'pedidos', 'pedidos/'.$order->id] as $section) {
            $this->get('/mi-cuenta/'.$section)->assertOk()->assertDontSee('Housekeeping');
        }
    }

    public function test_normal_users_receive_403_on_dashboard_and_every_admin_module(): void
    {
        $this->actingAs(User::factory()->create())->get('/housekeeping')->assertForbidden();
        foreach (array_keys(HousekeepingController::MODULES) as $module) {
            $this->get('/housekeeping/'.$module)->assertForbidden();
        }
    }

    public function test_admins_have_the_same_account_menu_with_the_extra_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $admin->orders()->create(['number' => 'CUENTA-ADMIN-001']);
        $this->actingAs($admin)->get('/mi-cuenta')->assertOk()
            ->assertSee('Resumen')->assertSee('Mis datos')->assertSee('Mis direcciones')
            ->assertSee('Mis pedidos')->assertSee('Favoritos')->assertSee('Cerrar sesión')
            ->assertSee('Housekeeping')->assertSee(route('housekeeping.dashboard'));
        $this->get('/')->assertOk()->assertSee('Housekeeping');
        foreach (['datos', 'direcciones', 'favoritos', 'pedidos', 'pedidos/'.$order->id] as $section) {
            $this->get('/mi-cuenta/'.$section)->assertOk();
        }
    }

    public function test_admins_can_access_dashboard_and_all_prepared_modules(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/housekeeping')
            ->assertOk()->assertSee('Resumen administrativo')->assertSee('Usuarios registrados');
        foreach (HousekeepingController::MODULES as $module => $label) {
            if (in_array($module, ['productos', 'categorias', 'pedidos', 'clientes'])) {
                $this->get('/housekeeping/'.$module)->assertRedirect(HousekeepingController::moduleUrl($module));
                $this->get(HousekeepingController::moduleUrl($module))->assertOk()->assertSee($label);
            } else {
                $this->get('/housekeeping/'.$module)->assertOk()->assertSee($label);
            }
        }
        $this->get('/housekeeping/modulo-inexistente')->assertNotFound();
    }

    public function test_registration_cannot_assign_an_admin_role(): void
    {
        $this->post('/register', [
            'name' => 'Nakama', 'email' => 'nakama@example.com', 'role' => 'admin',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('user', User::where('email', 'nakama@example.com')->firstOrFail()->role);
        $this->get('/housekeeping')->assertForbidden();
    }

    public function test_account_and_legacy_profile_updates_cannot_promote_users(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $this->actingAs($user)->patch('/mi-cuenta/datos', [
            'name' => 'Nombre actualizado', 'email' => $user->email, 'role' => 'admin',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.data'));
        $this->assertSame('Nombre actualizado', $user->fresh()->name);
        $this->assertSame('user', $user->fresh()->role);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->patch('/profile', ['name' => 'Otro nombre', 'email' => $user->email, 'role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->isAdmin());
        $this->get('/housekeeping')->assertForbidden();
    }

    public function test_role_defaults_to_user_in_the_database(): void
    {
        DB::table('users')->insert(['name' => 'Usuario existente', 'email' => 'default@example.com', 'password' => Hash::make('password')]);
        $user = User::where('email', 'default@example.com')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertFalse($user->isAdmin());
        $this->assertNotContains('role', $user->getFillable());
    }

    public function test_order_routes_do_not_expose_arbitrary_or_old_example_identifiers(): void
    {
        $this->actingAs(User::factory()->create())->get('/mi-cuenta/pedidos/12345')->assertNotFound();
        $this->get('/mi-cuenta/pedidos/ejemplo-001')->assertNotFound();
        $this->get('/dashboard')->assertRedirect(route('account.index'));
    }

    public function test_account_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/housekeeping')->assertRedirect(route('login'));
    }
}
