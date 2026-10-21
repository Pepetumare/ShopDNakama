@extends('account.layout', ['accountTitle' => 'Favoritos'])
@section('account-content')
@if($favorites->total() > 0)
    <p>Tienes {{ $favorites->total() }} {{ $favorites->total() === 1 ? 'producto guardado' : 'productos guardados' }}.</p>
    <div class="product-grid account-favorites-grid">
        @foreach($favorites as $product)<x-store.product-card :product="$product"/>@endforeach
    </div>
    <x-orders.pagination :paginator="$favorites"/>
@else
    <div class="account-empty"><x-store.icon name="heart" size="38"/><h3>Aún no tienes favoritos</h3><p>Guarda productos para encontrarlos rápidamente después.</p><a class="shop-button green" href="{{ route('catalogo') }}">Explorar catálogo</a></div>
@endif
@endsection
