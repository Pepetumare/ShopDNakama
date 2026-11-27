<?php

namespace App\Services;

use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;

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

            app(CustomPaymentRecovery::class)->initialize($order, new: true);

            return $order;
        }, 3);
    }

    public function checkoutUrl(Order $order, User $user, MercadoPagoService $mercadoPago): string
    {
        $recovery = app(CustomPaymentRecovery::class);
        $publish = DB::transaction(function () use ($order, $user, $recovery) {
            $quote = CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accept', $quote);
            $this->assertQuoted($quote);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertPayable($locked, $quote, $user);
            $intent = $recovery->initialize($locked);
            if (! $recovery->unchanged($locked, $intent)) {
                throw ValidationException::withMessages(['quote' => 'El pedido requiere revisión antes de continuar el pago.']);
            }
            if ($locked->hasCustomPaymentHold(true)) {
                $recovery->requireFinancialReview($intent, 'checkout_financial_hold');

                return false;
            }
            if (! in_array($intent->state, [CustomPaymentIntent::DRAFT, CustomPaymentIntent::FAILED], true)) {
                return false;
            }
            $intent->transition(CustomPaymentIntent::SENDING);
            $intent->sending_at = now();
            $intent->outcome = 'publication_claimed';
            $intent->save();
            $recovery->event($intent, 'checkout', 'publication_claimed');

            return true;
        }, 3);
        if ($publish) {
            $preference = null;
            try {
                // Never wrap this external side effect in a retryable database transaction.
                $preference = $mercadoPago->createPreference($order->fresh()->load('items'));
                $recovery->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($preference, $recovery) {
                    if ($intent->state === CustomPaymentIntent::READY) {
                        if ($intent->preference_id !== ($preference['id'] ?? null)) {
                            throw new \RuntimeException('Conflicting preference result');
                        }

                        return;
                    }
                    // Retain a recoverable ID even when the URL is missing or malformed.
                    if (is_string($preference['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,255}\z/', $preference['id'])) {
                        $intent->preference_id = $preference['id'];
                    }
                    if (! $recovery->validResult($preference)) {
                        $intent->transition(CustomPaymentIntent::UNCERTAIN);
                        $intent->outcome = 'incomplete_response';
                    } elseif (! $recovery->unchanged($locked, $intent)) {
                        $intent->transition(CustomPaymentIntent::UNCERTAIN);
                        $intent->outcome = 'order_snapshot_changed';
                    } else {
                        $intent->transition(CustomPaymentIntent::READY);
                        $intent->checkout_url = $preference['url'];
                        $intent->outcome = 'published';
                        $locked->forceFill(['mercadopago_preference_id' => $preference['id'], 'mercadopago_checkout_url' => $preference['url']])->save();
                    }
                    $intent->save();
                    $recovery->event($intent, 'checkout', $intent->outcome, ['preference_id' => $intent->preference_id]);
                });
            } catch (\Throwable $exception) {
                // Failure to persist this classification still leaves the committed sending state, never draft.
                $recovery->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($exception, $recovery, $preference) {
                    if ($intent->state === CustomPaymentIntent::READY) {
                        return;
                    }
                    $intent->transition($exception instanceof PreferenceNotSent ? CustomPaymentIntent::FAILED : CustomPaymentIntent::UNCERTAIN);
                    if (is_string($preference['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,255}\z/', $preference['id'])) {
                        $intent->preference_id = $preference['id'];
                    }
                    $intent->outcome = $exception instanceof PreferenceNotSent ? 'not_sent' : 'ambiguous_publication';
                    $intent->save();
                    $recovery->event($intent, 'checkout', $intent->outcome, ['exception_class' => $exception::class,
                        'preference_id' => $intent->preference_id,
                        'http_status' => $exception instanceof MPApiException ? $exception->getApiResponse()->getStatusCode() : null]);
                });
                throw $exception;
            }
        } else {
            $recovery->recover($order, $mercadoPago, 'checkout');
        }

        if ($order->fresh()->hasCustomPaymentHold()) {
            app(PaymentReconciliation::class)->reviewObservedCustomPayments($order);
        }

        return $recovery->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($user) {
            $quote = CustomRequest::findOrFail($locked->custom_request_id);
            Gate::forUser($user)->authorize('accept', $quote);
            $this->assertQuoted($quote);
            $this->assertPayable($locked, $quote, $user);
            if ($locked->hasCustomPaymentHold(true)) {
                throw new CustomPaymentPending;
            }
            if ($intent->state !== CustomPaymentIntent::READY || ! $intent->checkout_url
                || $locked->mercadopago_preference_id !== $intent->preference_id) {
                throw new CustomPaymentPending;
            }

            return $intent->checkout_url;
        });
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
