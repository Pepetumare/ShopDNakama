@props(['order'])
<div class="order-items-wrap"><table class="order-items"><caption class="order-sr-only">Productos del pedido {{ $order->number }}</caption><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Subtotal</th></tr></thead><tbody>
@forelse($order->items as $item)
    <tr><td><strong>{{ $item->product_name }}</strong>@if($item->sku)<small>SKU: {{ $item->sku }}</small>@endif</td><td>{{ $item->quantity }}</td><td><x-store.price :amount="$item->unit_price"/></td><td><x-store.price :amount="$item->line_total"/></td></tr>
@empty
    <tr><td colspan="4">Este pedido no tiene productos registrados.</td></tr>
@endforelse
</tbody></table></div>
