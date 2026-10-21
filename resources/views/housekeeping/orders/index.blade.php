@extends('housekeeping.layout', ['module' => 'pedidos'])
@section('title', 'Pedidos | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / PEDIDOS</p><h1>Pedidos</h1><span>{{ $orders->total() }} pedidos encontrados</span></div>
<form method="get" action="{{ route('housekeeping.orders.index') }}" class="hk-panel hk-filters">
    <div class="hk-field"><label for="q">Número, cliente o correo</label><input id="q" name="q" type="search" maxlength="255" value="{{ request('q') }}"></div>
    <div class="hk-field"><label for="status">Estado del pedido</label><select id="status" name="status"><option value="">Todos</option>@foreach(\App\Models\Order::STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="hk-field"><label for="payment_status">Estado de pago</label><select id="payment_status" name="payment_status"><option value="">Todos</option>@foreach(\App\Models\Order::PAYMENT_STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('payment_status') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="hk-field"><label for="customer_id">Cliente</label><select id="customer_id" name="customer_id"><option value="">Todos</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) request('customer_id') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->email }}</option>@endforeach</select></div>
    <div class="hk-field"><label for="date_from">Desde</label><input id="date_from" type="date" name="date_from" value="{{ request('date_from') }}"></div>
    <div class="hk-field"><label for="date_to">Hasta</label><input id="date_to" type="date" name="date_to" value="{{ request('date_to') }}"></div>
    <button type="submit" class="hk-button">Filtrar</button><a class="hk-secondary" href="{{ route('housekeeping.orders.index') }}">Limpiar</a>
</form>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><caption class="hk-sr-only">Pedidos registrados</caption><thead><tr><th>Número de pedido</th><th>Cliente</th><th>Fecha</th><th>Estado</th><th>Total</th><th>Método de pago</th><th>Estado de pago</th><th>Acciones</th></tr></thead><tbody>
@forelse($orders as $order)
<tr><td><strong>{{ $order->number }}</strong></td><td>{{ $order->customer_display_name }}<small class="hk-cell-note">{{ $order->customer_display_email }}</small></td><td>{{ $order->created_at?->format('d/m/Y H:i') }}</td><td><span class="hk-badge {{ $order->status === 'cancelado' ? '' : 'is-active' }}">{{ $order->status_label }}</span></td><td><x-store.price :amount="$order->total"/></td><td>{{ $order->payment_method_label }}</td><td>{{ $order->payment_status_label }}</td><td><a class="hk-link-button" href="{{ route('housekeeping.orders.show', $order) }}">Ver pedido →</a></td></tr>
@empty<tr><td colspan="8" class="hk-empty">No encontramos pedidos con esos filtros.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$orders"/>
@endsection
