@extends('layouts.store')
@section('title', $product->name.' | Shop D. Nakama')
@section('content')
<div class="shop-container product-page" id="productos">
    <nav aria-label="Migas de pan" class="product-breadcrumbs">
        <ol>
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li><a href="{{ route('catalogo') }}">Catálogo</a></li>
            <li><a href="{{ route('catalogo', ['categoria' => $product->category->slug]) }}">{{ $category }}</a></li>
            <li aria-current="page">{{ $product->name }}</li>
        </ol>
    </nav>
    <div class="product-detail-layout">
        <x-store.product-gallery :images="$product->images" :name="$product->name" />
        <section class="product-summary" aria-labelledby="product-title" x-data="{ quantity: 1 }">
            <a class="product-category" href="{{ route('catalogo', ['categoria' => $product->category->slug]) }}">{{ $category }}</a>
            @if($product->badge)<span @class(['product-badge', 'sale' => $product->badge === 'Oferta'])>{{ $product->badge }}</span>@endif
            <h1 id="product-title">{{ $product->name }}</h1>
            <p class="product-detail-price"><strong @class(['sale-price' => $product->on_sale])><x-store.price :amount="$product->current_price" /></strong> @if($product->on_sale)<del><x-store.price :amount="$product->price" /></del>@endif <small>CLP</small></p>
            <p class="product-availability"><span @class(['availability-dot', 'unavailable' => !($product->stock > 0)])></span>{{ $product->stock_label }} <small>· {{ $product->stock }} unidades disponibles</small></p>
            <p class="product-sku">SKU: {{ $product->sku ?: 'Por confirmar' }}</p>
            <p>{{ $product->short_description ?: $product->description }}</p>
            <div class="product-quantity">
                <label for="product-quantity">Cantidad</label>
                <div class="quantity-control">
                    <button type="button" @click="quantity = Math.max(1, quantity - 1)" :disabled="quantity <= 1" @disabled(!($product->stock > 0)) aria-label="Disminuir cantidad">−</button>
                    <input form="add-to-cart" name="quantity" id="product-quantity" type="number" min="1" max="{{ max(1, $product->stock) }}" step="1" value="1" x-model.number="quantity" @change="quantity = Math.min({{ max(1, $product->stock) }}, Math.max(1, Math.floor(Number(quantity) || 1)))" @disabled(!($product->stock > 0))>
                    <button type="button" @click="quantity = Math.min({{ max(1, $product->stock) }}, quantity + 1)" :disabled="quantity >= {{ max(1, $product->stock) }}" @disabled(!($product->stock > 0)) aria-label="Aumentar cantidad">+</button>
                </div>
            </div>
            <div class="product-detail-actions">
                <form id="add-to-cart" method="post" action="{{ route('cart.store') }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <button type="submit" class="shop-button red" @disabled($product->stock < 1)><x-store.icon name="cart"/> {{ $product->stock > 0 ? 'Agregar al carrito' : 'Agotado' }}</button>
                </form>
                <x-store.favorite-button :product="$product" :detail="true"/>
            </div>
            <div class="product-attention"><x-store.icon name="user" size="20"/> Atención nakama · Estamos para ayudarte.</div>
        </section>
    </div>
    <section class="product-information" aria-label="Información del producto">
        <details open><summary>Descripción</summary><div class="product-long-description"><p>{{ $product->description ?: $product->short_description ?: 'Próximamente más información de este producto.' }}</p></div></details>
        <details><summary>Características</summary><div><dl><div><dt>Categoría</dt><dd>{{ $category }}</dd></div>@if($product->product_type)<div><dt>Tipo de producto</dt><dd>{{ $product->product_type }}</dd></div>@endif</dl></div></details>
        <details><summary>Envíos</summary><div><p>Tu pedido llega donde estés. La cobertura, el costo y el plazo de despacho se confirmarán cuando la tienda habilite las compras.</p></div></details>
        <details><summary>Cambios y devoluciones</summary><div><p>Las condiciones de cambios y devoluciones se informarán antes de habilitar las compras.</p></div></details>
    </section>
    <section class="product-related" aria-label="Productos relacionados">
        <x-store.section-heading icon="star">También te puede gustar</x-store.section-heading>
        <div class="product-grid related-grid">@foreach($related as $item)<x-store.product-card :product="$item"/>@endforeach</div>
    </section>
</div>
@endsection
