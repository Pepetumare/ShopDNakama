@props(['product'])
@php($available = $product->is_active && $product->category->is_active)
<article class="product-card">
    <div class="product-image">
        @if($available)<a href="{{ route('producto.show', $product) }}" aria-label="Ver {{ $product->name }}">@endif
            <x-store.image :path="$product->images->first()?->image" :alt="$product->images->first()?->alt_text ?: $product->name" />
        @if($available)</a>@endif
        @if($product->badge)
            <span @class(['product-badge', 'sale' => $product->on_sale])>{{ $product->badge }}</span>
        @endif
        <x-store.favorite-button :product="$product"/>
    </div>
    <div class="product-info">
        <h3>@if($available)<a href="{{ route('producto.show', $product) }}">{{ $product->name }}</a>@else{{ $product->name }}@endif</h3>
        <small class="product-stock">{{ $available ? $product->stock_label : 'No disponible' }}</small>
        <div class="price-row">
            <strong @class(['sale-price' => $product->on_sale])><x-store.price :amount="$product->current_price" /></strong>
            @if($product->on_sale)
                <del><x-store.price :amount="$product->price" /></del>
            @endif
            <form method="post" action="{{ route('cart.store') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="quantity" value="1">
                <button class="product-cart" type="submit" @disabled(!$available || $product->stock < 1) aria-label="{{ $available && $product->stock > 0 ? 'Agregar al carrito: '.$product->name : 'Agotado o no disponible: '.$product->name }}" title="{{ $product->stock > 0 ? 'Agregar al carrito' : 'Agotado' }}"><x-store.icon name="cart" size="19"/></button>
            </form>
        </div>
    </div>
</article>
