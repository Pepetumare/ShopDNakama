@props(['images', 'name'])
@php($gallery = $images->isEmpty() ? collect([null]) : $images)
<div class="product-gallery" x-data="{ active: 0 }">
    <div class="product-gallery-main">
        @foreach($gallery as $image)
            <div x-show="active === {{ $loop->index }}" @if(!$loop->first) x-cloak @endif>
                <x-store.image :path="$image?->image" :alt="$image?->alt_text ?: $name" :eager="$loop->first" />
            </div>
        @endforeach
    </div>
    <div class="product-thumbnails" role="group" aria-label="Vistas del producto">
        @foreach($gallery as $image)
            <button type="button" @click="active = {{ $loop->index }}" :aria-pressed="active === {{ $loop->index }}" aria-label="Mostrar imagen {{ $loop->iteration }} de {{ $name }}">
                <x-store.image :path="$image?->image" :alt="$image?->alt_text ?: $name" />
            </button>
        @endforeach
    </div>
</div>