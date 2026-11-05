<div class="hk-panel hk-table-wrap"><table class="hk-table"><caption class="hk-sr-only">Historial de movimientos de stock</caption><thead><tr><th>Fecha</th><th>Producto</th><th>Movimiento</th><th>Cambio</th><th>Anterior</th><th>Posterior</th><th>Responsable</th><th>Motivo / pedido</th></tr></thead><tbody>
@forelse($movements as $movement)
<tr><td>{{ $movement->created_at->format('d/m/Y H:i:s') }}</td><td>{{ $movement->product?->name ?? $product->name }}</td><td>{{ \App\Models\StockMovement::TYPES[$movement->type] ?? $movement->type }}</td><td>{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td><td>{{ $movement->before }}</td><td><strong>{{ $movement->after }}</strong></td><td>{{ $movement->user?->name ?? ($movement->type === 'sale' ? 'Sistema · Mercado Pago' : 'Usuario eliminado') }}</td><td>{{ $movement->reason }}@if($movement->order)<small class="hk-cell-note"><a href="{{ route('housekeeping.orders.show', $movement->order) }}">{{ $movement->order->number }}</a></small>@endif</td></tr>
@empty<tr><td colspan="8" class="hk-empty">No hay movimientos registrados.</td></tr>@endforelse
</tbody></table></div>
<x-housekeeping.pagination :paginator="$movements"/>
