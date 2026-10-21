<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\MercadoPagoService;
use App\Services\ShoppingCart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;
use Throwable;

class CheckoutController extends Controller
{
    public function index(Request $request, ShoppingCart $cart)
    {
        $lines = $cart->lines($request);
        if ($lines->isEmpty()) {
            return to_route('cart.index')->with('cart-warning', 'Agrega productos al carrito para continuar.');
        }

        return view('store.checkout', ['lines' => $lines, 'subtotal' => $lines->sum('subtotal'),
            'shipping' => $this->shippingCost()]);
    }

    private function shippingCost(): int
    {
        return max(0, (int) config('services.mercadopago.shipping_cost'));
    }

    public function store(Request $request, MercadoPagoService $mercadoPago)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:50',
            'shipping_address_line1' => 'required|string|max:255',
            'shipping_city' => 'required|string|max:255',
            'shipping_region' => 'required|string|max:255',
            'delivery_type' => 'required|in:despacho',
        ]);
        $order = DB::transaction(function () use ($request, $data) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
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
                if (! $product || $item->quantity < 1 || $product->stock < $item->quantity) {
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
            unset($data['delivery_type']);
            $order = Order::create(array_merge($data, ['user_id' => $request->user()->id,
                'shipping_country' => 'Chile', 'status' => 'pending', 'payment_status' => 'pending',
                'payment_method' => 'mercadopago', 'subtotal' => $subtotal / 100,
                'shipping_cost' => $this->shippingCost(), 'discount_total' => 0,
                'total' => $subtotal / 100 + $this->shippingCost()]));
            $order->forceFill(['delivery_type' => 'despacho'])->save();
            $order->items()->createMany($lines);

            return $order;
        });
        try {
            $preference = $mercadoPago->createPreference($order->load('items'));
            $order->forceFill(['mercadopago_preference_id' => $preference['id']])->save();
            DB::transaction(function () use ($request, $order) {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $cart = $request->user()->cart()->first();
                foreach ($order->items as $item) {
                    $cart?->items()->where('product_id', $item->product_id)->where('quantity', $item->quantity)->delete();
                }
            });
        } catch (MPApiException $exception) {
            $statusCode = $exception->getApiResponse()->getStatusCode();
            $content = $exception->getApiResponse()->getContent();

            Log::error('Mercado Pago API rechazó la preferencia', [
                'status_code' => $statusCode,
                'response' => $this->sanitizeMercadoPagoResponse($content, $data),
                'order_id' => $order->id ?? null,
            ]);

            return to_route('checkout')->withErrors(['payment' => 'No pudimos iniciar el pago. Tu pedido sigue pendiente; no se ha realizado ningún cobro.']);
        } catch (Throwable $exception) {
            Log::error('Mercado Pago: error al crear preferencia', [
                'exception_class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'order_id' => $order->id ?? null,
            ]);
            return to_route('checkout')->withErrors(['payment' => 'No pudimos iniciar el pago. Tu pedido sigue pendiente; no se ha realizado ningún cobro.']);
        }

        return redirect()->away($preference['url']);
    }

    private function sanitizeMercadoPagoResponse(array $content, array $customerData): array
    {
        // Keep diagnostic fields only; never include headers or echoed request objects.
        $allowed = ['message', 'error', 'code', 'status', 'cause', 'causes', 'description'];
        $sensitive = array_filter(array_merge(array_values($customerData), [
            config('services.mercadopago.access_token'),
            config('services.mercadopago.public_key'),
            config('services.mercadopago.webhook_secret'),
        ]), fn ($value) => is_string($value) && $value !== '');
        usort($sensitive, fn ($left, $right) => strlen($right) <=> strlen($left));

        $sanitize = function (array $values) use (&$sanitize, $allowed, $sensitive): array {
            $result = [];
            foreach ($values as $key => $value) {
                if (! is_int($key) && ! in_array($key, $allowed, true)) {
                    continue;
                }
                if (is_array($value)) {
                    $result[$key] = $sanitize($value);
                } elseif (is_string($value)) {
                    $value = str_ireplace($sensitive, '[REDACTED]', $value);
                    $result[$key] = preg_replace([
                        '/\bBearer\s+\S+/i',
                        '/\b(?:APP_USR|TEST)-[A-Za-z0-9_-]+/i',
                        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
                        '/\b(?:access_token|public_key|webhook_secret|authorization)\b[\s"\x27:=]+[^\s,;}]+/i',
                        '/\+?\d[\d\s().-]{6,}\d/',
                        '/"[^"\r\n]*"|\x27[^\x27\r\n]*\x27/',
                    ], '[REDACTED]', $value);
                } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                    $result[$key] = $value;
                }
            }

            return $result;
        };

        return $sanitize($content);
    }

    public function result(Request $request)
    {
        // Query parameters never change an order or establish ownership.
        return view('store.payment-result', ['result' => $request->route('result')]);
    }
}
