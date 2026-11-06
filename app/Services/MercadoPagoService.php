<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPSearchRequest;
use RuntimeException;

class MercadoPagoService
{
    private function configure(): void
    {
        $token = config('services.mercadopago.access_token');
        if (! $token) {
            throw new PreferenceNotSent('Mercado Pago no está configurado.');
        }
        MercadoPagoConfig::setAccessToken($token);
    }

    public function createPreference(Order $order): array
    {
        $appUrl = config('app.url');
        if (! is_string($appUrl) || trim($appUrl) === '') {
            throw new PreferenceNotSent('Mercado Pago: APP_URL debe estar configurada.');
        }

        $baseUrl = rtrim(trim($appUrl), '/');
        if (! str_starts_with($baseUrl, 'https://')
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! parse_url($baseUrl, PHP_URL_HOST)
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
            || parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null) {
            throw new PreferenceNotSent('Mercado Pago: APP_URL debe ser una URL absoluta HTTPS válida, sin credenciales, query ni fragmento.');
        }

        $backUrls = [
            'success' => $baseUrl.'/checkout/mercadopago/success',
            'pending' => $baseUrl.'/checkout/mercadopago/pending',
            'failure' => $baseUrl.'/checkout/mercadopago/failure',
        ];
        foreach (['success', 'pending', 'failure'] as $result) {
            if (empty($backUrls[$result])) {
                throw new RuntimeException('Mercado Pago: falta una URL de retorno obligatoria.');
            }
        }

        $this->configure();
        $items = $order->items->map(fn ($item) => [
            'id' => $order->custom_request_id && $item->product_id === null ? 'custom-'.$order->custom_request_id : (string) $item->product_id, 'title' => $item->product_name,
            'quantity' => $item->quantity, 'unit_price' => (float) $item->unit_price, 'currency_id' => 'CLP',
        ])->all();
        $options = null;
        if ($order->custom_request_id) {
            $options = new RequestOptions;
            $options->setCustomHeaders(['X-Idempotency-Key: custom-order-'.$order->number]);
        }
        $reservation = $order->custom_request_id ? null : app(StockReservations::class)->rows($order)->first();
        $validity = $reservation ? [
            'expires' => true,
            'expiration_date_from' => Carbon::parse($reservation->created_at)->toIso8601String(),
            'expiration_date_to' => Carbon::parse($reservation->expires_at)->toIso8601String(),
        ] : [];
        $client = new PreferenceClient($reservation ? app(MercadoPagoSingleRequestTransport::class) : null);
        $preference = $client->create([
            'items' => $items,
            'payer' => ['name' => $order->customer_name, 'email' => $order->customer_email],
            'shipments' => ['cost' => (float) $order->shipping_cost],
            'external_reference' => $order->number,
            'metadata' => ['order_id' => $order->id],
            'back_urls' => $backUrls,
            'notification_url' => $baseUrl.'/webhooks/mercadopago',
            'auto_return' => 'approved',
        ] + $validity, $options);

        return ['id' => $preference->id, 'url' => $preference->init_point];
    }

    public function findPreference(Order $order): ?array
    {
        $this->configure();
        $client = new PreferenceClient(app(MercadoPagoSingleRequestTransport::class));
        $search = $client->search(new MPSearchRequest(2, 0, ['external_reference' => $order->number]));
        if (($search->total ?? null) !== 1 || count($search->elements ?? []) !== 1 || blank($search->elements[0]->id ?? null)) {
            return null;
        }

        $preference = $client->get($search->elements[0]->id);
        $reservation = app(StockReservations::class)->rows($order)->first();
        if (! $reservation || ($preference->external_reference ?? null) !== $order->number
            || ($preference->id ?? null) !== $search->elements[0]->id || ($preference->expires ?? false) !== true
            || blank($preference->expiration_date_to ?? null)
            || ! Carbon::parse($preference->expiration_date_to)->equalTo(Carbon::parse($reservation->expires_at))
            || ! $this->matchesOrder($order, $preference)) {
            return null;
        }

        return ['id' => $preference->id, 'url' => $preference->init_point ?? null];
    }

    private function matchesOrder(Order $order, \MercadoPago\Resources\Preference $preference): bool
    {
        $items = collect($preference->items ?? []);
        $byId = $items->keyBy('id');
        $order->loadMissing('items');
        if ($items->count() !== $order->items->count() || $byId->count() !== $items->count()
            || round((float) data_get($preference->shipments ?? null, 'cost', 0) * 100) !== round((float) $order->shipping_cost * 100)) {
            return false;
        }

        return $order->items->every(function ($line) use ($byId) {
            $item = $byId->get($line->product_id);

            return ($item->currency_id ?? null) === 'CLP'
                && ($item->quantity ?? null) === (int) $line->quantity
                && isset($item->unit_price)
                && round($item->unit_price * 100) === round((float) $line->unit_price * 100);
        });
    }

    public function getPreference(string $id): array
    {
        $this->configure();
        $preference = (new PreferenceClient)->get($id);

        return ['id' => $preference->id, 'url' => $preference->init_point];
    }

    public function getPayment(string $id): array
    {
        $this->configure();
        $payment = (new PaymentClient(app(MercadoPagoSingleRequestTransport::class)))->get($id);
        // The SDK Payment DTO omits this field even when the response includes it.
        $preference = $payment->getResponse()->getContent()['preference_id'] ?? null;

        return ['id' => (string) $payment->id, 'status' => $payment->status,
            'external_reference' => $payment->external_reference,
            'amount' => $payment->transaction_amount, 'currency' => $payment->currency_id,
            'preference_id' => $preference,
            'approved_at' => $payment->date_approved ?? null, 'updated_at' => $payment->date_last_updated ?? null];
    }

    public function searchPayments(Order $order, int $offset): array
    {
        $this->configure();
        $limit = 10;
        $result = (new PaymentClient(app(MercadoPagoSingleRequestTransport::class)))->search(
            new MPSearchRequest($limit, $offset, ['external_reference' => $order->number, 'sort' => 'date_created', 'criteria' => 'asc'])
        );
        $rows = $result->results ?? null;
        $total = data_get($result->paging ?? null, 'total');
        if (! is_array($rows) || ! is_numeric($total) || count($rows) > $limit || ($rows === [] && $offset < (int) $total)) {
            throw new RuntimeException('Respuesta de búsqueda de pagos incompleta.');
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) data_get($row, 'id');
            if (! preg_match('/\A\d{1,64}\z/', $id)) {
                throw new RuntimeException('Identificador de pago inválido en búsqueda.');
            }
            $ids[] = $id;
        }

        $next = $offset + count($rows);

        return ['ids' => array_values(array_unique($ids)), 'next' => $next < (int) $total ? $next : null];
    }
}
