@props(['path' => null, 'alt' => '', 'eager' => false])
<img {{ $attributes->class(['store-image']) }} src="{{ \App\Support\StoreImage::url($path) }}" alt="{{ $alt }}" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async">
