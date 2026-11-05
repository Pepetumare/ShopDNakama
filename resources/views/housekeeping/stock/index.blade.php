@extends('housekeeping.layout', ['module' => 'stock'])
@section('title', 'Stock | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / INVENTARIO</p><h1>Control de stock</h1><span>Disponibilidad actual, reposiciones y trazabilidad de productos.</span></div>
<div class="hk-stats">
@foreach(['Unidades disponibles' => $units, 'Productos agotados' => $empty, 'Productos con stock bajo' => $low] as $label => $value)<div class="hk-stat"><span>{{ $label }}</span><strong>{{ number_format($value, 0, ',', '.') }}</strong><small>Inventario completo</small></div>@endforeach
</div>
<form class="hk-panel hk-filters" method="get" action="{{ route('housekeeping.stock.index') }}">
<div class="hk-field"><label for="q">Nombre o SKU</label><input id="q" name="q" type="search" value="{{ request('q') }}" maxlength="255"></div>
<div class="hk-field"><label for="category_id">Categoría</label><select id="category_id" name="category_id"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
<div class="hk-field"><label for="state">Disponibilidad</label><select id="state" name="state"><option value="">Todas</option>@foreach(['available' => 'En stock', 'low' => 'Stock bajo', 'empty' => 'Agotado'] as $key => $label)<option value="{{ $key }}" @selected(request('state') === $key)>{{ $label }}</option>@endforeach</select></div>
<div class="hk-field"><label for="type">Tipo de movimiento</label><select id="type" name="type"><option value="">Todos</option>@foreach(\App\Models\StockMovement::TYPES as $key => $label)<option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>@endforeach</select></div>
<div class="hk-field"><label for="date_from">Movimientos desde</label><input id="date_from" name="date_from" type="date" value="{{ request('date_from') }}"></div><div class="hk-field"><label for="date_to">Hasta</label><input id="date_to" name="date_to" type="date" value="{{ request('date_to') }}"></div>
<button class="hk-button">Filtrar</button><a class="hk-secondary" href="{{ route('housekeeping.stock.index') }}">Limpiar</a></form>
<h2>Inventario por producto</h2>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><thead><tr><th>Imagen</th><th>Producto / SKU</th><th>Categoría</th><th>Stock actual</th><th>Mínimo</th><th>Disponibilidad</th><th>Acciones</th></tr></thead><tbody>
@forelse($products as $product)<tr><td><x-store.image class="hk-thumbnail" :path="$product->images->first()?->image" :alt="$product->name"/></td><td><strong>{{ $product->name }}</strong><small class="hk-cell-note">{{ $product->sku ?: 'Sin SKU' }}</small></td><td>{{ $product->category->name }}</td><td><strong>{{ $product->stock }}</strong></td><td>{{ $product->minimum_stock }}</td><td><span @class(['hk-badge', 'is-active' => $product->stock > $product->minimum_stock])>{{ $product->stock_label }}</span></td><td><a class="hk-secondary" href="{{ route('housekeeping.stock.show', $product) }}">Gestionar stock</a></td></tr>
@empty<tr><td colspan="7" class="hk-empty">No hay productos con estos filtros.</td></tr>@endforelse
</tbody></table></div><x-housekeeping.pagination :paginator="$products"/>
<h2 class="hk-stock-heading">Movimientos recientes</h2>
@include('housekeeping.stock.movements')
<details class="hk-panel hk-detail-panel hk-stock-heading"><summary>Registrar devolución de un pedido reembolsado</summary><p>Confirma que recibiste los productos en condiciones de volver al inventario. Un reembolso por sí solo no repone mercadería.</p>
<form class="hk-status-form" method="post" action="{{ route('housekeeping.stock.restore') }}" @submit="if (!window.confirm('¿Confirmas la recepción y reposición de todos los productos físicos del pedido?')) $event.preventDefault()">@csrf
<div class="hk-field"><label for="order_number">Número del pedido</label><input id="order_number" name="order_number" maxlength="255" value="{{ old('order_number') }}" placeholder="SDN-…" required><small>Ingresa el número que aparece en el detalle del pedido.</small></div>
<div class="hk-field"><label for="return_reason">Motivo</label><textarea id="return_reason" name="reason" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea></div>
<label class="hk-checkbox"><input type="checkbox" name="received" value="1" required> Recibí todos los productos físicos y están aptos para venta.</label><button class="hk-button">Confirmar devolución y reponer</button></form></details>
@endsection
