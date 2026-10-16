@props(['categories'])
<section class="shop-container section-block" id="categorias"><x-store.section-heading icon="torii" subtitle="Todo lo que te hace parte de la nakama">Nuestras categorías</x-store.section-heading><div class="category-grid">
@foreach($categories as $category)
<a class="category-card" href="{{ route('home', ['categoria' => $category['slug']]) }}#productos"><x-store.art :x="$category['x']" :y="535" :w="108" :h="110" :label="$category['name']"/><span>{{ $category['name'] }} <x-store.icon name="arrow" size="18"/></span></a>
@endforeach
</div></section>
