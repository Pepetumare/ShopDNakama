@props(['customRequest'])
@if($customRequest->quoted_price !== null)
<dl class="account-order-details">
    <div><dt>Cotización base del lote</dt><dd><x-store.price :amount="$customRequest->effective_base_quoted_price"/></dd></div>
    @if($customRequest->design_assistance)<div><dt>Diseño asistido</dt><dd><x-store.price :amount="$customRequest->design_assistance_fee"/></dd></div>@endif
    <div><dt>Total cotizado</dt><dd><x-store.price :amount="$customRequest->quoted_price"/></dd></div>
</dl>
@endif
