<?php

namespace App\Http\Controllers;

use App\Models\CustomRequest;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\StockManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Webhook\WebhookSignatureValidator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoService $mercadoPago)
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
            $updated = DB::transaction(function () use ($id, $mercadoPago, $context) {
                // Consult inside the lock to avoid applying stale, out-of-order snapshots.
                $payment = $mercadoPago->getPayment($id);
                $this->paymentTrace($payment, $context);
                $candidate = Order::where('number', $payment['external_reference'] ?? '')->first();
                $customRequest = $candidate?->custom_request_id
                    ? CustomRequest::whereKey($candidate->custom_request_id)->lockForUpdate()->firstOrFail() : null;
                $order = $candidate ? Order::whereKey($candidate->id)->lockForUpdate()->first() : null;
                $this->trace('webhook_order_located', $context + ['order_id' => $order?->id, 'stored_preference_id' => $order?->mercadopago_preference_id]);
                if (! $order) {
                    $this->trace('webhook_failed', $context + ['reason' => 'external_reference_order_not_found']);
                }
                abort_unless($order && $order->payment_method === 'mercadopago' && $order->mercadopago_preference_id, 422);
                // Re-fetch after acquiring the lock in case another notification was processing.
                $payment = $mercadoPago->getPayment($id);
                $this->paymentTrace($payment, $context + ['order_id' => $order->id]);
                $before = ['order_id' => $order->id, 'payment_status_before' => $order->payment_status, 'order_status_before' => $order->status];
                $valid = (string) ($payment['id'] ?? '') === $id && ($payment['external_reference'] ?? null) === $order->number
                    && ($payment['currency'] ?? null) === 'CLP'
                    && is_numeric($payment['amount'] ?? null)
                    && (int) round((float) $payment['amount'] * 100) === (int) round((float) $order->total * 100)
                    && (empty($payment['preference_id']) || $payment['preference_id'] === $order->mercadopago_preference_id);
                if (! $valid) {
                    $this->trace('webhook_failed', $context + $before + ['reason' => 'payment_identity_amount_currency_or_preference_mismatch']);
                }
                abort_unless($valid, 422);
                // Once a successful payment is associated, another attempt cannot replace it.
                if ($order->mercadopago_payment_id && $order->mercadopago_payment_id !== $id
                    && in_array($order->payment_status, ['paid', 'refunded'])) {
                    return $before + ['payment_status_after' => $order->payment_status, 'order_status_after' => $order->status, 'result' => 'different_payment_ignored'];
                }
                $status = match ($payment['status']) {
                    'approved' => 'paid', 'rejected' => 'rejected', 'refunded', 'charged_back' => 'refunded',
                    'cancelled' => 'cancelled', default => 'pending',
                };
                if ($order->payment_status === 'refunded' || ($order->payment_status === 'paid' && ! in_array($status, ['paid', 'refunded']))) {
                    return $before + ['payment_status_after' => $order->payment_status, 'order_status_after' => $order->status, 'result' => 'terminal_status_preserved'];
                }
                if ($customRequest && $order->payment_status === $status && $order->mercadopago_payment_id === $id
                    && ($order->stock_deducted_at || ! $order->items()->whereNotNull('product_id')->exists())) {
                    return $before + ['payment_status_after' => $order->payment_status, 'order_status_after' => $order->status, 'result' => 'duplicate_ignored'];
                }
                $order->forceFill(['mercadopago_payment_id' => $id, 'payment_status' => $status]);
                $stockAvailable = $status !== 'paid' || ((! $customRequest || in_array($customRequest->status, ['quoted', 'approved', 'in_production', 'ready', 'completed'], true)) && app(StockManager::class)->deductOrder($order));
                if ($customRequest && $status === 'paid') {
                    if ($customRequest->status === 'quoted' && $order->status !== 'cancelado') {
                        $customRequest->update(['status' => 'approved']);
                    }
                    if (in_array($customRequest->status, ['approved', 'in_production', 'ready', 'completed'], true) && $order->status !== 'cancelado') {
                        if (in_array($order->status, ['pending', 'pendiente'], true)) {
                            $order->status = 'confirmed';
                        }
                        $order->fulfillment_issue = null;
                    } else {
                        $order->fulfillment_issue = 'Pago confirmado para una solicitud cancelada o rechazada: revisar antes de producir o reembolsar.';
                    }
                } elseif ($customRequest && $status === 'refunded') {
                    $order->fulfillment_issue = 'Pago personalizado reembolsado: revisar la producción antes de continuar.';
                } elseif (! $customRequest && $status === 'paid') {
                    if ($stockAvailable && $order->status !== 'cancelado') {
                        $order->fulfillment_issue = null;
                        if (in_array($order->status, ['pending', 'pendiente'])) {
                            $order->status = 'confirmed';
                        }
                    } else {
                        $order->fulfillment_issue = 'Pago confirmado: revisar stock o pedido cancelado antes de preparar o reembolsar.';
                    }
                }
                if ($status === 'paid' && ! $stockAvailable) {
                    $order->fulfillment_issue = 'Pago confirmado: revisar stock o pedido cancelado antes de preparar o reembolsar.';
                }
                $order->save();

                return $before + ['payment_status_after' => $order->payment_status, 'order_status_after' => $order->status, 'result' => 'updated'];
            }, 3);
            // This log describes a committed transaction, never an update rolled back later.
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

    private function paymentTrace(array $payment, array $context): void
    {
        $this->trace('webhook_payment_fetched', $context + [
            'payment_id' => $payment['id'] ?? null, 'payment_status' => $payment['status'] ?? null,
            'external_reference' => $payment['external_reference'] ?? null, 'preference_id' => $payment['preference_id'] ?? null,
        ]);
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
