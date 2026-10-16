<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Shop D. Nakama: anime, cultura japonesa y creaciones personalizadas. Manga, figuras, billeteras, impresión 3D y estampados.">
    <title>@yield('title', 'Shop D. Nakama | Anime y cultura japonesa')</title>
    @vite(['resources/css/store.css', 'resources/js/store.js'])
</head>
<body style="--shop-reference:url('{{ asset('images/shop-reference.png') }}')">
    <a class="skip-link" href="#contenido">Saltar   al contenido</a>
    <x-store.header />
    <main id="contenido">@yield('content')</main>
    <x-store.footer />
</body>
</html>
