<?php

namespace App\Http\Controllers;

use App\Services\ShoppingCart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request, ShoppingCart $cart)
    {
        $lines = $cart->lines($request);

        return view('store.cart', ['lines' => $lines, 'total' => $lines->sum('subtotal')]);
    }

    public function store(Request $request, ShoppingCart $cart)
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:2147483647']]);
        $cart->change($request, $data['product_id'], $data['quantity'], true);

        return back()->with('cart-status', 'Producto agregado');
    }

    public function update(Request $request, ShoppingCart $cart, int $product)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:2147483647']]);
        $cart->change($request, $product, $data['quantity']);

        return back()->with('cart-status', 'Cantidad actualizada');
    }

    public function destroy(Request $request, ShoppingCart $cart, int $product)
    {
        $cart->remove($request, $product);

        return back()->with('cart-status', 'Producto eliminado');
    }
}
