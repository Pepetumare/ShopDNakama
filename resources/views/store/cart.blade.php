@extends('layouts.store')
@section('title', 'Mi carrito | Shop D. Nakama')
@section('content')
<div class="shop-container cart-page">
    <h1>Mi carrito</h1>
    @if($lines->isEmpty())
        <div class="account-empty"><x-store.icon name="cart" size="38"/><h2>Tu carrito está vacío</h2><p>Encuentra tu próximo tesoro nakama.</p><a class="shop-button green" href="{{ route('catalogo') }}">Explorar catálogo</a></div>
    @else
        <div class="cart-layout">
            <div class="cart-lines">
                @foreach($lines as $line)
                    @php($product = $line['product'])
                    <article class="cart-line">
                        <a href="{{ route('producto.show', $product) }}" class="cart-image"><x-store.image :path="$product->images->first()?->image" :alt="$product->name"/></a>
                        <div class="cart-description">
                            <h2><a href="{{ route('producto.show', $product) }}">{{ $product->name }}</a></h2>
                            <p>Precio: <x-store.price :amount="$line['price']"/></p>
                            <form method="post" action="{{ route('cart.update', $product->id) }}" x-data="{ quantity: {{ $line['quantity'] }} }" class="cart-quantity-form">
                                @csrf @method('PATCH')
                                <label for="quantity-{{ $product->id }}">Cantidad</label>
                                <div class="quantity-control">
                                    <button type="button" @click="quantity = Math.max(1, Number(quantity) - 1)" :disabled="quantity <= 1" aria-label="Disminuir cantidad de {{ $product->name }}">−</button>
                                    <input id="quantity-{{ $product->id }}" type="number" name="quantity" min="1" max="{{ $product->stock }}" step="1" required value="{{ $line['quantity'] }}" x-model.number="quantity" @change="quantity = Math.min({{ $product->stock }}, Math.max(1, Math.floor(Number(quantity) || 1)))">
                                    <button type="button" @click="quantity = Math.min({{ $product->stock }}, Number(quantity) + 1)" :disabled="quantity >= {{ $product->stock }}" aria-label="Aumentar cantidad de {{ $product->name }}">+</button>
                                </div>
                                <button class="shop-button green" type="submit">Actualizar</button>
                            </form>
                            <p>Subtotal: <strong><x-store.price :amount="$line['subtotal']"/></strong></p>
                            <form method="post" action="{{ route('cart.destroy', $product->id) }}">@csrf @method('DELETE')<button class="cart-remove" type="submit" aria-label="Eliminar {{ $product->name }}">Eliminar</button></form>
                        </div>
                    </article>
                @endforeach
            </div>
            <aside class="cart-totals" aria-label="Resumen del carrito">
                <h2>Resumen de tu pedido</h2>
                <dl><div><dt>Subtotal</dt><dd><x-store.price :amount="$total"/></dd></div><div><dt>Despacho</dt><dd>Pendiente de calcular</dd></div><div class="cart-total"><dt>Total</dt><dd><x-store.price :amount="$total"/></dd></div></dl>
                <p>El total actual no incluye despacho.</p>
                <a class="shop-button red" href="{{ route('checkout') }}">Continuar compra <x-store.icon name="arrow" size="18"/></a>
            </aside>
        </div>
    @endif
</div>
@endsection
