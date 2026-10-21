<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Webhook\WebhookSignatureValidator;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoService $mercadoPago)
    {
        if ($request->input('type') !== 'payment') {
            return response()->json(['received' => true]);
        }
        // PHP normalizes dots in query keys to underscores.
        $id = $request->query('data_id') ?? $request->query('data.id');
        abort_unless(is_scalar($id) && preg_match('/^\d+$/', (string) $id), 400);
        $id = (string) $id;
        abort_unless((string) $request->input('data.id') === $id, 400);
        $secret = config('services.mercadopago.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        try {
            WebhookSignatureValidator::validate($request->header('x-signature'), $request->header('x-request-id'), $id, $secret);
        } catch (InvalidWebhookSignatureException $exception) {
            abort(401);
        }
        // Failures return 5xx so Mercado Pago retries. No notification body is trusted.
        DB::transaction(function () use ($id, $mercadoPago) {
            // Consult inside the lock to avoid applying stale, out-of-order snapshots.
            $payment = $mercadoPago->getPayment($id);
            $order = Order::where('number', $payment['external_reference'] ?? '')->lockForUpdate()->first();
            abort_unless($order && $order->payment_method === 'mercadopago' && $order->mercadopago_preference_id, 422);
            // Re-fetch after acquiring the lock in case another notification was processing.
            $payment = $mercadoPago->getPayment($id);
            abort_unless((string) $payment['id'] === $id && $payment['external_reference'] === $order->number
                && $payment['currency'] === 'CLP'
                && (int) round((float) $payment['amount'] * 100) === (int) round((float) $order->total * 100), 422);
            // Once a successful payment is associated, another attempt cannot replace it.
            if ($order->mercadopago_payment_id && $order->mercadopago_payment_id !== $id
                && in_array($order->payment_status, ['paid', 'refunded'])) {
                return;
            }
            $status = match ($payment['status']) {
                'approved' => 'paid', 'rejected' => 'rejected', 'refunded', 'charged_back' => 'refunded',
                'cancelled' => 'cancelled', default => 'pending',
            };
            if ($order->payment_status === 'refunded' || ($order->payment_status === 'paid' && ! in_array($status, ['paid', 'refunded']))) {
                return;
            }
            $order->forceFill(['mercadopago_payment_id' => $id, 'payment_status' => $status]);
            if ($status === 'paid' && ! $order->stock_deducted_at) {
                $items = $order->items;
                $products = Product::whereKey($items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $available = $items->isNotEmpty() && $items->every(fn ($item) => $products->has($item->product_id) && $products[$item->product_id]->stock >= $item->quantity);
                if ($available && $order->status !== 'cancelado') {
                    foreach ($items as $item) {
                        $products[$item->product_id]->decrement('stock', $item->quantity);
                    }
                    $order->forceFill(['stock_deducted_at' => now(), 'fulfillment_issue' => null]);
                    if (in_array($order->status, ['pending', 'pendiente'])) {
                        $order->status = 'confirmado';
                    }
                } else {
                    $order->fulfillment_issue = 'Pago confirmado: revisar stock o pedido cancelado antes de preparar o reembolsar.';
                }
            }
            $order->save();
        }, 3);

        return response()->json(['received' => true]);
    }
}
