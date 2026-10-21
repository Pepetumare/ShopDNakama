<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Tu cuenta' }} | Shop D. Nakama</title>
    @vite(['resources/css/auth.css', 'resources/js/store.js'])
</head>
<body class="auth-page">
    <a class="auth-skip" href="#auth-content">Saltar al formulario</a>
    <div class="auth-decoration" aria-hidden="true"><span class="auth-sun"></span><x-store.icon name="torii" class="auth-torii" size="260"/><span class="auth-waves"></span></div>
    <main class="auth-shell" id="auth-content">
        <a class="auth-brand" href="{{ route('home') }}" aria-label="Shop D. Nakama, inicio">
            <x-store.art :x="30" :y="42" :w="113" :h="113" label="Logo Shop D. Nakama" class="auth-logo"/>
            <span class="auth-wordmark">SHOP D. <em>NAKAMA</em></span>
            <span class="auth-brand-caption">ANIME · CULTURA JAPONESA</span>
        </a>
        <section class="auth-card" aria-labelledby="auth-title">
            <header class="auth-heading"><span class="auth-heading-accent" aria-hidden="true"></span><h1 id="auth-title">{{ $title ?? 'Tu cuenta, nakama' }}</h1>@isset($subtitle)<p>{{ $subtitle }}</p>@endisset</header>
            <x-auth.status :status="session('status')" />
            @if($errors->any())
                <div class="auth-alert" role="alert"><strong>No pudimos completar la solicitud.</strong><p>Revisa los mensajes de los campos e inténtalo de nuevo.</p></div>
            @endif
            {{ $slot }}
        </section>
        <a class="auth-back" href="{{ route('home') }}"><span aria-hidden="true">←</span> Volver a la tienda</a>
        <p class="auth-signoff">Shop D. Nakama <span aria-hidden="true">·</span> Para verdaderos nakamas</p>
    </main>
</body>
</html>
