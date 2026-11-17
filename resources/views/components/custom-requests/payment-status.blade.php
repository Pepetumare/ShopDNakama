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
        @php($intent = $order->customPaymentIntent)
        @if(in_array($intent?->state, ['sending', 'reconciliation_required'], true) || (! $intent && ! $order->mercadopago_preference_id) || $order->hasCustomPaymentHold())
            @if(in_array($intent?->outcome, ['multiple_candidates', 'candidate_mismatch', 'outside_search_window', 'association_conflict', 'order_snapshot_changed', 'candidate_conflict', 'payment_candidate_conflict'], true) || $intent?->sending_at?->lt(now()->subDay()))
                <p role="status">El inicio de tu pago requiere asistencia. Contacta a la tienda indicando el número de pedido.</p>
            @else
                <p role="status">Estamos verificando el inicio de tu pago. No necesitas intentarlo nuevamente por ahora.</p>
            @endif
        @elseif($intent?->state === 'failed')
            <p role="status">No se inició el pago. Puedes volver a intentarlo o contactar a la tienda.</p>
        @elseif(($intent?->state === 'ready' || $order->mercadopago_preference_id) && in_array($order->status, ['pending', 'pendiente'], true) && $customRequest->status === 'quoted')
            <p role="status">El pago está listo para continuar. Aún no hay un pago confirmado.</p>
        @endif
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
