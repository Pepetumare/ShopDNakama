<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSingleRequestTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Mockery;
use Tests\TestCase;

class CustomQuoteMercadoPagoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_sdk_gets_correct_preference_payload_and_stable_idempotency_key_without_network(): void
    {
        config(['app.url' => 'https://nakama.example', 'services.mercadopago.access_token' => 'TEST-fake']);
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['product_type' => 'mug', 'quantity' => 2, 'customer_name' => $user->name, 'customer_email' => $user->email, 'quoted_price' => 10000, 'status' => 'quoted']);
        $order = $quote->order()->create(['user_id' => $user->id, 'customer_name' => $user->name, 'customer_email' => $user->email, 'payment_method' => 'mercadopago', 'subtotal' => 10000, 'shipping_cost' => 3990, 'total' => 13990]);
        $order->items()->create(['product_name' => 'Personalizado '.$quote->request_number, 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);
        $originalClient = MercadoPagoConfig::getHttpClient();
        $originalToken = MercadoPagoConfig::getAccessToken();
        $requests = [];
        $transport = Mockery::mock(MPHttpClient::class);
        $transport->shouldReceive('send')->times(3)->andReturnUsing(function (MPRequest $request) use (&$requests) {
            $requests[] = $request;

            return new MPResponse(201, ['id' => 'sdk-pref', 'init_point' => 'https://www.mercadopago.cl/sdk-checkout']);
        });
        MercadoPagoConfig::setHttpClient($transport);
        $this->app->instance(MercadoPagoSingleRequestTransport::class, $transport);
        try {
            $service = new MercadoPagoService;
            $this->assertSame(['id' => 'sdk-pref', 'url' => 'https://www.mercadopago.cl/sdk-checkout'], $service->createPreference($order));
            $service->createPreference($order);
            $this->assertSame('sdk-pref', $service->getPreference('sdk-pref')['id']);
            $payload = json_decode($requests[0]->getPayload(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('/checkout/preferences', $requests[0]->getUri());
            $this->assertSame('POST', $requests[0]->getMethod());
            $this->assertSame($order->number, $payload['external_reference']);
            $this->assertSame($order->id, $payload['metadata']['order_id']);
            $this->assertSame(1, $payload['items'][0]['quantity']);
            $this->assertEquals(10000, $payload['items'][0]['unit_price']);
            $this->assertSame('custom-'.$quote->id, $payload['items'][0]['id']);
            $this->assertEquals(3990, $payload['shipments']['cost']);
            $this->assertSame('https://nakama.example/webhooks/mercadopago', $payload['notification_url']);
            foreach (['success', 'pending', 'failure'] as $result) {
                $this->assertSame('https://nakama.example/checkout/mercadopago/'.$result, $payload['back_urls'][$result]);
            }
            foreach (array_slice($requests, 0, 2) as $request) {
                $keys = array_values(array_filter($request->getHeaders(), fn ($header) => str_starts_with(strtolower($header), 'x-idempotency-key:')));
                $this->assertSame(['X-Idempotency-Key: custom-order-'.$order->number], $keys);
            }
            $this->assertSame('GET', $requests[2]->getMethod());
            $this->assertSame('/checkout/preferences/sdk-pref', $requests[2]->getUri());
        } finally {
            MercadoPagoConfig::setHttpClient($originalClient);
            MercadoPagoConfig::setAccessToken($originalToken);
        }
    }
}
