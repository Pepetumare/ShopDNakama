@extends('layouts.store')
@section('title', 'Checkout | Shop D. Nakama')
@section('content')
<div class="shop-container cart-page">
<h1>Finalizar compra</h1>
@if($errors->any())<div role="alert" class="checkout-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="post" action="{{ route('checkout.store') }}" class="cart-layout checkout-form" x-data="{ sending: false }" @submit="if (sending) { $event.preventDefault() } else { sending = true }" @pageshow.window="sending = false">@csrf
<input type="hidden" name="attempt" value="{{ $attempt->token }}">
@if($lines === null)
<input type="hidden" name="resume" value="1">
<section class="checkout-fields"><h2>Ya tienes un pedido iniciado</h2>
<p>Recupera el mismo intento para continuar si la reserva sigue vigente.</p>
<a href="{{ route('account.orders.show', $attempt->order_id) }}">Ver pedido</a></section>
<aside class="cart-totals">
<button class="shop-button red" type="submit" :disabled="sending" x-text="sending ? 'Verificando…' : 'Recuperar pago'">Recuperar pago</button>
</aside>
@else
<section class="checkout-fields"><h2>Datos del cliente</h2>
@foreach(['customer_name' => ['Nombre', auth()->user()->name], 'customer_email' => ['Email', auth()->user()->email], 'customer_phone' => ['Teléfono', '']] as $field => [$label, $value])
<label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $field === 'customer_email' ? 'email' : ($field === 'customer_phone' ? 'tel' : 'text') }}" value="{{ old($field, $value) }}" maxlength="{{ $field === 'customer_phone' ? 50 : 255 }}" required>
@endforeach
<h2>Entrega</h2><label for="delivery_type">Tipo de entrega</label><select id="delivery_type" name="delivery_type"><option value="despacho">Despacho a domicilio</option></select>
@foreach(['shipping_address_line1' => 'Dirección', 'shipping_city' => 'Comuna', 'shipping_region' => 'Región'] as $field => $label)
<label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" maxlength="255" required>
@endforeach
</section><aside class="cart-totals"><h2>Resumen de tu pedido</h2>
@foreach($lines as $line)<p>{{ $line['product']->name }} × {{ $line['quantity'] }} <strong><x-store.price :amount="$line['subtotal']"/></strong></p>@endforeach
<dl><div><dt>Subtotal</dt><dd><x-store.price :amount="$subtotal"/></dd></div><div><dt>Despacho</dt><dd><x-store.price :amount="$shipping"/></dd></div><div class="cart-total"><dt>Total</dt><dd><x-store.price :amount="$subtotal + $shipping"/></dd></div></dl>
<p>Te redirigiremos a Mercado Pago para completar el pago.</p><button class="shop-button red" type="submit" :disabled="sending" x-text="sending ? 'Procesando…' : 'Pagar con Mercado Pago'">Pagar con Mercado Pago</button>
</aside>
@endif
</form></div>
@endsection
