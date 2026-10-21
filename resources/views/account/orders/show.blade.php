@extends('account.layout', ['accountTitle' => 'Pedido '.$order->number])
@section('account-content')
<dl class="account-order-details"><div><dt>Número de pedido</dt><dd>{{ $order->number }}</dd></div><div><dt>Fecha</dt><dd>{{ $order->created_at?->format('d/m/Y H:i') }}</dd></div><div><dt>Estado</dt><dd>{{ $order->status_label }}</dd></div><div><dt>Método de pago</dt><dd>{{ $order->payment_method_label }}</dd></div><div><dt>Estado de pago</dt><dd>{{ $order->payment_status_label }}</dd></div><div><dt>Cliente</dt><dd>{{ $order->customer_display_name }}</dd></div><div><dt>Correo</dt><dd>{{ $order->customer_display_email }}</dd></div><div><dt>Teléfono</dt><dd>{{ $order->customer_phone ?: 'No registrado' }}</dd></div></dl>
<h3>Dirección de envío</h3><x-orders.address :order="$order"/>
<h3>Productos</h3><x-orders.items :order="$order"/><x-orders.totals :order="$order"/>
<a class="shop-button green" href="{{ route('account.orders.index') }}">Volver a mis pedidos</a>
<x-orders.payment-reference :order="$order"/>
@endsection