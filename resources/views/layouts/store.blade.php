<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Shop D. Nakama: anime y cultura japonesa. Manga, figuras, billeteras, merchandising y accesorios para verdaderos nakamas.">
    <title>@yield('title', 'Shop D. Nakama | Anime y cultura japonesa')</title>
    @vite(['resources/css/store.css', 'resources/js/store.js'])
</head>
<body style="--shop-reference:url('{{ asset('images/shop-reference.png') }}')">
    <a class="skip-link" href="#contenido">Saltar   al contenido</a>
    <x-store.header />
    <main id="contenido">
        @if(session('favorite-status'))<div class="shop-container favorite-feedback"><p class="account-notice" role="status">{{ session('favorite-status') }}</p></div>@endif
        @if(session('cart-status'))<div class="shop-container favorite-feedback"><p class="account-notice" role="status">{{ session('cart-status') }}</p></div>@endif
        @if(session('cart-warning'))<div class="shop-container favorite-feedback"><p class="account-notice" role="alert">{{ session('cart-warning') }}</p></div>@endif
        @if($errors->any())<div class="shop-container favorite-feedback"><div class="account-notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>@endif
        @yield('content')
    </main>
    <x-store.footer />
</body>
</html>
