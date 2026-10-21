@extends('housekeeping.layout', ['module' => 'categorias'])
@section('title', 'Categorías | Housekeeping')
@section('content')
<div class="hk-toolbar"><div class="hk-page-heading"><p>HOUSEKEEPING / CATÁLOGO</p><h1>Categorías</h1><span>{{ $categories->total() }} categorías · El orden menor aparece primero en la tienda.</span></div><a class="hk-button" href="{{ route('housekeeping.categories.create') }}">Crear categoría</a></div>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><caption class="hk-sr-only">Categorías de la tienda</caption><thead><tr><th>Imagen</th><th>Nombre</th><th>Slug</th><th>Orden</th><th>Productos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
@forelse($categories as $category)
<tr><td><x-store.image class="hk-thumbnail" :path="$category->image" :alt="$category->name"/></td><td><strong>{{ $category->name }}</strong></td><td>{{ $category->slug }}</td><td>{{ $category->sort_order }}</td><td>{{ $category->products_count }}</td><td><span @class(['hk-badge', 'is-active' => $category->is_active])>{{ $category->is_active ? 'Activa' : 'Inactiva' }}</span></td><td><div class="hk-row-actions"><a href="{{ route('housekeeping.categories.edit', $category) }}">Editar</a><form method="post" action="{{ route('housekeeping.categories.status', $category) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}"><button class="hk-link-button" type="submit">{{ $category->is_active ? 'Desactivar' : 'Activar' }}</button></form></div></td></tr>
@empty<tr><td colspan="7" class="hk-empty">No hay categorías. Crea la primera para comenzar.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$categories"/>
@endsection
