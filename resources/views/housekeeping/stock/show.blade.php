@extends('housekeeping.layout', ['module' => 'stock'])
@section('title', 'Gestionar stock | Housekeeping')
@section('content')
<div class="hk-toolbar"><div class="hk-page-heading"><p>HOUSEKEEPING / STOCK</p><h1>{{ $product->name }}</h1><span>SKU: {{ $product->sku ?: 'Sin SKU' }} · {{ $product->category->name }}</span></div><a class="hk-secondary" href="{{ route('housekeeping.stock.index') }}">Volver al inventario</a></div>
<div class="hk-stats"><div class="hk-stat"><span>Stock actual</span><strong>{{ $product->stock }}</strong><small>{{ $product->stock_label }}</small></div><div class="hk-stat"><span>Stock mínimo</span><strong>{{ $product->minimum_stock }}</strong><small>Umbral de alerta</small></div></div>
<div class="hk-detail-grid">
<form class="hk-panel hk-form hk-status-form" method="post" action="{{ route('housekeeping.stock.store', $product) }}" @submit="if (!window.confirm('¿Confirmas este movimiento de inventario?')) $event.preventDefault()">@csrf
<h2>Registrar movimiento</h2><input type="hidden" name="version" value="{{ $product->stock_version }}"><input type="hidden" name="token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<div class="hk-field"><label for="type">Tipo</label><select id="type" name="type" required>
@foreach(\App\Models\StockMovement::TYPES as $key => $label)
    @if(!in_array($key, ['sale', 'return']))
        <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
    @endif
@endforeach
</select></div>
<div class="hk-field"><label for="quantity">Cantidad</label><input id="quantity" name="quantity" type="number" min="0" max="2147483647" step="1" value="{{ old('quantity') }}" required><small>En conteo físico ingresa el total contado. En los demás movimientos ingresa las unidades que entran o salen.</small></div>
<div class="hk-field"><label for="reason">Motivo obligatorio</label><textarea id="reason" name="reason" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea><small>Para devoluciones de ventas usa el formulario de devolución del inventario.</small></div><button class="hk-button">Confirmar movimiento</button></form>
<form class="hk-panel hk-form hk-status-form" method="post" action="{{ route('housekeeping.stock.minimum', $product) }}">@csrf @method('PATCH')<h2>Alerta de reposición</h2><x-store.image class="hk-stock-image" :path="$product->images->first()?->image" :alt="$product->name"/><div class="hk-field"><label for="minimum_stock">Stock mínimo</label><input id="minimum_stock" name="minimum_stock" type="number" min="0" max="2147483647" value="{{ old('minimum_stock', $product->minimum_stock) }}" required><small>Se alerta cuando el stock disponible es igual o inferior a este umbral.</small></div><button class="hk-button">Guardar mínimo</button><a class="hk-secondary" href="{{ route('housekeeping.products.edit', $product) }}">Editar producto</a></form>
</div><h2 class="hk-stock-heading">Historial del producto</h2>@include('housekeeping.stock.movements')
@endsection
