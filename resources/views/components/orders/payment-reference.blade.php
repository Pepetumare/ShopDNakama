@props(['order'])
@if($order->payment_method === 'mercadopago')
<dl class="account-order-details">
    <div><dt>Mercado Pago Payment ID</dt><dd>{{ $order->mercadopago_payment_id ?: 'Pendiente' }}</dd></div>
    @if($order->mercadopago_preference_id)<div><dt>Preference ID</dt><dd>{{ $order->mercadopago_preference_id }}</dd></div>@endif
</dl>
@endif
