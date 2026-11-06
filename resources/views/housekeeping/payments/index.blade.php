@extends('housekeeping.layout')
@section('title', 'Conciliación de pagos')
@section('content')
<h1>Conciliación de pagos</h1>
<p><a href="{{ route('housekeeping.payments.index', $all ? [] : ['all' => 1]) }}">{{ $all ? 'Ver pagos e incidencias pendientes' : 'Ver todos los pagos verificados' }}</a></p>
<div class="hk-table-wrap">
<table class="hk-table">
    <thead><tr><th>Pedido</th><th>Cliente</th><th>ID de pago</th><th>Importe</th><th>Estado oficial</th><th>Incidencia</th><th>Última consulta</th><th>Resolución</th></tr></thead>
    <tbody>
    @forelse($payments as $payment)
        <tr>
            <td><a href="{{ route('housekeeping.payments.show', $payment->order) }}">{{ $payment->order->number }}</a></td>
            <td>{{ $payment->order->customer_name }}</td>
            <td>{{ $payment->provider_payment_id }}</td>
            <td>{{ $payment->amount }} {{ $payment->currency }}</td>
            <td>{{ $payment->provider_status }}</td>
            <td>{{ $payment->incident ?? '—' }}</td>
            <td>{{ $payment->last_verified_at }}</td>
            <td>{{ $payment->resolved_at ?? ($payment->incident ? 'Abierta' : '—') }}</td>
        </tr>
    @empty
        <tr><td colspan="8">No hay pagos en esta vista.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<x-housekeeping.pagination :paginator="$payments"/>

<h2>Intentos con resultado incierto</h2>
<ul>
@forelse($uncertain as $attempt)
    <li><a href="{{ route('housekeeping.payments.show', $attempt->order) }}">{{ $attempt->order->number }}</a> · {{ $attempt->state }}</li>
@empty
    <li>No hay intentos inciertos con unidades retenidas.</li>
@endforelse
</ul>
<x-housekeeping.pagination :paginator="$uncertain"/>
@endsection
