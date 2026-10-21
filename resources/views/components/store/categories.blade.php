@props(['categories'])
<section class="shop-container section-block" id="categorias"><x-store.section-heading icon="torii" subtitle="Todo lo que te hace parte de la nakama">Nuestras categorías</x-store.section-heading><div class="category-grid">
@foreach($categories as $category)
<a class="category-card" href="{{ route('catalogo', ['categoria' => $category['slug']]) }}"><x-store.image :path="$category->image" :alt="$category->name"/><span>{{ $category['name'] }} <x-store.icon name="arrow" size="18"/></span></a>
@endforeach
</div></section>
