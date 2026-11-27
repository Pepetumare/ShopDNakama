<?php

namespace App\Http\Controllers;

use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use App\Services\MercadoPagoService;
use App\Services\PreferenceNotSent;
use App\Services\ShoppingCart;
use App\Services\StockReservations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CheckoutController extends Controller
{
    public function index(Request $request, ShoppingCart $cart, StockReservations $reservations)
    {
        [$attempt, $lines] = DB::transaction(function () use ($request, $cart, $reservations) {
            $user = $request->user();
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $attempt = CheckoutAttempt::where('user_id', $user->id)->latest('id')->first();
            $order = $attempt?->order;
            if ($order && $this->canResume($attempt, $order, $reservations)) {
                return [$attempt, null];
            }

            $lines = $cart->lines($request);
            if ($lines->isEmpty()) {
                return [null, $lines];
            }
            $hash = $this->cartHash($lines->map(fn ($line) => [
                'product_id' => $line['product']->id, 'quantity' => $line['quantity'], 'unit_price' => $line['price'],
            ])->all());
            if ($attempt?->state !== CheckoutAttempt::DRAFT || $attempt->cart_hash !== $hash) {
                if ($attempt?->state === CheckoutAttempt::DRAFT) {
                    $attempt->update(['state' => CheckoutAttempt::FAILED]);
                }
                $attempt = CheckoutAttempt::create(['user_id' => $user->id, 'token' => bin2hex(random_bytes(32)),
                    'state' => CheckoutAttempt::DRAFT, 'cart_hash' => $hash]);
            }

            return [$attempt, $lines];
        }, 3);
        if ($lines === null) {
            return view('store.checkout', ['attempt' => $attempt, 'lines' => null]);
        }
        if ($lines->isEmpty()) {
            return to_route('cart.index')->with('cart-warning', 'Agrega productos al carrito para continuar.');
        }

        return view('store.checkout', ['attempt' => $attempt, 'lines' => $lines, 'subtotal' => $lines->sum('subtotal'),
            'shipping' => $this->shippingCost()]);
    }

    private function shippingCost(): int
    {
        return max(0, (int) config('services.mercadopago.shipping_cost'));
    }

    private function cartHash(array $lines): string
    {
        $snapshot = [];
        foreach ($lines as $line) {
            $snapshot[$line['product_id']] = [(int) $line['quantity'], (int) round((float) $line['unit_price'] * 100)];
        }
        ksort($snapshot);

        return hash('sha256', json_encode([$this->shippingCost(), $snapshot], JSON_THROW_ON_ERROR));
    }

    private function canResume(CheckoutAttempt $attempt, Order $order, StockReservations $reservations): bool
    {
        $rows = $reservations->rows($order);

        return $attempt->state !== CheckoutAttempt::NOT_SENT && $rows->isNotEmpty() && ! $rows->contains(fn ($row) => $row->status === 'released' || $row->status === 'consumed')
            && ($attempt->state !== CheckoutAttempt::READY
                || (filled($order->mercadopago_checkout_url) && $this->isPayable($order) && $rows->every($reservations->isCurrent(...))));
    }

    private function isPayable(Order $order): bool
    {
        return in_array($order->payment_status, ['pending', 'pendiente', 'rejected', 'rechazado', 'cancelled'], true)
            && in_array($order->status, ['pending', 'pendiente'], true);
    }

    public function store(Request $request, MercadoPagoService $mercadoPago, StockReservations $reservations)
    {
        $identity = $request->validate(['attempt' => 'required|string|regex:/\A[a-f0-9]{64}\z/', 'resume' => 'sometimes|accepted']);
        $data = isset($identity['resume']) ? null : $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:50',
            'shipping_address_line1' => 'required|string|max:255',
            'shipping_city' => 'required|string|max:255',
            'shipping_region' => 'required|string|max:255',
            'delivery_type' => 'required|in:despacho',
        ]);
        [$attempt, $order, $operation] = DB::transaction(function () use ($request, $identity, $data, $reservations) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $attempt = CheckoutAttempt::where('user_id', $request->user()->id)->where('token', $identity['attempt'])->lockForUpdate()->firstOrFail();
            $deliveryHash = $data === null ? null : hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($attempt->order_id) {
                $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
                if ($deliveryHash !== null && $attempt->delivery_hash !== $deliveryHash) {
                    throw ValidationException::withMessages(['payment' => 'Los datos de entrega cambiaron. El pedido anterior conserva sus datos; revisa ese intento en checkout.']);
                }
                if (! $this->canResume($attempt, $order, $reservations)) {
                    return [$attempt, $order, 'closed'];
                }
                if ($attempt->state === CheckoutAttempt::READY) {
                    return [$attempt, $order, 'reuse'];
                }
                if (($attempt->state === CheckoutAttempt::SENDING && $attempt->updated_at->gt(now()->subMinutes(2)))
                    || $attempt->lookup_at?->gt(now()->subSeconds(30))) {
                    return [$attempt, $order, 'wait'];
                }
                $attempt->update(['state' => CheckoutAttempt::UNCERTAIN, 'lookup_at' => now()]);

                return [$attempt, $order, 'lookup'];
            }
            if ($attempt->state !== CheckoutAttempt::DRAFT || $data === null) {
                throw ValidationException::withMessages(['attempt' => 'Vuelve al checkout para iniciar un intento vigente.']);
            }
            $cart = $request->user()->cart()->first();
            $items = $cart?->items()->orderBy('product_id')->get();
            if (! $items || $items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Tu carrito está vacío.']);
            }
            $products = Product::visible()->whereKey($items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $subtotal = 0;
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product || $item->quantity < 1 || $reservations->available($product, lock: true) < $item->quantity) {
                    throw ValidationException::withMessages(['cart' => 'Stock insuficiente o producto no disponible. Revisa tu carrito.']);
                }
                $cents = (int) round((float) $product->current_price * 100);
                $subtotal += $cents * $item->quantity;
                $lines[] = ['product_id' => $product->id, 'product_name' => $product->name,
                    'sku' => $product->sku, 'quantity' => $item->quantity,
                    'unit_price' => $cents / 100, 'line_total' => $cents * $item->quantity / 100];
            }
            if ($subtotal <= 0) {
                throw ValidationException::withMessages(['cart' => 'El pedido debe tener un monto mayor a cero.']);
            }
            if ($attempt->cart_hash !== $this->cartHash($lines)) {
                throw ValidationException::withMessages(['cart' => 'El carrito o el precio cambió. Revisa el nuevo total en checkout.']);
            }
            unset($data['delivery_type']);
            $order = Order::create(array_merge($data, ['user_id' => $request->user()->id,
                'shipping_country' => 'Chile', 'status' => 'pending', 'payment_status' => 'pending',
                'payment_method' => 'mercadopago', 'subtotal' => $subtotal / 100,
                'shipping_cost' => $this->shippingCost(), 'discount_total' => 0,
                'total' => $subtotal / 100 + $this->shippingCost()]));
            $order->forceFill(['delivery_type' => 'despacho'])->save();
            $order->items()->createMany($lines);
            $reservations->reserve($order, $lines);
            $attempt->update(['order_id' => $order->id, 'delivery_hash' => $deliveryHash, 'state' => CheckoutAttempt::SENDING]);

            return [$attempt, $order, 'create'];
        }, 3);
        if ($operation === 'closed') {
            return to_route('account.orders.show', $order)->withErrors(['payment' => 'Este intento ya no admite otro pago.']);
        }
        if ($operation === 'reuse') {
            return redirect()->away($order->mercadopago_checkout_url, 303, ['Cache-Control' => 'no-store']);
        }
        if ($operation === 'wait') {
            return $this->pendingResponse();
        }

        try {
            $preference = $operation === 'create'
                ? $mercadoPago->createPreference($order->load('items'))
                : $mercadoPago->findPreference($order);
            if (! $preference || ! is_string($preference['id'] ?? null) || blank($preference['id'])
                || ! is_string($preference['url'] ?? null) || ! filter_var($preference['url'], FILTER_VALIDATE_URL)
                || parse_url($preference['url'], PHP_URL_SCHEME) !== 'https') {
                return $this->markUncertain($attempt);
            }

            $url = DB::transaction(function () use ($request, $attempt, $order, $preference, $reservations) {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $attempt = CheckoutAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (in_array($attempt->state, [CheckoutAttempt::FAILED, CheckoutAttempt::NOT_SENT], true)
                    || ($locked->mercadopago_preference_id && $locked->mercadopago_preference_id !== $preference['id'])) {
                    return null;
                }
                if ($attempt->state === CheckoutAttempt::READY) {
                    return $this->canResume($attempt, $locked, $reservations) ? $locked->mercadopago_checkout_url : null;
                }

                $rows = $reservations->rows($locked, lock: true);
                $canPay = $this->isPayable($locked) && $rows->isNotEmpty() && $rows->every($reservations->isCurrent(...));
                // Keep the provider ID even if the reservation expired so the webhook can reconcile a late payment.
                $locked->forceFill(['mercadopago_preference_id' => $preference['id'],
                    'mercadopago_checkout_url' => $canPay ? $preference['url'] : null])->save();
                $attempt->update(['state' => CheckoutAttempt::READY]);
                if (! $canPay) {
                    return null;
                }

                $cart = $request->user()->cart()->first();
                $current = $cart?->items()->orderBy('product_id')->pluck('quantity', 'product_id')->all();
                $purchased = $order->items()->orderBy('product_id')->pluck('quantity', 'product_id')->all();
                if ($current === $purchased) {
                    $cart->items()->delete();
                }

                return $locked->mercadopago_checkout_url;
            }, 3);

            return $url ? redirect()->away($url, 303, ['Cache-Control' => 'no-store'])
                : to_route('account.orders.show', $order)->withErrors(['payment' => 'La reserva ya no está vigente. Revisa el estado del pedido.']);
        } catch (PreferenceNotSent $exception) {
            if ($operation !== 'create') {
                return $this->pendingResponse();
            }
            DB::transaction(function () use ($attempt, $order, $reservations) {
                $failed = CheckoutAttempt::whereKey($attempt->id)->where('state', CheckoutAttempt::SENDING)
                    ->update(['state' => CheckoutAttempt::NOT_SENT]);
                if ($failed) {
                    $reservations->releaseUnpublished($order);
                }
            }, 3);

            return to_route('checkout')->withErrors(['payment' => 'No se inició el pago. Puedes volver a intentar desde el checkout.']);
        } catch (Throwable $exception) {
            Log::warning('Resultado de preferencia de Mercado Pago incierto', [
                'order_id' => $order->id, 'exception_class' => $exception::class,
            ]);

            return $this->markUncertain($attempt);
        }
    }

    private function markUncertain(CheckoutAttempt $attempt)
    {
        CheckoutAttempt::whereKey($attempt->id)->where('state', CheckoutAttempt::SENDING)
            ->update(['state' => CheckoutAttempt::UNCERTAIN]);

        return $this->pendingResponse();
    }

    private function pendingResponse()
    {
        return to_route('checkout')->withErrors(['payment' => 'Estamos verificando el inicio del pago. Usa Recuperar pago para consultar el mismo intento.']);
    }

    public function result(Request $request)
    {
        // Query parameters never change an order or establish ownership.
        return view('store.payment-result', ['result' => $request->route('result')]);
    }
}
