@extends('account.layout', ['accountTitle' => 'Mis pedidos'])
@section('account-content')
<div class="account-table-wrap"><table class="account-table">
    <caption class="visually-hidden">Mis pedidos</caption>
    <thead><tr><th scope="col">Número de pedido</th><th scope="col">Fecha</th><th scope="col">Estado</th><th scope="col">Total</th><th scope="col">Detalle</th></tr></thead>
    <tbody>
    @forelse($orders as $order)
        <tr><td>{{ $order->number }}</td><td>{{ $order->created_at?->format('d/m/Y') }}</td><td><span class="account-status">{{ $order->status_label }}</span></td><td><x-store.price :amount="$order->total"/></td><td><a href="{{ route('account.orders.show', $order) }}">Ver pedido →</a></td></tr>
    @empty
        <tr><td colspan="5">Todavía no tienes pedidos registrados.</td></tr>
    @endforelse
    </tbody>
</table></div>
<x-orders.pagination :paginator="$orders"/>
@endsection