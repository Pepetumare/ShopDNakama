@extends('housekeeping.layout')
@section('title', 'Colecciones | Housekeeping')
@section('content')
<div class="hk-toolbar">
    <div class="hk-page-heading"><h1>Colecciones</h1></div>
    <a class="hk-button" href="{{ route('housekeeping.collections.create') }}">Crear colección</a>
</div>
<div class="hk-panel hk-table-wrap">
    <table class="hk-table">
        <caption class="hk-sr-only">Colecciones de manga</caption>
        <thead><tr><th>Serie</th><th>Categoría</th><th>Tomos</th><th>Publicación</th><th>Acciones</th></tr></thead>
        <tbody>
            @forelse($collections as $collection)
                <tr>
                    <th scope="row">{{ $collection->name }}</th>
                    <td>{{ $collection->category->name }}</td>
                    <td>{{ $collection->volumes_count }}</td>
                    <td>{{ $collection->is_published ? 'Publicada' : 'Borrador' }}</td>
                    <td><a href="{{ route('housekeeping.collections.edit', $collection) }}">Editar {{ $collection->name }}</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="hk-empty">Todavía no hay colecciones.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<x-housekeeping.pagination :paginator="$collections"/>
@endsection
