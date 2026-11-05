@extends('housekeeping.layout', ['module' => 'pedidos'])
@section('title', 'Pedido '.$order->number.' | Housekeeping')
@section('content')
@if($order->customRequest)<p><a class="hk-secondary" href="{{ route('housekeeping.custom-requests.show', $order->customRequest) }}">Ver solicitud personalizada {{ $order->customRequest->request_number }}</a></p>@endif
<div class="hk-toolbar"><div class="hk-page-heading"><p>HOUSEKEEPING / PEDIDOS</p><h1>Pedido {{ $order->number }}</h1><span>{{ $order->created_at?->format('d/m/Y H:i') }}</span></div><a class="hk-secondary" href="{{ route('housekeeping.orders.index') }}">Volver a pedidos</a></div>
<div class="hk-detail-grid">
    <section class="hk-panel hk-detail-panel"><h2>Cliente</h2><dl class="hk-detail-list"><div><dt>Nombre</dt><dd>{{ $order->customer_display_name }}</dd></div><div><dt>Correo</dt><dd>{{ $order->customer_display_email }}</dd></div><div><dt>Teléfono</dt><dd>{{ $order->customer_phone ?: 'No registrado' }}</dd></div></dl>@if($order->user)<a class="hk-secondary" href="{{ route('housekeeping.customers.show', $order->user) }}">Ver cliente</a>@endif</section>
    <section class="hk-panel hk-detail-panel"><h2>Dirección de envío</h2><x-orders.address :order="$order"/></section>
    <section class="hk-panel hk-detail-panel hk-full"><h2>Productos</h2><x-orders.items :order="$order"/><x-orders.totals :order="$order"/></section>
    <section class="hk-panel hk-detail-panel"><h2>Pago</h2><dl class="hk-detail-list"><div><dt>Método</dt><dd>{{ $order->payment_method_label }}</dd></div><div><dt>Estado de pago</dt><dd>{{ $order->payment_status_label }}</dd></div></dl><p class="hk-help">Información registrada del pago. No se realizan cobros ni reembolsos desde este panel.</p></section>
    <section class="hk-panel hk-detail-panel"><h2>Estado del pedido</h2><form method="post" action="{{ route('housekeeping.orders.status', $order) }}" class="hk-status-form">@csrf @method('PATCH')<div class="hk-field"><label for="order-status">Estado</label><select id="order-status" name="status" required>@foreach(\App\Models\Order::STATUSES as $value => $label)<option value="{{ $value }}" @selected(old('status', $order->status) === $value)>{{ $label }}</option>@endforeach</select></div><button type="submit" class="hk-button">Guardar estado</button></form><p class="hk-help">Este cambio actualiza el seguimiento del pedido. No modifica pagos ni stock automáticamente.</p></section>
</div>
<x-orders.payment-reference :order="$order"/>
@if($order->stock_restored_at)
<p class="hk-notice">Los productos físicos de este pedido ya fueron repuestos al inventario.</p>
@elseif($order->stock_deducted_at && in_array($order->payment_status, ['refunded', 'reembolsado']))
<form class="hk-panel hk-form hk-status-form hk-stock-heading" method="post" action="{{ route('housekeeping.stock.restore') }}" @submit="if (!window.confirm('¿Confirmas que recibiste todos los productos físicos en condiciones de venta?')) $event.preventDefault()">
@csrf
<h2>Devolución al inventario</h2><input type="hidden" name="order_number" value="{{ $order->number }}">
<p>El reembolso no repone stock automáticamente. Registra la devolución cuando hayas recibido y revisado todos los productos físicos.</p>
<div class="hk-field"><label for="return-reason">Motivo de la devolución</label><textarea id="return-reason" name="reason" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea></div>
<label class="hk-checkbox"><input type="checkbox" name="received" value="1" required> Recibí todos los productos físicos y están aptos para venta.</label><button class="hk-button">Confirmar recepción y reponer stock</button></form>
@endif
@if($order->fulfillment_issue)<p role="alert" class="hk-panel">{{ $order->fulfillment_issue }}</p>@endif
@endsection
