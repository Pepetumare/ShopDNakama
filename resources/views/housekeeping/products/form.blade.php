@extends('housekeeping.layout', ['module' => 'productos'])
@section('title', ($product->exists ? 'Editar producto' : 'Crear producto').' | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / PRODUCTOS</p><h1>{{ $product->exists ? 'Editar producto' : 'Crear producto' }}</h1><span>Administra los datos, publicación e imágenes del producto.</span></div>
@if($categories->isEmpty())<p class="hk-notice">Primero debes <a href="{{ route('housekeeping.categories.create') }}">crear una categoría</a> para poder guardar productos.</p>@endif
<form class="hk-panel hk-form" method="post" enctype="multipart/form-data" action="{{ $product->exists ? route('housekeeping.products.update', $product) : route('housekeeping.products.store') }}">
    @csrf
    @if($product->exists) @method('PUT') @endif
    <h2>Información del producto</h2>
    <div class="hk-form-grid">
        <div class="hk-field"><label for="name">Nombre *</label><input id="name" name="name" value="{{ old('name', $product->name) }}" required maxlength="255"></div>
        <div class="hk-field"><label for="slug">Slug</label><input id="slug" name="slug" value="{{ old('slug', $product->slug) }}" maxlength="255"><small>Vacío: se genera al crear o se conserva el actual al editar. Cambiarlo modifica la URL pública.</small></div>
        <div class="hk-field"><label for="category_id">Categoría *</label><select id="category_id" name="category_id" required><option value="">Selecciona una categoría</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (inactiva)' }}</option>@endforeach</select></div>
        <div class="hk-field"><label for="sku">SKU</label><input id="sku" name="sku" value="{{ old('sku', $product->sku) }}" maxlength="255"></div>
        <div class="hk-field"><label for="product_type">Tipo de producto</label><input id="product_type" name="product_type" value="{{ old('product_type', $product->product_type) }}" maxlength="255"></div>
        <div class="hk-field"><label for="stock">Stock *</label><input id="stock" name="stock" type="number" min="0" max="2147483647" step="1" value="{{ old('stock', $product->stock) }}" required></div>
        <div class="hk-field"><label for="price">Precio normal (CLP) *</label><input id="price" name="price" type="number" min="0" max="9999999999.99" step="0.01" value="{{ old('price', $product->price) }}" required><small>Ingresa el valor sin separador de miles. Ejemplo: 12990.</small></div>
        <div class="hk-field"><label for="sale_price">Precio de oferta (CLP)</label><input id="sale_price" name="sale_price" type="number" min="0" max="9999999999.99" step="0.01" value="{{ old('sale_price', $product->sale_price) }}"><small>Opcional; debe ser menor al precio normal.</small></div>
        <div class="hk-field hk-full"><label for="short_description">Descripción corta</label><textarea id="short_description" name="short_description" rows="3" maxlength="2000">{{ old('short_description', $product->short_description) }}</textarea></div>
        <div class="hk-field hk-full"><label for="description">Descripción completa</label><textarea id="description" name="description" rows="7" maxlength="50000">{{ old('description', $product->description) }}</textarea></div>
    </div>
    <fieldset class="hk-options"><legend>Publicación</legend>
        @foreach(['is_active' => 'Activo', 'is_featured' => 'Destacado en la home', 'is_new' => 'Nuevo'] as $field => $label)
            <input type="hidden" name="{{ $field }}" value="0"><label class="hk-checkbox"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $product->$field))> {{ $label }}</label>
        @endforeach
    </fieldset>
    <h2>Imágenes</h2>
    <div class="hk-field"><label for="uploads">Subir imágenes</label><input id="uploads" type="file" name="uploads[]" multiple accept="image/jpeg,image/png,image/webp"><small>Hasta 10 archivos por envío, JPG, PNG o WebP, máximo 5 MB cada uno. La primera imagen será principal si todavía no existe una. Puedes elegir otra después de guardar.</small></div>
    <x-input-error class="hk-field-errors" :messages="$errors->get('uploads')"/>
    @if($product->exists && $product->images->isNotEmpty())
        <div class="hk-image-grid">
        @foreach($product->images as $image)
            <div class="hk-image-editor">
                <x-store.image :path="$image->image" :alt="$image->alt_text ?: $product->name"/>
                <input type="hidden" name="images_meta[{{ $loop->index }}][id]" value="{{ $image->id }}">
                <label class="hk-checkbox"><input type="radio" name="primary_image_id" value="{{ $image->id }}" @checked((string) old('primary_image_id', $product->images->firstWhere('is_primary', true)?->id) === (string) $image->id)> Imagen principal</label>
                <div class="hk-field"><label for="image-order-{{ $image->id }}">Orden</label><input id="image-order-{{ $image->id }}" type="number" name="images_meta[{{ $loop->index }}][sort_order]" min="0" max="2147483647" step="1" value="{{ old('images_meta.'.$loop->index.'.sort_order', $image->sort_order) }}" required></div>
                <div class="hk-field"><label for="image-alt-{{ $image->id }}">Texto alternativo</label><input id="image-alt-{{ $image->id }}" name="images_meta[{{ $loop->index }}][alt_text]" value="{{ old('images_meta.'.$loop->index.'.alt_text', $image->alt_text) }}" maxlength="255"></div>
                <button type="submit" form="delete-image-{{ $image->id }}" class="hk-delete-button">Eliminar imagen</button>
            </div>
        @endforeach
        </div>
        <p class="hk-help">La imagen principal se muestra primero. Las demás siguen el orden numérico. Guarda el producto para aplicar los cambios de orden y principal.</p>
    @endif
    <div class="hk-form-actions"><button class="hk-button" type="submit" @disabled($categories->isEmpty())>Guardar producto</button><a class="hk-secondary" href="{{ route('housekeeping.products.index') }}">Volver al listado</a>@if($product->exists && $product->is_active)<a class="hk-secondary" href="{{ route('producto.show', $product) }}">Ver en la tienda</a>@endif</div>
</form>
@if($product->exists)
    @foreach($product->images as $image)
        <form id="delete-image-{{ $image->id }}" method="post" action="{{ route('housekeeping.products.images.destroy', [$product, $image]) }}" @submit="if (!window.confirm('¿Eliminar esta imagen? Los cambios sin guardar del formulario no se conservarán.')) $event.preventDefault()">@csrf @method('DELETE')</form>
    @endforeach
@endif
@endsection
