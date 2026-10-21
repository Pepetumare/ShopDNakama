@extends('account.layout', ['accountTitle' => 'Mis direcciones'])
@section('account-content')
<div class="account-empty"><x-store.icon name="pin" size="38"/><h3>Tus direcciones, en un solo lugar</h3><p>Próximamente podrás agregar y editar direcciones para tus envíos. Esta sección aún no guarda direcciones.</p><a class="shop-button green" href="{{ route('catalogo') }}">Explorar catálogo</a></div>
@endsection
