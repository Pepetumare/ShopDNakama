@extends('housekeeping.layout')
@section('title', 'Conciliación del pedido')
@section('content')
<h1>Conciliación del pedido {{ $order->number }}</h1>
<p>{{ $order->customer_name }} · {{ $order->payment_status_label }}</p>
<p><a href="{{ route('housekeeping.orders.show', $order) }}">Volver al pedido</a></p>
@if($order->fulfillment_issue)
    <p>{{ $order->fulfillment_issue }}</p>
    <form method="post" action="{{ route('housekeeping.orders.resolve-fulfillment', $order) }}">@csrf
        <label for="fulfillment-note">Motivo y evidencia de resolución de preparación</label>
        <textarea id="fulfillment-note" name="note" required maxlength="1000"></textarea>
        <button type="submit">Verificar y resolver bloqueo de preparación</button>
    </form>
@endif
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
@if($order->custom_request_id)
<section>
    <h2>Inicio de pago personalizado</h2>
    <p>Estado {{ $intent?->state ?? 'histórico sin intención registrada' }} · Inicio {{ $intent?->sending_at ?? 'sin evidencia' }} · Última consulta {{ $intent?->lookup_at ?? 'pendiente' }}</p>
    <p>Resultado {{ $intent?->outcome ?? 'sin consulta' }} · Preferencia {{ $intent?->preference_id ?? 'sin identificar' }}</p>
    <p>Conciliación financiera {{ $intent?->financial_review_required ? 'pendiente' : 'sin trabajo pendiente registrado' }} · Resultado {{ $intent?->financial_review_outcome ?? 'sin consulta' }} · Último intento {{ $intent?->financial_reviewed_at ?? 'pendiente' }} · Próxima revisión {{ $intent?->financial_next_review_at ?? 'disponible' }}</p>
    <p>La consulta de Mercado Pago solo realiza lecturas. Una búsqueda vacía no confirma que la preferencia no exista.</p>
    <form method="post" action="{{ route('housekeeping.payments.custom-intent', $order) }}">@csrf
        <label for="intent-note">Motivo y evidencia de la revisión</label>
        <textarea id="intent-note" name="note" required maxlength="1000"></textarea>
        <label for="intent-preference">ID de preferencia respaldado por evidencia (opcional)</label>
        <input id="intent-preference" name="preference_id" maxlength="255">
        <label><input type="checkbox" name="verified" value="1"> Verifiqué la procedencia del ID y su relación con este pedido</label>
        <button type="submit">Registrar evidencia y verificar ID</button>
    </form>
    <ul>
    @foreach($intentEvents ?? [] as $event)
        <li>{{ $event->created_at }} · {{ $event->source }} · {{ $event->outcome }} · Usuario {{ $event->actor_id ?? 'sistema' }}
            @if($event->note)<p>{{ $event->note }}</p>@endif
            @if($event->evidence)<details><summary>Ver evidencias de consulta</summary><pre style="max-width:100%;overflow:auto">{{ json_encode($event->evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></details>@endif
        </li>
    @endforeach
    </ul>
    @if($intentEvents)<x-housekeeping.pagination :paginator="$intentEvents"/>@endif
</section>
@endif
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
        @if($event->provider_date_evidence)
            <details><summary>Fechas del proveedor que requieren revisión</summary><pre>{{ json_encode($event->provider_date_evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
        @endif
        @if($event->note)<p>{{ $event->note }}</p>@endif
    </li>
@empty
    <li>Sin comprobaciones u observaciones registradas.</li>
@endforelse
</ul>
<x-housekeeping.pagination :paginator="$events"/>
@endsection
