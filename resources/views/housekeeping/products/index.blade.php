@extends('housekeeping.layout', ['module' => 'productos'])
@section('title', 'Productos | Housekeeping')
@section('content')
<div class="hk-toolbar"><div class="hk-page-heading"><p>HOUSEKEEPING / CATÁLOGO</p><h1>Productos</h1><span>{{ $products->total() }} productos encontrados</span></div><a class="hk-button" href="{{ route('housekeeping.products.create') }}">Crear producto</a></div>
<form class="hk-panel hk-filters" method="get" action="{{ route('housekeeping.products.index') }}">
    <div class="hk-field"><label for="q">Nombre o SKU</label><input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="Buscar producto" maxlength="255"></div>
    <div class="hk-field"><label for="category_id">Categoría</label><select id="category_id" name="category_id"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div class="hk-field"><label for="state">Estado</label><select id="state" name="state"><option value="">Todos</option><option value="active" @selected(request('state') === 'active')>Activo</option><option value="inactive" @selected(request('state') === 'inactive')>Inactivo</option></select></div>
    <button class="hk-button" type="submit">Filtrar</button><a class="hk-secondary" href="{{ route('housekeeping.products.index') }}">Limpiar</a>
</form>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><caption class="hk-sr-only">Productos de la tienda</caption><thead><tr><th>Imagen</th><th>Nombre</th><th>Categoría</th><th>SKU</th><th>Precio</th><th>Stock</th><th>Estado</th><th>Destacado</th><th>Acciones</th></tr></thead><tbody>
@forelse($products as $product)
<tr><td><x-store.image class="hk-thumbnail" :path="$product->images->first()?->image" :alt="$product->name"/></td><td><strong>{{ $product->name }}</strong></td><td>{{ $product->category->name }}</td><td>{{ $product->sku ?: '—' }}</td><td><x-store.price :amount="$product->current_price"/>@if($product->on_sale)<del class="hk-old-price"><x-store.price :amount="$product->price"/></del>@endif</td><td>{{ $product->stock }}<small class="hk-cell-note">{{ $product->stock_label }}</small></td><td><span @class(['hk-badge', 'is-active' => $product->is_active])>{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span>@if(!$product->category->is_active)<small class="hk-cell-note">Categoría inactiva</small>@endif</td><td>{{ $product->is_featured ? 'Sí' : 'No' }}</td><td><div class="hk-row-actions"><a href="{{ route('housekeeping.products.edit', $product) }}">Editar</a><form method="post" action="{{ route('housekeeping.products.status', $product) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}"><button type="submit" class="hk-link-button">{{ $product->is_active ? 'Desactivar' : 'Activar' }}</button></form></div></td></tr>
@empty<tr><td colspan="9" class="hk-empty">No encontramos productos con esos filtros.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$products"/>
@endsection
