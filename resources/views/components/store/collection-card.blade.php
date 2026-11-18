@props(['product'])
@php
    $volume = $product->collectionVolume;
    $collection = $volume->collection;
    $url = route('collections.show', ['collection' => $collection, 'tomo' => $volume->volume_number]);
@endphp
<article class="product-card">
    <div class="product-image">
        <a href="{{ $url }}" aria-label="Ver {{ $collection->name }}">
            <x-store.image :path="$collection->image ?: $product->images->first()?->image" :alt="$collection->name"/>
        </a>
        @if($product->on_sale)<span class="product-badge sale">Oferta</span>@endif
    </div>
    <div class="product-info">
        <h3><a href="{{ $url }}">{{ $collection->name }}</a></h3>
        <small class="product-stock">{{ $product->stocked_series_id ? 'Tomos disponibles' : 'Agotado' }}</small>
        <div class="price-row">
            <span>Tomo {{ $volume->volume_number }} · <strong @class(['sale-price' => $product->on_sale])><x-store.price :amount="$product->current_price"/></strong></span>
            <a class="product-cart" href="{{ $url }}" aria-label="Ver tomos de {{ $collection->name }}"><x-store.icon name="arrow" size="19"/></a>
        </div>
    </div>
</article>
