@extends('account.layout', ['accountTitle' => 'Resumen'])
@section('account-content')
<p>Bienvenido a tu cuenta. Consulta tus datos y encuentra tus accesos en un solo lugar.</p>
<div class="account-summary-grid">
    <a class="account-card" href="{{ route('account.data') }}"><x-store.icon name="user"/><h3>Mis datos</h3><p>Actualiza tu nombre, correo y contraseña.</p><span>Ver mis datos →</span></a>
    <a class="account-card" href="{{ route('account.orders.index') }}"><x-store.icon name="cube"/><h3>Mis pedidos</h3><p>Consulta el historial y el estado de tus compras.</p><span>Ver pedidos →</span></a>
    <a class="account-card" href="{{ route('account.addresses') }}"><x-store.icon name="pin"/><h3>Mis direcciones</h3><p>Próximamente podrás gestionar tus direcciones de envío.</p><span>Ver direcciones →</span></a>
    <a class="account-card" href="{{ route('account.favorites') }}"><x-store.icon name="heart"/><h3>Favoritos</h3><p>Encuentra los productos que guardaste para verlos después.</p><span>Ver favoritos →</span></a>
</div>
@endsection
