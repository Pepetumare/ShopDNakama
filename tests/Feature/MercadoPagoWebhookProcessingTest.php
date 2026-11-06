<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\StoreTestCase;

class MercadoPagoWebhookProcessingTest extends StoreTestCase
{
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(function ($event, $context) {
            $this->events[] = ['event' => $event, 'context' => $context];
        });
        Log::shouldReceive('channel')->with('mercadopago_webhook')->andReturn($logger);
    }

    private function order(): Order
    {
        $product = $this->product(['stock' => 8]);
        $order = Order::create(['payment_method' => 'mercadopago', 'status' => 'pending', 'payment_status' => 'pending', 'subtotal' => 10000, 'total' => 10000]);
        $order->forceFill(['mercadopago_preference_id' => 'processing-pref'])->save();
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]);

        return $order;
    }

    private function signature(): array
    {
        config(['services.mercadopago.webhook_secret' => 'processing-secret']);
        $timestamp = (string) time();
        $hash = hash_hmac('sha256', "id:123;request-id:processing-request;ts:$timestamp;", 'processing-secret');

        return ['x-request-id' => 'processing-request', 'x-signature' => "ts=$timestamp,v1=$hash"];
    }

    public function test_legitimate_query_only_notification_is_processed_without_a_body_data_id(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->twice()->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'amount' => 10000, 'currency' => 'CLP', 'status' => 'approved',
        ]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], $this->signature())->assertOk();
        }
        $order->refresh();
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(7, $order->items->first()->product->stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->id, 'type' => 'sale', 'quantity' => -1, 'before' => 8, 'after' => 7, 'user_id' => null]);
    }

    public function test_php_normalized_and_literal_query_keys_are_distinct_from_nested_body_input(): void
    {
        $request = Request::create('/webhooks/mercadopago?data.id=123&type=payment', 'POST');
        $this->assertSame('123', $request->query('data_id'));
        $this->assertNull($request->query('data.id'));
        $this->assertNull($request->input('data.id'));
        $request->query->replace(['data.id' => '123', 'type' => 'payment']);
        $this->assertSame('123', $request->query('data.id'));
        $this->assertNull($request->input('data.id'));
        $request->request->replace(['data' => ['id' => '999']]);
        $this->assertSame('999', $request->input('data.id'));
        $this->assertSame('123', $request->query('data.id'));
    }

    public function test_query_id_and_type_override_body_and_only_api_payment_status_is_trusted(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'amount' => 10000, 'currency' => 'CLP', 'status' => 'rejected',
        ]);
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', ['type' => 'merchant_order', 'data' => ['id' => '999'], 'status' => 'approved', 'external_reference' => 'forged', 'transaction_amount' => 1], $this->signature())->assertOk();
        $order->refresh();
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertSame('rejected', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame(8, $order->items->first()->product->stock);
    }

    public function test_body_id_without_query_is_not_accepted_as_a_signed_payment_id(): void
    {
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $this->postJson('/webhooks/mercadopago?type=payment', ['data' => ['id' => '123']], $this->signature())->assertStatus(400);
    }

    public function test_signature_failure_is_logged_without_logging_header_values_or_secrets(): void
    {
        $headers = $this->signature();
        $headers['x-signature'] = 'ts=123,v1=DO-NOT-LOG-THIS-SIGNATURE';
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', ['action' => 'payment.updated', 'access_token' => 'DO-NOT-LOG-THIS-TOKEN', 'card' => ['number' => 'DO-NOT-LOG-THIS-CARD']], $headers)->assertUnauthorized();
        $signatureEvent = collect($this->events)->firstWhere('event', 'webhook_signature')['context'];
        $this->assertFalse($signatureEvent['signature_valid']);
        $this->assertSame('SignatureMismatch', $signatureEvent['reason']);
        $this->assertSame(InvalidWebhookSignatureException::class, $signatureEvent['exception_class']);
        $json = json_encode($this->events);
        foreach (['processing-secret', 'processing-request', 'DO-NOT-LOG-THIS-SIGNATURE', 'DO-NOT-LOG-THIS-TOKEN', 'DO-NOT-LOG-THIS-CARD'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $json);
        }
    }

    public function test_logs_trace_committed_updates_and_report_query_and_body_id_separately(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'preference_id' => 'processing-pref', 'amount' => 10000, 'currency' => 'CLP', 'status' => 'approved',
        ]);
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', ['data' => ['id' => '999']], $this->signature())->assertOk();
        $received = collect($this->events)->firstWhere('event', 'webhook_received')['context'];
        $this->assertSame('123', $received['data_id_query']);
        $this->assertSame('999', $received['data_id_body']);
        $this->assertTrue($received['has_x_signature']);
        $this->assertTrue($received['has_x_request_id']);
        $this->assertTrue(collect($this->events)->firstWhere('event', 'webhook_signature')['context']['signature_valid']);
        $fetched = collect($this->events)->firstWhere('event', 'webhook_payment_fetched')['context'];
        $this->assertSame('123', $fetched['payment_id']);
        $this->assertSame('processing-pref', $fetched['preference_id']);
        $processed = collect($this->events)->firstWhere('event', 'webhook_processed')['context'];
        $this->assertSame($order->id, $processed['order_id']);
        $this->assertSame('pending', $processed['payment_status_before']);
        $this->assertSame('paid', $processed['payment_status_after']);
        $this->assertSame('pending', $processed['order_status_before']);
        $this->assertSame('confirmed', $processed['order_status_after']);
        $this->assertSame($received['notification_id'], $processed['notification_id']);
    }

    public function test_pending_payment_stays_pending_without_deducting_stock(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'amount' => 10000, 'currency' => 'CLP', 'status' => 'pending',
        ]);
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], $this->signature())->assertOk();
        $order->refresh();
        $this->assertSame('123', $order->mercadopago_payment_id);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame(8, $order->items->first()->product->stock);
    }

    public function test_api_failure_returns_retryable_status_and_logs_no_exception_payload(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andThrow(new \RuntimeException('DO-NOT-LOG-SECRET-EXCEPTION-PAYLOAD'));
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], $this->signature())->assertStatus(503);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertStringNotContainsString('DO-NOT-LOG-SECRET-EXCEPTION-PAYLOAD', json_encode($this->events));
        $this->assertNull(collect($this->events)->firstWhere('event', 'webhook_processed'));
    }

    public function test_diagnostics_can_be_disabled_without_changing_payment_processing(): void
    {
        config(['services.mercadopago.webhook_diagnostics' => false]);
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], array_merge($this->signature(), ['x-signature' => 'invalid']))->assertUnauthorized();
        $this->assertSame([], $this->events);
    }

    public function test_signature_computed_from_body_id_cannot_authorize_a_different_query_id(): void
    {
        $headers = $this->signature();
        $ts = (string) time();
        $headers['x-signature'] = 'ts='.$ts.',v1='.hash_hmac('sha256', "id:999;request-id:processing-request;ts:$ts;", 'processing-secret');
        $this->mock(MercadoPagoService::class)->shouldNotReceive('getPayment');
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', ['data' => ['id' => '999']], $headers)->assertUnauthorized();
    }

    public function test_api_preference_when_available_must_match_the_stored_preference(): void
    {
        $order = $this->order();
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andReturn([
            'id' => '123', 'external_reference' => $order->number, 'preference_id' => 'different-pref', 'amount' => 10000, 'currency' => 'CLP', 'status' => 'approved',
        ]);
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], $this->signature())->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->mercadopago_payment_id);
        $this->assertSame(8, $order->items->first()->product->stock);
    }

    public function test_api_http_status_is_logged_but_api_response_and_credentials_are_not(): void
    {
        $exception = new MPApiException('DO-NOT-LOG-API-MESSAGE', new MPResponse(401, ['access_token' => 'DO-NOT-LOG-API-CREDENTIAL']));
        $this->mock(MercadoPagoService::class)->shouldReceive('getPayment')->with('123')->once()->andThrow($exception);
        $this->postJson('/webhooks/mercadopago?data.id=123&type=payment', [], $this->signature())->assertStatus(503);
        $failure = collect($this->events)->firstWhere('event', 'webhook_failed')['context'];
        $this->assertSame(401, $failure['api_http_status']);
        $this->assertStringNotContainsString('DO-NOT-LOG-API-MESSAGE', json_encode($this->events));
        $this->assertStringNotContainsString('DO-NOT-LOG-API-CREDENTIAL', json_encode($this->events));
    }
}
