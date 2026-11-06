@extends('housekeeping.layout')
@section('title', 'Conciliación del pedido')
@section('content')
<h1>Conciliación del pedido {{ $order->number }}</h1>
<p>{{ $order->customer_name }} · {{ $order->payment_status_label }}</p>
<p><a href="{{ route('housekeeping.orders.show', $order) }}">Volver al pedido</a></p>
@if($order->mercadopago_payment_id)
    <p>Pago asociado al pedido: {{ $order->mercadopago_payment_id }}</p>
@endif
<form method="post" action="{{ route('housekeeping.payments.review', $order) }}">@csrf
    <button type="submit">Consultar Mercado Pago</button>
</form>
@if(session('payment_next') !== null)
<form method="post" action="{{ route('housekeeping.payments.review', $order) }}">@csrf
    <input type="hidden" name="offset" value="{{ session('payment_next') }}">
    <button type="submit">Consultar siguiente página de pagos</button>
</form>
@endif

<h2>Pagos verificados</h2>
@forelse($payments as $payment)
    <section class="hk-card">
        <h3>Pago {{ $payment->provider_payment_id }}</h3>
        <p>{{ $payment->amount }} {{ $payment->currency }} · {{ $payment->provider_status }}</p>
        <p>Aprobación {{ $payment->approved_at ?? 'sin fecha informada' }} · Última verificación {{ $payment->last_verified_at }}</p>
        <p>Incidencia {{ $payment->incident ?? 'sin incidencia' }} · {{ $payment->resolved_at ? 'Resuelta el '.$payment->resolved_at : ($payment->incident ? 'Abierta' : 'Sin resolución requerida') }}</p>
        <form method="post" action="{{ route('housekeeping.payments.annotate', $payment) }}">@csrf
            <label for="note-{{ $payment->id }}">Observación o evidencia de resolución</label>
            <textarea id="note-{{ $payment->id }}" name="note" required maxlength="1000"></textarea>
            <p class="hk-help">Registra referencias de conciliación sin incluir datos de tarjetas ni credenciales.</p>
            <button name="action" value="observe">Registrar observación</button>
            @if($payment->incident && ! $payment->resolved_at)
                <label><input type="checkbox" name="verified" value="1"> Verifiqué la evidencia de resolución</label>
                <button name="action" value="resolve">Registrar resolución manual</button>
            @endif
        </form>
    </section>
@empty
    <p>El registro todavía no tiene comprobaciones para este pedido.</p>
@endforelse
<x-housekeeping.pagination :paginator="$payments"/>

<h2>Reservas relacionadas</h2>
<ul>
@forelse($stock as $row)
    <li>Producto {{ $row->product_id }} · {{ $row->quantity }} unidades · {{ $row->status }} · Vencimiento {{ $row->expires_at }}</li>
@empty
    <li>Sin reservas asociadas.</li>
@endforelse
</ul>

<h2>Historial de conciliación</h2>
<ul>
@forelse($events as $event)
    <li>{{ $event->created_at }} · {{ $event->source }} · {{ $event->outcome }}
        @if($event->provider_payment_id) · Pago {{ $event->provider_payment_id }} @endif
        @if($event->actor_id) · Usuario {{ $event->actor_id }} @endif
        @if($event->note)<p>{{ $event->note }}</p>@endif
    </li>
@empty
    <li>Sin comprobaciones u observaciones registradas.</li>
@endforelse
</ul>
<x-housekeeping.pagination :paginator="$events"/>
@endsection
