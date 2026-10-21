@extends('housekeeping.layout', ['module' => 'categorias'])
@section('title', ($category->exists ? 'Editar categoría' : 'Crear categoría').' | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / CATEGORÍAS</p><h1>{{ $category->exists ? 'Editar categoría' : 'Crear categoría' }}</h1><span>Los cambios se reflejan en la tienda al guardar.</span></div>
<form class="hk-panel hk-form" method="post" enctype="multipart/form-data" action="{{ $category->exists ? route('housekeeping.categories.update', $category) : route('housekeeping.categories.store') }}">
    @csrf
    @if($category->exists) @method('PUT') @endif
    <div class="hk-form-grid">
        <div class="hk-field"><label for="name">Nombre *</label><input id="name" name="name" value="{{ old('name', $category->name) }}" required maxlength="255"></div>
        <div class="hk-field"><label for="slug">Slug</label><input id="slug" name="slug" value="{{ old('slug', $category->slug) }}" maxlength="255"><small>Si lo dejas vacío, se genera al crear. Al editar se conserva el actual.</small></div>
        <div class="hk-field hk-full"><label for="description">Descripción</label><textarea id="description" name="description" rows="4" maxlength="20000">{{ old('description', $category->description) }}</textarea></div>
        <div class="hk-field"><label for="sort_order">Orden *</label><input id="sort_order" type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}" required min="0" max="2147483647" step="1"><small>Menor número, mayor prioridad.</small></div>
        <div class="hk-field"><label for="image">Imagen opcional</label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WebP. Máximo 5 MB.</small></div>
    </div>
    @if($category->image)
        <div class="hk-current-image"><x-store.image :path="$category->image" :alt="$category->name"/><label class="hk-checkbox"><input type="checkbox" name="remove_image" value="1" @checked(old('remove_image'))> Quitar imagen actual</label></div>
    @endif
    <input type="hidden" name="is_active" value="0"><label class="hk-checkbox"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active))> Categoría activa</label>
    <p class="hk-help">Desactivar una categoría oculta sus productos en la tienda; no los elimina.</p>
    <div class="hk-form-actions"><button class="hk-button" type="submit">Guardar categoría</button><a class="hk-secondary" href="{{ route('housekeeping.categories.index') }}">Volver al listado</a></div>
</form>
@endsection
