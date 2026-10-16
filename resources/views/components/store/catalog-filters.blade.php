@props(['categories', 'prices'])
<form method="get" action="{{ route('catalogo') }}">
    <h2>Filtrar productos</h2>
    @foreach(request()->only('q', 'orden') as $key => $value)
        @if(is_string($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    @foreach(['categoria' => ['Categorías', $categories], 'precio' => ['Precio', $prices], 'disponibilidad' => ['Disponibilidad', ['stock' => 'En stock', 'agotado' => 'Agotado']]] as $name => [$title, $options])
        <fieldset>
            <legend>{{ $title }}</legend>
            <label><input type="radio" name="{{ $name }}" value="" @checked(!request($name))> Todos</label>
            @foreach($options as $key => $label)
                <label><input type="radio" name="{{ $name }}" value="{{ $key }}" @checked(request($name) === $key)> {{ $label }}</label>
            @endforeach
        </fieldset>
    @endforeach
    <button class="shop-button green" type="submit">Aplicar filtros</button>
    <a class="catalog-clear" href="{{ route('catalogo') }}">Limpiar filtros</a>
</form>
