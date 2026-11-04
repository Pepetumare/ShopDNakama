@extends('housekeeping.layout', ['module' => 'personalizados'])
@section('title', 'Solicitud personalizada')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / PERSONALIZADOS</p><h1>{{ $customRequest->request_number }}</h1></div>
<section class="hk-panel custom-admin-details"><x-custom-requests.details :custom-request="$customRequest"/>
<x-custom-requests.saved-preview :custom-request="$customRequest" :admin="true"/><x-custom-requests.payment-status :custom-request="$customRequest" :admin="true"/></section>
<x-custom-requests.design-assistance :custom-request="$customRequest" :admin="true"/>
<x-custom-requests.quote-breakdown :custom-request="$customRequest"/>
<form method="post" action="{{ route('housekeeping.custom-requests.update', $customRequest) }}" class="hk-panel hk-form" x-data="{ quoteBase: @js(old('base_quoted_price', $customRequest->base_quoted_price)), quoteFee: @js($customRequest->design_assistance_fee) }">
    @csrf @method('patch')
    <h2>Gestión</h2>
    <div class="hk-field"><label for="status">Estado</label><select id="status" name="status" required>@foreach(\App\Models\CustomRequest::STATUSES as $value => $label)<option value="{{ $value }}" @selected(old('status', $customRequest->status) === $value)>{{ $label }}</option>@endforeach</select></div>
    @if($customRequest->design_assistance)
    <div class="hk-field"><label for="base_quoted_price">Precio base cotizado del lote completo (CLP)</label><input id="base_quoted_price" name="base_quoted_price" x-model="quoteBase" type="number" min="0.01" max="{{ (999999999999 - $customRequest->design_assistance_fee * 100) / 100 }}" step="0.01" @readonly($customRequest->order) value="{{ old('base_quoted_price', $customRequest->base_quoted_price) }}"></div>
    <p>El diseño asistido (+<x-store.price :amount="$customRequest->design_assistance_fee"/>) se suma automáticamente una sola vez al precio base. El total cotizado incluye este servicio.</p>
    <p>Total a cotizar: <strong x-text="quoteBase ? new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(Number(quoteBase) + quoteFee) : 'Pendiente de precio base'"></strong></p>
    @else
    <div class="hk-field"><label for="quoted_price">Precio cotizado del lote completo (CLP)</label><input id="quoted_price" name="quoted_price" type="number" min="0.01" max="9999999999.99" step="0.01" @readonly($customRequest->order) value="{{ old('quoted_price', $customRequest->quoted_price) }}"></div>
    @endif
    <p>Un precio nuevo cambia la solicitud a Cotizado. Después de aceptar, el monto queda fijado en su pedido. El pago aprobado habilita la producción.</p>
    <div class="hk-field"><label for="admin_notes">Notas internas (solo administradores)</label><textarea id="admin_notes" name="admin_notes" rows="6" maxlength="10000">{{ old('admin_notes', $customRequest->admin_notes) }}</textarea></div>
    <button class="hk-button">Guardar gestión</button>
</form>
<a href="{{ route('housekeeping.custom-requests.index') }}">Volver a Personalizados</a>
@endsection
