@extends('housekeeping.layout')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING</p><h1>Resumen administrativo</h1><span>Consulta tu tienda y accede a los módulos de gestión.</span></div>
<div class="hk-stats">@foreach($stats as $label => $count)<div class="hk-stat"><span>{{ $label }}</span><strong>{{ number_format($count, 0, ',', '.') }}</strong><small>Datos actuales</small></div>@endforeach</div>
<section aria-labelledby="hk-modules-title"><h2 id="hk-modules-title">Módulos de administración</h2><div class="hk-modules">@foreach($modules as $slug => $label)<a class="hk-module-card" href="{{ \App\Http\Controllers\HousekeepingController::moduleUrl($slug) }}"><span class="hk-module-icon"><x-store.icon name="{{ $slug === 'configuracion' ? 'gear' : 'cube' }}"/></span><h3>{{ $label }}</h3><p>{{ in_array($slug, ['productos', 'categorias', 'pedidos', 'clientes']) ? 'Consulta y administra la tienda.' : 'Estructura preparada para la próxima etapa.' }}</p><span class="hk-module-link">Abrir módulo →</span></a>@endforeach</div></section>
@endsection
