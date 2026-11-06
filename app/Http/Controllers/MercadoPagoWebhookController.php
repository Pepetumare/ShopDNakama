<?php

namespace App\Http\Controllers;

use App\Services\PaymentReconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Webhook\WebhookSignatureValidator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentReconciliation $reconciliation)
    {
        $query = $request->query();
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $type = $query['type'] ?? ($body['type'] ?? null);
        // Query bags may contain the literal key, while PHP normalizes it to data_id.
        // input('data.id') uses dot traversal and is NOT a read of that flat query key.
        $id = $query['data.id'] ?? $query['data_id'] ?? null;
        $context = ['notification_id' => (string) Str::uuid(), 'type' => $type, 'action' => $body['action'] ?? null,
            'data_id_query' => $id, 'data_id_body' => data_get($body, 'data.id'),
            'has_x_signature' => filled($request->header('x-signature')), 'has_x_request_id' => filled($request->header('x-request-id'))];
        $this->trace('webhook_received', $context);
        if ($type !== 'payment') {
            $this->trace('webhook_ignored', $context + ['reason' => 'unsupported_type']);

            return response()->json(['received' => true]);
        }
        if (! is_scalar($id) || ! preg_match('/\A\d{1,64}\z/', (string) $id)) {
            $this->trace('webhook_failed', $context + ['reason' => 'invalid_query_data_id']);
            abort(400);
        }
        if (isset($query['data.id'], $query['data_id']) && $query['data.id'] !== $query['data_id']) {
            $this->trace('webhook_failed', $context + ['reason' => 'ambiguous_query_data_id']);
            abort(400);
        }
        $id = (string) $id;
        $secret = config('services.mercadopago.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            $this->trace('webhook_signature', $context + ['signature_valid' => false, 'reason' => 'webhook_secret_missing']);
            abort(503);
        }
        try {
            WebhookSignatureValidator::validate($request->header('x-signature'), $request->header('x-request-id'), $id, $secret);
        } catch (InvalidWebhookSignatureException $exception) {
            $this->trace('webhook_signature', $context + ['signature_valid' => false, 'exception_class' => $exception::class, 'reason' => $exception->getReason()]);
            abort(401);
        }
        $this->trace('webhook_signature', $context + ['signature_valid' => true]);
        // Failures return 5xx so Mercado Pago retries. No notification body is trusted.
        try {
            $updated = $reconciliation->verify($id, 'webhook', $context['notification_id']);
            $this->trace('webhook_payment_fetched', $context + ['order_id' => $updated['order_id']] + $updated['payment']);
            $this->trace('webhook_order_located', $context + ['order_id' => $updated['order_id'],
                'stored_preference_id' => $updated['stored_preference_id']]);
            $http = $updated['http'];
            unset($updated['payment'], $updated['http'], $updated['stored_preference_id']);
            abort_unless($http === 200, $http);
            $this->trace('webhook_processed', $context + $updated);
        } catch (HttpExceptionInterface $exception) {
            $this->trace('webhook_failed', $context + ['exception_class' => $exception::class, 'reason' => 'payment_validation_failed', 'http_status' => $exception->getStatusCode()]);
            throw $exception;
        } catch (\Throwable $exception) {
            // API exception messages/payloads may contain credentials; never log them.
            $this->trace('webhook_failed', $context + ['exception_class' => $exception::class, 'reason' => 'payment_api_or_transaction_failed', 'http_status' => 503,
                'api_http_status' => $exception instanceof MPApiException ? $exception->getStatusCode() : null]);

            return response()->json(['received' => false], 503);
        }

        return response()->json(['received' => true]);
    }

    private function trace(string $event, array $context): void
    {
        if (! config('services.mercadopago.webhook_diagnostics', true)) {
            return;
        }
        // Only scalar identifiers/enums; no body dump, headers, raw exceptions or secrets.
        foreach ($context as $key => $value) {
            if ($value === null || is_bool($value) || is_int($value)) {
                continue;
            }
            $context[$key] = is_string($value) && preg_match('/\A[a-zA-Z0-9_.:\\\\-]{1,160}\z/', $value) ? $value : '[invalid]';
        }
        try {
            Log::channel('mercadopago_webhook')->info($event, $context);
        } catch (\Throwable) {
            // Temporary diagnostics must not block payments if storage is unavailable.
        }
    }
}
