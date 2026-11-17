@extends('layouts.store', ['metaDescription' => $collection->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($collection->description ?? ''), 155)])
@section('title', ($collection->seo_title ?: $collection->name).' | Shop D. Nakama')
@section('content')
<div class="shop-container product-page" x-data="mangaCollection(@js($details), {{ $selected }})">
    <nav aria-label="Migas de pan" class="product-breadcrumbs">
        <ol>
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li><a href="{{ route('catalogo') }}">Catálogo</a></li>
            <li><a href="{{ route('catalogo', ['categoria' => $collection->category->slug]) }}">{{ $collection->category->name }}</a></li>
            <li aria-current="page">{{ $collection->name }}</li>
        </ol>
    </nav>
    <div class="product-detail-layout">
        <div class="product-gallery" aria-label="Galería del tomo">
            <div class="product-gallery-main">
                <img class="store-image" src="{{ $initial['images'][0]['url'] }}" alt="{{ $initial['images'][0]['alt'] }}"
                     :src="current.images[activeImage].url" :alt="current.images[activeImage].alt">
            </div>
            <div class="product-thumbnails" role="group" aria-label="Vistas del tomo" x-cloak>
                <template x-for="(image, index) in current.images" :key="index">
                    <button type="button" @click="activeImage = index" :aria-pressed="activeImage === index"
                            :aria-label="'Mostrar imagen ' + (index + 1) + ' de ' + current.name">
                        <img class="store-image" :src="image.url" :alt="image.alt" loading="lazy" decoding="async">
                    </button>
                </template>
            </div>
        </div>
        <section class="product-summary" aria-labelledby="product-title">
            <h1 id="product-title">{{ $collection->name }}</h1>
            @if($collection->description)<p>{{ $collection->description }}</p>@endif
            <fieldset class="manga-volumes">
                <legend>Elige un tomo</legend>
                <div class="manga-volume-grid">
                    @foreach($volumes as $volume)
                        @php($available = $volume->product->stock > 0)
                        <button type="button" class="manga-volume" @disabled(!$available)
                            aria-pressed="{{ $available && $selected === $volume->volume_number ? 'true' : 'false' }}"
                            aria-label="Tomo {{ $volume->volume_number }}{{ $available ? '' : ', agotado' }}"
                            :aria-pressed="current.stock > 0 && selected === {{ $volume->volume_number }}"
                            @click="selectVolume({{ $volume->volume_number }})">{{ $volume->volume_number }}</button>
                    @endforeach
                </div>
            </fieldset>
            <div aria-live="polite" aria-atomic="true">
                <p class="product-sku" x-text="current.name">{{ $initial['name'] }}</p>
                <p class="product-detail-price">
                    <strong x-text="current.price" :class="{ 'sale-price': current.previousPrice }">{{ $initial['price'] }}</strong>
                    <del x-show="current.previousPrice" x-text="current.previousPrice" @if(!$initial['previousPrice']) x-cloak @endif>{{ $initial['previousPrice'] }}</del>
                    <small>CLP</small>
                </p>
                <p class="product-availability">
                    <span @class(['availability-dot', 'unavailable' => $initial['stock'] < 1]) :class="{ unavailable: current.stock < 1 }"></span>
                    <span x-text="current.availability">{{ $initial['availability'] }}</span>
                    <small x-text="'· ' + current.stock + ' unidades disponibles'">· {{ $initial['stock'] }} unidades disponibles</small>
                </p>
                <p class="product-sku">SKU: <span x-text="current.sku">{{ $initial['sku'] }}</span></p>
            </div>
            <form id="manga-purchase" method="post" action="{{ route('cart.store') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $initial['id'] }}" :value="current.id">
                <div class="product-quantity">
                    <label for="volume-quantity">Cantidad</label>
                    <div class="quantity-control">
                        <button type="button" x-cloak @click="quantity = Math.max(1, quantity - 1)" :disabled="quantity <= 1 || !current.stock" aria-label="Disminuir cantidad">−</button>
                        <input type="number" id="volume-quantity" name="quantity" min="1" max="{{ max(1, $initial['stock']) }}"
                               :max="Math.max(1, current.stock)" step="1" value="1" required
                               x-model.number="quantity" @change="clampQuantity()" @disabled(!$initial['stock']) :disabled="!current.stock">
                        <button type="button" x-cloak @click="quantity = Math.min(current.stock, Number(quantity) + 1)" :disabled="quantity >= current.stock" aria-label="Aumentar cantidad">+</button>
                    </div>
                </div>
            </form>
            <div class="product-detail-actions">
                <button form="manga-purchase" class="shop-button red" type="submit" @disabled(!$initial['stock']) :disabled="!current.stock">
                    <x-store.icon name="cart"/> <span x-text="current.stock > 0 ? 'Agregar al carrito' : 'Agotado'">{{ $initial['stock'] > 0 ? 'Agregar al carrito' : 'Agotado' }}</span>
                </button>
                @foreach($volumes as $volume)
                    <div x-show="selected === {{ $volume->volume_number }}" @if($selected !== $volume->volume_number) x-cloak @endif>
                        <x-store.favorite-button :product="$volume->product" :detail="true"/>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
    <section class="product-information" x-show="current.description" aria-label="Información del tomo" @if(!$initial['description']) x-cloak @endif>
        <details open>
            <summary>Descripción del tomo</summary>
            <div class="product-long-description"><p x-text="current.description">{{ $initial['description'] }}</p></div>
        </details>
    </section>
</div>
@endsection
