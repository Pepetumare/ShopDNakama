<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Housekeeping') | Shop D. Nakama</title>
    @vite(['resources/css/housekeeping.css', 'resources/js/store.js'])
</head>
<body class="hk-body" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
@php($modules = $modules ?? \App\Http\Controllers\HousekeepingController::MODULES)
<a class="hk-skip" href="#hk-content">Saltar al contenido</a>
<div class="hk-shell">
    <button class="hk-backdrop" type="button" :class="{ 'is-open': menuOpen }" @click="menuOpen = false" aria-label="Cerrar menú administrativo" tabindex="-1"></button>
    <aside class="hk-sidebar" id="hk-sidebar" :class="{ 'is-open': menuOpen }">
        <button class="hk-close" type="button" @click="menuOpen = false; $refs.menuToggle.focus()">Cerrar menú ×</button>
        <a class="hk-brand" href="{{ route('housekeeping.dashboard') }}">Shop D. <strong>Nakama</strong><small>Housekeeping · Administración</small></a>
        <nav aria-label="Administración">
            <a href="{{ route('housekeeping.dashboard') }}" @if(request()->routeIs('housekeeping.dashboard')) aria-current="page" @endif>Resumen</a>
            @foreach($modules as $slug => $label)<a href="{{ \App\Http\Controllers\HousekeepingController::moduleUrl($slug) }}" @if(($module ?? '') === $slug) aria-current="page" @endif>{{ $label }}</a>@endforeach
        </nav>
        <div class="hk-sidebar-bottom"><a href="{{ route('account.index') }}">← Volver a mi cuenta</a><a href="{{ route('home') }}">Ver tienda</a><form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form></div>
    </aside>
    <div class="hk-main">
        <header class="hk-topbar"><button class="hk-menu" x-ref="menuToggle" type="button" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen" aria-controls="hk-sidebar"><x-store.icon name="menu"/> Menú</button><span>Panel interno</span><div class="hk-user"><span>{{ auth()->user()->name }}</span><small>Administrador</small></div></header>
        <main id="hk-content" class="hk-content">
            @if(session('status'))<div class="hk-notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="hk-errors" role="alert"><strong>Revisa los datos del formulario.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul><p>Si seleccionaste archivos, vuelve a seleccionarlos antes de guardar.</p></div>@endif
            @yield('content')
        </main>
        <footer class="hk-footer">Shop D. Nakama · Housekeeping</footer>
    </div>
</div>
</body>
</html>
