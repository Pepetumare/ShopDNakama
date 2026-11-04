@extends('housekeeping.layout', ['module' => 'personalizados'])
@section('title', 'Personalizados')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / PERSONALIZADOS</p><h1>Personalizados</h1><span>{{ $customRequests->total() }} solicitudes encontradas</span></div>
@foreach($customRequests as $customRequest)
    @if($customRequest->ready_for_production)<p class="hk-notice">{{ $customRequest->request_number }} · Pagado — listo para producción · <a href="{{ route('housekeeping.custom-requests.show', $customRequest) }}">Ver solicitud</a></p>@endif
@endforeach
<form method="get" class="hk-panel hk-filters">
    <div class="hk-field"><label for="q">Número, cliente o email</label><input id="q" name="q" type="search" maxlength="255" value="{{ request('q') }}"></div>
    @foreach(['status' => ['Estado', \App\Models\CustomRequest::STATUSES], 'product_type' => ['Producto', \App\Models\CustomRequest::PRODUCTS]] as $field => [$label, $options])
    <div class="hk-field"><label for="{{ $field }}">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}"><option value="">Todos</option>@foreach($options as $value => $text)<option value="{{ $value }}" @selected(request($field) === $value)>{{ $text }}</option>@endforeach</select></div>
    @endforeach
    <div class="hk-field"><label for="date_from">Desde</label><input id="date_from" type="date" name="date_from" value="{{ request('date_from') }}"></div>
    <div class="hk-field"><label for="date_to">Hasta</label><input id="date_to" type="date" name="date_to" value="{{ request('date_to') }}"></div>
    <button class="hk-button">Filtrar</button><a class="hk-secondary" href="{{ route('housekeeping.custom-requests.index') }}">Limpiar</a>
</form>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><thead><tr><th>Número</th><th>Cliente</th><th>Producto</th><th>Cantidad</th><th>Fecha</th><th>Estado</th><th>Precio cotizado</th><th>Diseño</th><th>Acción</th></tr></thead><tbody>
@forelse($customRequests as $customRequest)
<tr><td>{{ $customRequest->request_number }}</td><td>{{ $customRequest->customer_name }}<small class="hk-cell-note">{{ $customRequest->customer_email }}</small></td><td>{{ $customRequest->product_label }}</td><td>{{ $customRequest->quantity }}</td><td>{{ $customRequest->created_at->format('d/m/Y H:i') }}</td><td><span class="hk-badge">{{ $customRequest->status_label }}</span></td><td>@if($customRequest->quoted_price !== null)<x-store.price :amount="$customRequest->quoted_price"/>@else Por cotizar @endif</td><td>@foreach($customRequest->files as $file)@if($file->is_image)<img src="{{ route('custom-requests.files.show', $file) }}" alt="Diseño" width="64" height="64" style="object-fit:contain" loading="lazy">@elseif($file->file_type === 'model_3d') Modelo 3D · {{ strtoupper($file->extension) }} @else PDF @endif @endforeach</td><td><a href="{{ route('housekeeping.custom-requests.show', $customRequest) }}">Ver</a></td></tr>
@empty<tr><td colspan="9">No encontramos solicitudes con esos filtros.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$customRequests"/>
@endsection
