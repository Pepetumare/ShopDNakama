@extends('layouts.store')
@section('title', 'Catálogo | Shop D. Nakama')
@section('content')
<section class="catalog-hero">
    <div class="hero-art" aria-hidden="true"></div>
    <div class="shop-container catalog-intro">
        <p class="eyebrow">SHOP D. NAKAMA · 仲間</p>
        <h1>CATÁLOGO</h1>
        <h2>Anime, cultura japonesa y mucho más</h2>
        <p>Descubre productos para verdaderos nakamas: manga, figuras, billeteras, merchandising, accesorios y cultura japonesa.</p>
    </div>
</section>
<section class="shop-container catalog-section" id="productos" x-data="{ filtersOpen: false }" @keydown.escape.window="if (filtersOpen) { filtersOpen = false; $refs.filterToggle.focus() }">
    <nav class="product-tabs catalog-tabs" aria-label="Categorías rápidas">
        @foreach(['' => 'Todos'] + $categories as $slug => $label)
            <a href="{{ route('catalogo', array_merge(request()->except('categoria', 'page'), ['categoria' => $slug])) }}" @class(['selected' => request('categoria', '') === $slug]) @if(request('categoria', '') === $slug) aria-current="true" @endif>{{ $slug === 'merch' ? 'Merch' : $label }}</a>
        @endforeach
    </nav>
    <button type="button" class="catalog-filter-toggle shop-button green" x-ref="filterToggle" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="catalog-filters"><x-store.icon name="menu" size="18"/> Filtros</button>
    <div class="catalog-layout">
        <button type="button" class="catalog-filter-backdrop" :class="{ 'is-open': filtersOpen }" @click="filtersOpen = false" aria-label="Cerrar filtros" tabindex="-1"></button>
        <aside id="catalog-filters" class="catalog-filters" :class="{ 'is-open': filtersOpen }" aria-label="Filtros del catálogo">
            <button type="button" class="catalog-filter-close" @click="filtersOpen = false; $refs.filterToggle.focus()">Cerrar filtros ×</button>
            <x-store.catalog-filters :categories="$categories" :prices="$prices" />
        </aside>
        <div class="catalog-results">
            <div class="catalog-list-heading">
                <p role="status">Mostrando {{ $products->count() }} productos <small>de {{ $products->total() }}</small></p>
                <form method="get" action="{{ route('catalogo') }}">
                    @foreach(request()->only('categoria', 'precio', 'disponibilidad', 'q') as $key => $value)
                        @if(is_string($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                    @endforeach
                    <label for="catalog-order">Ordenar por</label>
                    <select id="catalog-order" name="orden" onchange="this.form.submit()">
                        @foreach(['recientes' => 'Más recientes', 'precio-menor' => 'Precio menor', 'precio-mayor' => 'Precio mayor', 'nombre' => 'Nombre'] as $key => $label)
                            <option value="{{ $key }}" @selected(request('orden', 'recientes') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit">Ordenar</button></noscript>
                </form>
            </div>
            @if(request('q'))<p>Resultados para «{{ request('q') }}»</p>@endif
            <div class="product-grid catalog-grid">
                @forelse($products as $product)
                    <x-store.product-card :product="$product" />
                @empty
                    <p class="empty-state">No encontramos productos con esos filtros. <a href="{{ route('catalogo') }}">Limpiar filtros</a></p>
                @endforelse
            </div>
            @if($products->hasPages())
                <nav class="catalog-pagination" aria-label="Paginación del catálogo">
                    @if(!$products->onFirstPage())<a href="{{ $products->previousPageUrl() }}" rel="prev" aria-label="Página anterior">←</a>@endif
                    @for($page = 1; $page <= $products->lastPage(); $page++)
                        <a href="{{ $products->url($page) }}" @if($page === $products->currentPage()) aria-current="page" @endif aria-label="Página {{ $page }}">{{ $page }}</a>
                    @endfor
                    @if($products->hasMorePages())<a href="{{ $products->nextPageUrl() }}" rel="next" aria-label="Página siguiente">→</a>@endif
                </nav>
            @endif

        </div>
    </div>
</section>
@endsection
