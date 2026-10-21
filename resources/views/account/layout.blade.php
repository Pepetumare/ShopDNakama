@extends('layouts.store')
@section('title', ($accountTitle ?? 'Mi cuenta').' | Shop D. Nakama')
@section('content')
<div class="shop-container account-page">
    <div class="account-heading"><p class="eyebrow">SHOP D. NAKAMA</p><h1>Mi cuenta</h1><p>Tu espacio en la tripulación, {{ auth()->user()->name }}.</p></div>
    <div class="account-layout">
        <aside class="account-sidebar" aria-label="Menú de mi cuenta">
            <nav class="account-nav" aria-label="Mi cuenta">
                @foreach(['account.index' => 'Resumen', 'account.data' => 'Mis datos', 'account.addresses' => 'Mis direcciones', 'account.orders.index' => 'Mis pedidos', 'account.favorites' => 'Favoritos'] as $route => $label)
                    <a href="{{ route($route) }}" @if(request()->routeIs($route) || ($route === 'account.orders.index' && request()->routeIs('account.orders.*'))) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form>
            </nav>
            @if(auth()->user()->isAdmin())
                <div class="account-admin-access"><a class="shop-button green" href="{{ route('housekeeping.dashboard') }}"><x-store.icon name="gear" size="19"/> Housekeeping</a></div>
            @endif
        </aside>
        <section class="account-content" aria-labelledby="account-section-title">
            <h2 id="account-section-title">{{ $accountTitle ?? 'Resumen' }}</h2>
            @if(session('status'))<p class="account-notice" role="status">{{ session('status') === 'password-updated' ? 'Contraseña actualizada correctamente.' : session('status') }}</p>@endif
            @yield('account-content')
        </section>
    </div>
</div>
@endsection
