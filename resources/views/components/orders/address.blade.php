@props(['order'])
@php($lines = collect(['shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country'])->map(fn ($field) => data_get($order, $field))->filter())
@if($lines->isNotEmpty())
<address class="order-address">@foreach($lines as $line)<span>{{ $line }}</span>@endforeach</address>
@else
<p>Dirección no registrada.</p>
@endif
