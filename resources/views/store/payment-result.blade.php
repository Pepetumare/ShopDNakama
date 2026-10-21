@extends('layouts.store')
@section('title', 'Estado del pago | Shop D. Nakama')
@section('content')
<div class="shop-container cart-page"><div class="account-empty">
    <h1>{{ $result === 'failure' ? 'No se completó el pago' : 'Estamos confirmando tu pago' }}</h1>
    <p>El estado definitivo aparecerá en Mis pedidos cuando Mercado Pago confirme la operación.</p>
    <a class="shop-button green" href="{{ route('account.orders.index') }}">Ver mis pedidos</a>
    <a class="shop-button red" href="{{ route('cart.index') }}">Volver al carrito</a>
</div></div>
@endsection
