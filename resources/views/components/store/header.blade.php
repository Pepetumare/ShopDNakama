@php($navigationCategories = \App\Models\Category::storefront()->orderBy('sort_order')->orderBy('name')->get())
<header x-data="{ open: false }">
    <div class="utility-bar"><div class="shop-container d-flex justify-content-between align-items-center">
        <div class="utility-promises"><span><x-store.icon name="truck" size="20" /> Envíos a todo el país</span><span><x-store.icon name="shield" size="20" /> Pagos seguros</span><span><x-store.icon name="star" size="20" /> Productos originales</span></div>
        <div class="utility-actions"><a href="{{ route('home') }}#nakama" class="community-link">Únete a la tripulación</a>@auth @if(auth()->user()->isAdmin()) <a class="header-housekeeping" href="{{ route('housekeeping.dashboard') }}">Housekeeping</a> @endif @endauth</div>
    </div></div>
    <div class="shop-container brand-bar">
        <a href="{{ route('home') }}" class="brand" aria-label="Shop D. Nakama, inicio"><x-store.art :x="30" :y="42" :w="113" :h="113" label="Logo Shop D. Nakama" class="brand-logo"/><span><strong>SHOP D. <em>NAKAMA</em></strong><small>Anime · Manga · Figuras · Merch · Cultura Japonesa</small></span></a>
        <form action="{{ route('catalogo') }}" method="get" class="search-box"><label for="search" class="visually-hidden">Buscar productos</label><input id="search" name="q" value="{{ request('q') }}" placeholder="¿Qué estás buscando nakama?" type="search"><button aria-label="Buscar"><x-store.icon name="search" /></button></form>
        <div class="header-actions"><a href="{{ auth()->check() ? route('account.index') : route('login') }}"><x-store.icon name="user" size="28"/><span>Mi cuenta</span></a><a href="{{ route('account.favorites') }}" aria-label="Favoritos: {{ $favoriteCount }}"><x-store.icon name="heart" size="28"/><span>Favoritos</span><b class="favorites-count" data-favorites-count="{{ $favoriteCount }}">{{ $favoriteCount }}</b></a><a href="{{ route('cart.index') }}" aria-label="Carrito: {{ $cartCount }}"><x-store.icon name="cart" size="28"/><span>Carrito</span><b data-cart-count="{{ $cartCount }}">{{ $cartCount }}</b></a></div>
    </div>
    <nav class="main-nav" aria-label="Navegación principal"><div class="shop-container"><button class="mobile-menu" @click="open = !open" :aria-expanded="open" aria-controls="store-navigation"><x-store.icon name="menu"/> Menú</button><div id="store-navigation" class="nav-links" :class="{ 'is-open': open }">
    @foreach(array_merge(['Inicio' => route('home'), 'Catálogo' => route('catalogo')], $navigationCategories->mapWithKeys(fn ($category) => [$category->name => route('catalogo', ['categoria' => $category->slug])])->all(), ['Ofertas' => route('catalogo', ['categoria' => 'ofertas']), 'Contacto' => route('home').'#contacto']) as $label => $url)
        <a href="{{ $url }}" @class(['active' => ($loop->first && request()->routeIs('home')) || ($label === 'Catálogo' && request()->routeIs('catalogo'))]) @click="open = false">{{ $label === 'Inicio' ? '⌂ ' : ($label === 'Ofertas' ? '● ' : '') }}{{ $label }}</a>
    @endforeach
    </div></div></nav>
</header>
