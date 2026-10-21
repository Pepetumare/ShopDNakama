@props(['paginator'])
@if($paginator->hasPages())
<nav class="hk-pagination" aria-label="Paginación">
    @if($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anterior</a>@endif
    <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente →</a>@endif
</nav>
@endif
