@props(['product', 'detail' => false])
@php($selected = auth()->check() && (bool) $product->is_favorite)
<form method="post" action="{{ route($selected ? 'favorites.destroy' : 'favorites.store', $product) }}" @class(['favorite-form', 'product-favorite-form' => !$detail, 'favorite-form-detail' => $detail])>
    @csrf
    @if($selected) @method('DELETE') @endif
    <button type="submit" @class(['product-favorite' => !$detail, 'shop-button product-favorite-action' => $detail, 'selected' => $selected]) aria-pressed="{{ $selected ? 'true' : 'false' }}" aria-label="{{ $selected ? 'Quitar de favoritos' : 'Agregar a favoritos' }}: {{ $product->name }}" title="{{ auth()->check() ? ($selected ? 'Quitar de favoritos' : 'Agregar a favoritos') : 'Inicia sesión para guardar favoritos' }}">
        <x-store.icon name="heart" :size="$detail ? 24 : 18"/>
        @if($detail)<span>{{ $selected ? 'Quitar de favoritos' : 'Agregar a favoritos' }}</span>@endif
    </button>
</form>
