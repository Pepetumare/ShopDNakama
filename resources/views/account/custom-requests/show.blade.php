@extends('account.layout', ['accountTitle' => 'Mi personalizado'])
@section('account-content')
<x-custom-requests.details :custom-request="$customRequest"/>
<x-custom-requests.saved-preview :custom-request="$customRequest" :admin="false"/>
<x-custom-requests.design-assistance :custom-request="$customRequest"/>
@if($customRequest->status !== 'quoted')<x-custom-requests.quote-breakdown :custom-request="$customRequest"/>@endif
<x-custom-requests.payment-status :custom-request="$customRequest"/>
@if($customRequest->status === 'quoted' && (float) $customRequest->quoted_price > 0)
    @php($shipping = $customRequest->order?->shipping_cost ?? max(0, (int) config('services.mercadopago.shipping_cost')))
    <h3>Aceptar cotización</h3>
    <dl class="account-order-details"><div><dt>Cotización base</dt><dd><x-store.price :amount="$customRequest->effective_base_quoted_price"/></dd></div>@if($customRequest->design_assistance)<div><dt>Diseño asistido</dt><dd><x-store.price :amount="$customRequest->design_assistance_fee"/></dd></div>@endif<div><dt>Despacho</dt><dd><x-store.price :amount="$shipping"/></dd></div><div><dt>Total a pagar</dt><dd><x-store.price :amount="(float) $customRequest->quoted_price + (float) $shipping"/></dd></div></dl>
    <form method="post" action="{{ route('account.custom-requests.accept', $customRequest) }}" class="checkout-fields">
        @csrf
        @unless($customRequest->order)
        <h3>Datos de despacho</h3>
        <label>Dirección<input name="shipping_address_line1" required maxlength="255" autocomplete="street-address" value="{{ old('shipping_address_line1') }}"></label>
        <label>Comuna / ciudad<input name="shipping_city" required maxlength="255" autocomplete="address-level2" value="{{ old('shipping_city') }}"></label>
        <label>Región<input name="shipping_region" required maxlength="255" autocomplete="address-level1" value="{{ old('shipping_region') }}"></label>
        <label>Teléfono (opcional)<input name="customer_phone" type="tel" maxlength="50" autocomplete="tel" value="{{ old('customer_phone', $customRequest->customer_phone) }}"></label>
        @endunless
        <p>El precio cotizado corresponde al lote completo de {{ $customRequest->quantity }} unidad(es). El pago se confirmará cuando Mercado Pago lo verifique.</p>
        <button class="shop-button" type="submit">Aceptar cotización y pagar</button>
    </form>
@endif
@if($customRequest->status === 'quoted')
    <form method="post" action="{{ route('account.custom-requests.decline', $customRequest) }}">@csrf<button class="shop-button green" type="submit">Rechazar cotización</button></form>
@endif
<p><a class="shop-button green" href="{{ route('account.custom-requests.index') }}">Volver a mis personalizados</a></p>
@endsection
