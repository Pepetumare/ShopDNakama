<?php

namespace Tests\Feature;

use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\StoreTestCase;

class MercadoPagoWebhookEndpointTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.webhook_secret' => 'endpoint-test-secret']);
        // Laravel normally skips CSRF in tests. Exercise the real check here.
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function order(): Order
    {
        $product = $this->product(['stock' => 8]);
        $order = Order::create(['payment_method' => 'mercadopago', 'status' => 'pending', 'payment_status' => 'pending', 'subtotal' => 10000, 'shipping_cost' => 0, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'endpoint-pref'])->save();
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);

        return $order;
    }

    private function signature(): array
    {
        $timestamp = (string) time();
        $hash = hash_hmac('sha256', "id:123;request-id:endpoint-request;ts:$timestamp;", 'endpoint-test-secret');

        return ['x-request-id' => 'endpoint-request', 'x-signature' => "ts=$timestamp,v1=$hash"];
    }

    public function test_route_is_exactly_one_public_post_without_api_prefix_or_auth_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->uri() === 'webhooks/mercadopago');
        $this->assertCount(1, $routes);
        $route = $routes->first();
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('webhooks.mercadopago', $route->getName());
        $this->assertSame(MercadoPagoWebhookController::class, $route->getActionName());
        $this->assertSame('/webhooks/mercadopago', route('webhooks.mercadopago', absolute: false));
        // Laravel configures the web group when the HTTP kernel is resolved.
        app(Kernel::class);
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertContains(ValidateCsrfToken::class, $middleware);
        $this->assertNotContains(Authenticate::class, $middleware);
        $this->assertNotContains(AdminMiddleware::class, $middleware);
    }

    public function test_post_reaches_controller_without_auth_or_csrf_token(): void
    {
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $this->assertGuest();
        $this->postJson('/webhooks/mercadopago', ['type' => 'merchant_order'])->assertOk()->assertExactJson(['received' => true]);
        // A payment reaches the controller's ID validation, not auth, CSRF or a 405.
        $this->postJson('/webhooks/mercadopago', ['type' => 'payment'])->assertStatus(400);
        $this->assertGuest();
    }

    public function test_get_and_head_do_not_process_payments_even_with_a_valid_signature(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $url = '/webhooks/mercadopago?data.id=123&type=payment';
        $this->getJson($url, $this->signature())->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->json('HEAD', $url, [], $this->signature())->assertStatus(405);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(8, $order->items->first()->product->stock);
    }

    public function test_csrf_remains_enabled_for_other_routes_and_neighbouring_webhooks(): void
    {
        $this->postJson('/carrito', [])->assertStatus(419);
        Route::post('/webhooks/mercadopago/other', fn () => response()->noContent())->middleware('web');
        $this->postJson('/webhooks/mercadopago/other', [])->assertStatus(419);
    }

    public function test_missing_or_invalid_signature_is_rejected_before_consulting_payment_api(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        foreach ([[], ['x-request-id' => 'endpoint-request', 'x-signature' => 'ts=123,v1=invalid']] as $headers) {
            $this->postJson('/webhooks/mercadopago?data.id=123', ['type' => 'payment', 'data' => ['id' => '123']], $headers)->assertUnauthorized();
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(8, $order->items->first()->product->stock);
    }

    public function test_valid_signed_public_post_processes_payment_once_with_real_csrf_middleware(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->times(4)->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'amount' => 10000, 'currency' => 'CLP', 'status' => 'approved',
        ]);
        $this->assertGuest();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/webhooks/mercadopago?data.id=123', ['type' => 'payment', 'data' => ['id' => '123']], $this->signature())
                ->assertOk()->assertExactJson(['received' => true]);
        }
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertNotNull($order->stock_deducted_at);
        $this->assertSame(7, $order->items->first()->product->stock);
        $this->assertGuest();
    }
}
