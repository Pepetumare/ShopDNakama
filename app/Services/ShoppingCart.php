<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShoppingCart
{
    private function cart(Request $request): Cart
    {
        return $request->user()->cart()->firstOrCreate();
    }

    private function quantities(Request $request): array
    {
        return $request->user()
            ? $this->cart($request)->items()->pluck('quantity', 'product_id')->all()
            : $request->session()->get('cart', []);
    }

    private function save(Request $request, int $id, int $quantity): void
    {
        if ($request->user()) {
            $items = $this->cart($request)->items();
            if ($quantity > 0) {
                $items->updateOrCreate(['product_id' => $id], ['quantity' => $quantity]);
            } else {
                $items->where('product_id', $id)->delete();
            }
        } else {
            $cart = $request->session()->get('cart', []);
            if ($quantity > 0) {
                $cart[$id] = $quantity;
            } else {
                unset($cart[$id]);
            }
            $request->session()->put('cart', $cart);
        }
    }

    public function change(Request $request, int $id, int $quantity, bool $add = false): void
    {
        DB::transaction(function () use ($request, $id, $quantity, $add) {
            // Serialize changes to the same user's cart, including repeated adds.
            if ($request->user()) {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            }
            $product = Product::visible()->whereKey($id)->lockForUpdate()->firstOrFail();
            $current = (int) ($this->quantities($request)[$id] ?? 0);
            abort_if(!$add && $current === 0, 404);
            $desired = $add ? $current + $quantity : $quantity;
            if ($desired < 1 || $desired > $product->stock) {
                throw ValidationException::withMessages(['quantity' => $product->stock === 0
                    ? 'Agotado. Este producto ya no tiene stock.'
                    : 'Stock insuficiente. Disponibles: '.$product->stock.' unidades.']);
            }
            $this->save($request, $id, $desired);
        });
    }

    public function remove(Request $request, int $id): void
    {
        // The product ID is always resolved within the current owner's cart.
        abort_unless(array_key_exists($id, $this->quantities($request)), 404);
        $this->save($request, $id, 0);
    }

    public function lines(Request $request): Collection
    {
        $quantities = $this->quantities($request);
        $products = Product::visible()->with('images')->whereKey(array_keys($quantities))->get()->keyBy('id');
        $lines = collect();
        foreach ($quantities as $id => $quantity) {
            $product = $products->get($id);
            $valid = $product ? min((int) $quantity, $product->stock) : 0;
            if ($valid !== (int) $quantity) {
                $this->save($request, (int) $id, $valid);
                $request->session()->flash('cart-warning', 'Stock insuficiente o producto no disponible. Ajustamos tu carrito al stock actual.');
            }
            if ($valid > 0) {
                // Integer cents keep totals exact; all prices come from the database.
                $cents = (int) round((float) $product->current_price * 100);
                $lines->push(['product' => $product, 'quantity' => $valid, 'price' => $cents / 100, 'subtotal' => $cents * $valid / 100]);
            }
        }

        return $lines;
    }

    public function merge(Request $request): void
    {
        $guest = $request->session()->get('cart', []);
        if (!$guest) {
            return;
        }
        DB::transaction(function () use ($request, $guest) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            foreach ($guest as $id => $quantity) {
                $product = Product::visible()->whereKey($id)->lockForUpdate()->first();
                $current = (int) ($this->quantities($request)[$id] ?? 0);
                $desired = $current + (int) $quantity;
                $valid = $product ? min($desired, $product->stock) : 0;
                $this->save($request, (int) $id, $valid);
                if ($valid !== $desired) {
                    $request->session()->flash('cart-warning', 'Stock insuficiente o producto no disponible. Fusionamos tu carrito respetando el stock actual.');
                }
            }
        });
        $request->session()->forget('cart');
    }
}
