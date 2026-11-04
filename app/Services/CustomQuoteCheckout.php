<?php

namespace App\Services;

use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomQuoteCheckout
{
    public function orderFor(CustomRequest $customRequest, User $user, array $delivery): Order
    {
        return DB::transaction(function () use ($customRequest, $user, $delivery) {
            $quote = CustomRequest::whereKey($customRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accept', $quote);
            $this->assertQuoted($quote);
            $order = $quote->order()->lockForUpdate()->first();
            if ($order) {
                $this->assertPayable($order, $quote, $user);

                return $order;
            }
            foreach (['shipping_address_line1', 'shipping_city', 'shipping_region'] as $field) {
                if (! filled($delivery[$field] ?? null)) {
                    throw ValidationException::withMessages([$field => 'Completa los datos de despacho para continuar.']);
                }
            }
            $shipping = max(0, (int) config('services.mercadopago.shipping_cost'));
            $totalCents = $this->cents($quote->quoted_price) + $shipping * 100;
            if ($totalCents > 999999999999) {
                throw ValidationException::withMessages(['quote' => 'El total con despacho supera el monto permitido. Solicita una revisión de la cotización.']);
            }
            $order = $quote->order()->create(array_merge($delivery, [
                'user_id' => $user->id, 'customer_name' => $quote->customer_name, 'customer_email' => $quote->customer_email,
                'customer_phone' => $delivery['customer_phone'] ?? $quote->customer_phone,
                'shipping_country' => 'Chile', 'status' => 'pending', 'payment_status' => 'pending',
                'payment_method' => 'mercadopago', 'subtotal' => $quote->quoted_price, 'shipping_cost' => $shipping,
                'discount_total' => 0, 'total' => $totalCents / 100,
            ]));
            $order->forceFill(['delivery_type' => 'despacho'])->save();
            // One quoted lot: never multiply the agreed total by the requested quantity.
            $options = array_filter([
                $quote->product_label, $quote->project_name, $quote->variant, $quote->color,
                $quote->product_type === 'badges' ? 'Medida: Estándar' : $quote->size,
                $quote->print_position, $quote->dimensions, $quote->material,
                $quote->product_type === '3d_print' ? $quote->width.' × '.$quote->height.' × '.$quote->depth.' '.$quote->dimension_unit : null,
                $quote->is_patch && $quote->width !== null ? 'Ancho: '.$quote->width.' '.$quote->dimension_unit : null,
                $quote->is_patch && $quote->height !== null ? 'Alto: '.$quote->height.' '.$quote->dimension_unit : null,
                $quote->is_patch && $quote->shape ? 'Forma: '.(CustomRequest::PATCH_SHAPES[$quote->shape] ?? $quote->shape) : null,
                $quote->is_patch && $quote->border_color ? 'Color de borde: '.$quote->border_color : null,
                'Cantidad solicitada: '.$quote->quantity,
            ]);
            $order->items()->create([
                'product_id' => null, 'product_name' => 'Personalizado '.$quote->request_number,
                'description' => implode(' · ', $options), 'quantity' => 1,
                'unit_price' => $quote->effective_base_quoted_price, 'line_total' => $quote->effective_base_quoted_price,
            ]);
            if ($quote->design_assistance && $quote->design_assistance_fee > 0) {
                $order->items()->create([
                    'product_id' => null, 'product_name' => 'Diseño asistido por Shop D. Nakama',
                    'description' => 'Adaptación de diseño del lote completo · '.$quote->request_number,
                    'quantity' => 1, 'unit_price' => $quote->design_assistance_fee, 'line_total' => $quote->design_assistance_fee,
                ]);
            }

            return $order;
        }, 3);
    }

    public function checkoutUrl(Order $order, User $user, MercadoPagoService $mercadoPago): string
    {
        // Both local phases persist independently, so an API failure keeps the same pending order.
        // All custom-payment operations acquire locks in request -> order order.
        return DB::transaction(function () use ($order, $user, $mercadoPago) {
            $quote = CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accept', $quote);
            $this->assertQuoted($quote);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertPayable($locked, $quote, $user);
            if ($locked->mercadopago_preference_id && $locked->mercadopago_checkout_url) {
                return $locked->mercadopago_checkout_url;
            }
            if ($locked->mercadopago_preference_id) {
                // Recover a previously stored preference rather than creating another one.
                $preference = $mercadoPago->getPreference($locked->mercadopago_preference_id);
            } else {
                $preference = $mercadoPago->createPreference($locked->load('items'));
            }
            if (! filled($preference['id'] ?? null) || ! is_string($preference['url'] ?? null)
                || ! filter_var($preference['url'], FILTER_VALIDATE_URL) || parse_url($preference['url'], PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('Respuesta de preferencia incompleta.');
            }
            $locked->forceFill(['mercadopago_preference_id' => $preference['id'], 'mercadopago_checkout_url' => $preference['url']])->save();

            return $preference['url'];
        }, 3);
    }

    public function decline(CustomRequest $customRequest, User $user): void
    {
        DB::transaction(function () use ($customRequest, $user) {
            $quote = CustomRequest::whereKey($customRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('decline', $quote);
            $this->assertQuoted($quote, false);
            $order = $quote->order()->lockForUpdate()->first();
            if ($order && in_array($order->payment_status, ['paid', 'pagado', 'refunded', 'reembolsado'], true)) {
                throw ValidationException::withMessages(['quote' => 'El pedido ya tiene un pago confirmado.']);
            }
            $quote->update(['status' => 'cancelled']);
            if ($order) {
                $order->update(['status' => 'cancelado']);
            }
        }, 3);
    }

    private function assertQuoted(CustomRequest $quote, bool $requirePrice = true): void
    {
        if ($requirePrice && $quote->design_assistance
            && ($quote->base_quoted_price === null || $this->cents($quote->base_quoted_price) <= 0
                || $this->cents($quote->quoted_price) !== $this->cents($quote->base_quoted_price) + $quote->design_assistance_fee * 100)) {
            throw ValidationException::withMessages(['quote' => 'La cotización requiere revisión de su precio base y diseño asistido.']);
        }
        if ($quote->status !== 'quoted' || ($requirePrice && $this->cents($quote->quoted_price) <= 0)) {
            throw ValidationException::withMessages(['quote' => 'La solicitud no tiene una cotización vigente para aceptar o rechazar.']);
        }
    }

    private function assertPayable(Order $order, CustomRequest $quote, User $user): void
    {
        if ($order->user_id !== $user->id || $order->payment_method !== 'mercadopago'
            || ! in_array($order->status, ['pending', 'pendiente'], true)
            || ! in_array($order->payment_status, ['pending', 'pendiente', 'rejected', 'rechazado', 'cancelled'], true)
            || $this->cents($order->subtotal) !== $this->cents($quote->quoted_price)) {
            throw ValidationException::withMessages(['quote' => 'El pedido asociado no admite un nuevo pago. Revisa su estado.']);
        }
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
