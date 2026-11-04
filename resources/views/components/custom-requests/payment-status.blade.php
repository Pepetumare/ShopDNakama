@props(['customRequest', 'admin' => false])
@if($customRequest->order)
    @php($order = $customRequest->order)
    <h3>Pedido asociado</h3>
    @if(in_array($order->payment_status, ['paid', 'pagado'], true))
        <p role="status"><strong>Pago confirmado</strong></p>
        @if($customRequest->ready_for_production)<p class="{{ $admin ? 'hk-notice' : 'account-notice' }}">Pagado — listo para producción</p>@endif
    @elseif(in_array($order->payment_status, ['refunded', 'reembolsado'], true))
        <p>Pago reembolsado. Contacta a la tienda para revisar la solicitud.</p>
    @else
        <p>Cotización pendiente de pago</p>
    @endif
    <dl class="account-order-details">
        <div><dt>Número de pedido</dt><dd>{{ $order->number }}</dd></div>
        <div><dt>Estado de pago</dt><dd>{{ $order->payment_status_label }}</dd></div>
        <div><dt>Mercado Pago Payment ID</dt><dd>{{ $order->mercadopago_payment_id ?: 'Pendiente' }}</dd></div>
    </dl>
    <p><a href="{{ route($admin ? 'housekeeping.orders.show' : 'account.orders.show', $order) }}">Ver pedido asociado</a></p>
    @if($order->fulfillment_issue)<p role="alert">{{ $order->fulfillment_issue }}</p>@endif
@elseif($customRequest->status === 'quoted')
    <p>Cotización pendiente de pago</p>
@endif
