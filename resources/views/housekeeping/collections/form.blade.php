@extends('housekeeping.layout')
@section('title', ($collection->exists ? 'Editar colección' : 'Crear colección').' | Housekeeping')
@section('content')
<div class="hk-page-heading"><h1>{{ $collection->exists ? $collection->name : 'Crear colección' }}</h1></div>
<form class="hk-panel hk-form hk-collection-form" method="post" enctype="multipart/form-data"
      action="{{ $collection->exists ? route('housekeeping.collections.update', $collection) : route('housekeeping.collections.store') }}">
    @csrf
    @if($collection->exists) @method('PUT') @endif
    <h2>Datos de la serie</h2>
    <div class="hk-form-grid">
        <div class="hk-field">
            <label for="name">Nombre</label>
            <input id="name" name="name" value="{{ old('name', $collection->name) }}" required maxlength="200">
        </div>
        <div class="hk-field">
            <label for="category_id">Categoría</label>
            <select id="category_id" name="category_id" required>
                <option value="">Selecciona una categoría</option>
                @foreach($categories as $id => $name)
                    <option value="{{ $id }}" @selected((string) old('category_id', $collection->category_id) === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="hk-field hk-full">
            <label for="description">Descripción</label>
            <textarea id="description" name="description" rows="4" maxlength="10000">{{ old('description', $collection->description) }}</textarea>
        </div>
        <div class="hk-field">
            <label for="image">Imagen representativa</label>
            @if($collection->image)<x-store.image class="hk-thumbnail" :path="$collection->image" :alt="$collection->name"/>@endif
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
            <small>JPG, PNG o WebP, hasta 5 MB.</small>
        </div>
        <div class="hk-field">
            <label for="sort_order">Orden en catálogo</label>
            <input id="sort_order" name="sort_order" type="number" min="0" max="2147483647" required value="{{ old('sort_order', $collection->sort_order) }}">
        </div>
        <div class="hk-field">
            <label for="seo_title">Título SEO</label>
            <input id="seo_title" name="seo_title" maxlength="255" value="{{ old('seo_title', $collection->seo_title) }}">
        </div>
        <div class="hk-field">
            <label for="seo_description">Descripción SEO</label>
            <input id="seo_description" name="seo_description" maxlength="255" value="{{ old('seo_description', $collection->seo_description) }}">
        </div>
    </div>
    <input type="hidden" name="is_published" value="0">
    <label class="hk-checkbox"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $collection->is_published))> Publicar en la tienda</label>
    <div class="hk-form-actions">
        <button class="hk-button" type="submit" @disabled($categories->isEmpty())>Guardar colección</button>
        <a class="hk-secondary" href="{{ route('housekeeping.collections.index') }}">Volver a colecciones</a>
    </div>
</form>

@if($collection->exists)
    <section class="hk-panel hk-form hk-collection-form" aria-labelledby="volumes-heading">
        <h2 id="volumes-heading">Tomos asociados</h2>
        <p>Se muestran primero los tomos con menor orden. Si el orden coincide, se usa el número de tomo.</p>
        @forelse($volumes as $volume)
            @php($product = $volume->product)
            <article class="hk-collection-volume">
                <h3><a href="{{ route('housekeeping.products.edit', $product) }}">{{ $product->name }}</a></h3>
                <p>{{ $product->stock_label }} · {{ $product->stock }} unidades @if(!$product->is_active || !$product->category->is_active) · No publicado @endif</p>
                <form class="hk-form-grid" method="post" action="{{ route('housekeeping.collections.volumes.update', [$collection, $volume]) }}">
                    @csrf @method('PATCH')
                    <div class="hk-field">
                        <label for="volume-{{ $volume->id }}">Número de tomo</label>
                        <input id="volume-{{ $volume->id }}" name="volume_number" type="number" min="1" max="2147483647" required value="{{ $volume->volume_number }}">
                    </div>
                    <div class="hk-field">
                        <label for="order-{{ $volume->id }}">Orden del tomo</label>
                        <input id="order-{{ $volume->id }}" name="sort_order" type="number" min="0" max="2147483647" required value="{{ $volume->sort_order }}">
                    </div>
                    <div class="hk-form-actions hk-full">
                        <button class="hk-secondary" type="submit">Guardar tomo</button>
                        <button class="hk-delete-button" type="submit" form="remove-volume-{{ $volume->id }}">Retirar de la colección</button>
                    </div>
                </form>
                <form id="remove-volume-{{ $volume->id }}" method="post" action="{{ route('housekeeping.collections.volumes.destroy', [$collection, $volume]) }}">
                    @csrf @method('DELETE')
                </form>
            </article>
        @empty
            <p>Agrega un producto existente para comenzar.</p>
        @endforelse
    </section>
    <form class="hk-panel hk-form hk-collection-form" method="post" action="{{ route('housekeeping.collections.volumes.store', $collection) }}">
        @csrf
        <h2>Asociar un producto</h2>
        <div class="hk-form-grid">
            <div class="hk-field hk-full">
                <label for="product_id">Producto</label>
                <select id="product_id" name="product_id" required>
                    <option value="">Selecciona un producto sin colección</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="hk-field">
                <label for="new-number">Número de tomo</label>
                <input id="new-number" name="volume_number" type="number" min="1" max="2147483647" required value="{{ old('volume_number') }}">
            </div>
            <div class="hk-field">
                <label for="new-order">Orden del tomo</label>
                <input id="new-order" name="sort_order" type="number" min="0" max="2147483647" required value="{{ old('product_id') ? old('sort_order', 0) : 0 }}">
            </div>
        </div>
        <div class="hk-form-actions">
            <button class="hk-button" type="submit" @disabled($products->isEmpty())>Asociar producto</button>
        </div>
    </form>
@endif
@endsection
