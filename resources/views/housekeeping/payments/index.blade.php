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
<h2>Inicio de pagos personalizados por recuperar</h2>
<ul>
@forelse($customIntents as $intent)
    <li><a href="{{ route('housekeeping.payments.show', $intent->order) }}">{{ $intent->order->number }}</a>
        · {{ $intent->state }} · Creado {{ $intent->created_at }} ({{ $intent->created_at->diffForHumans() }})
        · Último resultado {{ $intent->outcome ?? 'sin consulta' }} · Última consulta {{ $intent->lookup_at ?? 'pendiente' }}</li>
        @if($intent->financial_review_required)<li>Conciliación financiera pendiente · Último intento {{ $intent->financial_reviewed_at ?? 'sin consulta' }} · Próxima revisión {{ $intent->financial_next_review_at ?? 'disponible' }}</li>@endif
@empty
    <li>No hay inicios de pago personalizados pendientes.</li>
@endforelse
</ul>
<x-housekeeping.pagination :paginator="$customIntents"/>
<h2>Pedidos históricos sin preferencia registrada</h2>
<ul>
@foreach($legacyOrders as $legacy)
    <li><a href="{{ route('housekeeping.payments.show', $legacy) }}">{{ $legacy->number }}</a> · Requiere consulta y evidencia; no se permite una nueva emisión.</li>
@endforeach
</ul>
<x-housekeeping.pagination :paginator="$legacyOrders"/>
<h2>Observaciones sin atribución comercial confirmada</h2>
<p>Estas evidencias no acreditan un pedido ni habilitan preparación o despacho. Investiga su identidad antes de asociarlas.</p>
<ul>
@forelse($quarantined as $event)
    <li>{{ $event->created_at }} · Pago {{ $event->provider_payment_id }} · {{ $event->outcome }} · {{ $event->provider_status }}
        @if($event->note)<pre style="max-width:100%;overflow:auto">{{ $event->note }}</pre>@endif
    </li>
@empty
    <li>No hay observaciones sin atribuir.</li>
@endforelse
</ul>
<x-housekeeping.pagination :paginator="$quarantined"/>
@endsection
