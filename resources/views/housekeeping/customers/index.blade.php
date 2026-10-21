@extends('housekeeping.layout', ['module' => 'clientes'])
@section('title', 'Clientes | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / CLIENTES</p><h1>Clientes</h1><span>{{ $customers->total() }} usuarios registrados</span></div>
<form method="get" action="{{ route('housekeeping.customers.index') }}" class="hk-panel hk-filters"><div class="hk-field"><label for="q">Nombre o correo</label><input id="q" name="q" type="search" maxlength="255" value="{{ request('q') }}"></div><button class="hk-button" type="submit">Buscar</button><a class="hk-secondary" href="{{ route('housekeeping.customers.index') }}">Limpiar</a></form>
<p class="hk-help">Total gastado: pedidos pagados, excluyendo cancelados y reembolsados.</p>
<div class="hk-panel hk-table-wrap"><table class="hk-table"><caption class="hk-sr-only">Clientes registrados</caption><thead><tr><th>Nombre</th><th>Correo</th><th>Registro</th><th>Pedidos</th><th>Total gastado</th><th>Último pedido</th><th>Acciones</th></tr></thead><tbody>
@forelse($customers as $customer)
<tr><td><strong>{{ $customer->name }}</strong></td><td>{{ $customer->email }}</td><td>{{ $customer->created_at?->format('d/m/Y') ?: 'No registrada' }}</td><td>{{ $customer->orders_count }}</td><td><x-store.price :amount="$customer->total_spent ?? 0"/></td><td>@if($customer->latestOrder)<a class="hk-link-button" href="{{ route('housekeeping.orders.show', $customer->latestOrder) }}">{{ $customer->latestOrder->number }}</a><small class="hk-cell-note">{{ $customer->latestOrder->created_at?->format('d/m/Y') }}</small>@else Sin pedidos @endif</td><td><a class="hk-link-button" href="{{ route('housekeeping.customers.show', $customer) }}">Ver cliente →</a></td></tr>
@empty<tr><td colspan="7" class="hk-empty">No encontramos clientes con esa búsqueda.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$customers"/>
@endsection
