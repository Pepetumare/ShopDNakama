<?php

namespace App\Services;

use App\Models\Order;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use RuntimeException;

class MercadoPagoService
{
    private function configure(): void
    {
        $token = config('services.mercadopago.access_token');
        if (! $token) {
            throw new RuntimeException('Mercado Pago no está configurado.');
        }
        MercadoPagoConfig::setAccessToken($token);
    }

    public function createPreference(Order $order): array
    {
        $appUrl = config('app.url');
        if (! is_string($appUrl) || trim($appUrl) === '') {
            throw new RuntimeException('Mercado Pago: APP_URL debe estar configurada.');
        }

        $baseUrl = rtrim(trim($appUrl), '/');
        if (! str_starts_with($baseUrl, 'https://')
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! parse_url($baseUrl, PHP_URL_HOST)
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
            || parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Mercado Pago: APP_URL debe ser una URL absoluta HTTPS válida, sin credenciales, query ni fragmento.');
        }

        $backUrls = [
            'success' => $baseUrl . '/checkout/mercadopago/success',
            'pending' => $baseUrl . '/checkout/mercadopago/pending',
            'failure' => $baseUrl . '/checkout/mercadopago/failure',
        ];
        foreach (['success', 'pending', 'failure'] as $result) {
            if (empty($backUrls[$result])) {
                throw new RuntimeException('Mercado Pago: falta una URL de retorno obligatoria.');
            }
        }

        $this->configure();
        $items = $order->items->map(fn ($item) => [
            'id' => (string) $item->product_id, 'title' => $item->product_name,
            'quantity' => $item->quantity, 'unit_price' => (float) $item->unit_price, 'currency_id' => 'CLP',
        ])->all();
        $preference = (new PreferenceClient)->create([
            'items' => $items,
            'payer' => ['name' => $order->customer_name, 'email' => $order->customer_email],
            'shipments' => ['cost' => (float) $order->shipping_cost],
            'external_reference' => $order->number,
            'metadata' => ['order_id' => $order->id],
            'back_urls' => $backUrls,
            'notification_url' => $baseUrl . '/webhooks/mercadopago',
            'auto_return' => 'approved',
        ]);

        return ['id' => $preference->id, 'url' => $preference->init_point];
    }

    public function getPayment(string $id): array
    {
        $this->configure();
        $payment = (new PaymentClient)->get($id);

        return ['id' => (string) $payment->id, 'status' => $payment->status,
            'external_reference' => $payment->external_reference,
            'amount' => $payment->transaction_amount, 'currency' => $payment->currency_id];
    }
}
