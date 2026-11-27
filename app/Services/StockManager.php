<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockManager
{
    public function __construct(private StockReservations $reservations) {}

    public function move(Product $product, array $data, int $userId): void
    {
        DB::transaction(function () use ($product, $data, $userId) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $key = 'manual:'.$data['token'];
            $existing = StockMovement::where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->product_id === $locked->id && $existing->user_id === $userId, 409);

                return;
            }
            if ((int) $locked->stock_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['quantity' => 'El inventario cambió. Recarga la página y verifica la cantidad antes de continuar.']);
            }
            $after = match ($data['type']) {
                'count' => (int) $data['quantity'],
                'issue', 'damage' => $locked->stock - (int) $data['quantity'],
                default => $locked->stock + (int) $data['quantity'],
            };
            if ($after < $this->reservations->held($locked, lock: true)) {
                throw ValidationException::withMessages(['quantity' => 'El movimiento dejaría sin stock a reservas pendientes.']);
            }
            $this->record($locked, $after, $data['type'], $data['reason'], $key, $userId);
        }, 3);
    }

    // The webhook holds the order lock; every product is locked in stable ID order.
    public function deductOrder(Order $order, ?Carbon $approvedAt = null): bool
    {
        if ($order->stock_deducted_at) {
            return true;
        }
        if (! in_array($order->payment_status, ['paid', 'pagado'], true) || $order->status === 'cancelado') {
            return false;
        }
        $items = $order->items()->get();
        $physical = $items->whereNotNull('product_id')->groupBy('product_id');
        if ((! $order->custom_request_id && $physical->count() === 0) || (! $order->custom_request_id && $items->contains('product_id', null))) {
            return false;
        }
        $products = Product::whereKey($physical->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $reservations = $this->reservations->rows($order, lock: true);
        if ($reservations->isNotEmpty() && $reservations->count() !== $physical->count()) {
            return false;
        }
        foreach ($physical as $id => $lines) {
            $quantity = $lines->sum('quantity');
            if (! $products->has($id) || $lines->contains(fn ($line) => $line->quantity < 1) || $products[$id]->stock < $quantity) {
                return false;
            }
            if ($reservations->isEmpty() && $this->reservations->available($products[$id], lock: true) < $quantity) {
                return false;
            }
            if ($reservations->isNotEmpty()) {
                $row = $reservations->get($id);
                if (! $row || (int) $row->quantity !== $quantity) {
                    return false;
                }
                $approvedWhileHeld = $row->absolute_window && in_array($row->status, ['active', 'expired'], true)
                    && $approvedAt && $approvedAt->gte($row->created_at) && $approvedAt->lt($row->expires_at)
                    && $approvedAt->lte(now());
                if (! $this->reservations->isCurrent($row) && ! $approvedWhileHeld) {
                    return false;
                }
                $otherHolds = $this->reservations->held($products[$id], lock: true) - $quantity;
                if ($products[$id]->stock - $quantity < $otherHolds) {
                    return false;
                }
            }
        }
        foreach ($physical as $id => $lines) {
            $this->record($products[$id], $products[$id]->stock - $lines->sum('quantity'), 'sale', 'Venta aprobada por Mercado Pago: '.$order->number, 'sale:'.$order->id.':'.$id, null, $order->id);
        }
        if ($physical->isNotEmpty()) {
            $order->stock_deducted_at = now();
            if ($reservations->isNotEmpty()) {
                $this->reservations->consume($order);
            }
        }

        return true;
    }

    public function restore(Order $order, int $userId, string $reason): void
    {
        DB::transaction(function () use ($order, $userId, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->stock_restored_at) {
                return;
            }
            if (! $order->stock_deducted_at || ! in_array($order->payment_status, ['refunded', 'reembolsado'], true)) {
                throw ValidationException::withMessages(['order_id' => 'Solo se puede reponer un pedido reembolsado cuyo stock fue descontado.']);
            }
            $sales = StockMovement::where('order_id', $order->id)->where('type', 'sale')->get();
            if ($sales->isEmpty() && ! $order->custom_request_id && $order->items()->whereNull('product_id')->exists()) {
                throw ValidationException::withMessages(['order_id' => 'El pedido contiene productos eliminados. Revisa la devolución antes de reponer.']);
            }
            // Historical orders have no movement ledger: use their immutable order lines.
            $quantities = $sales->isNotEmpty()
                ? $sales->mapWithKeys(fn ($sale) => [$sale->product_id => -$sale->quantity])
                : $order->items()->whereNotNull('product_id')->get()->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'));
            if ($quantities->isEmpty()) {
                throw ValidationException::withMessages(['order_id' => 'El pedido no contiene productos físicos para reponer.']);
            }
            $products = Product::whereKey($quantities->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($quantities as $id => $quantity) {
                if (! $products->has($id) || $quantity < 1) {
                    throw ValidationException::withMessages(['order_id' => 'No se puede reponer un producto eliminado o una cantidad inválida.']);
                }
                $this->record($products[$id], $products[$id]->stock + $quantity, 'return', $reason, 'return:'.$order->id.':'.$id, $userId, $order->id);
            }
            $order->forceFill(['stock_restored_at' => now()])->save();
        }, 3);
    }

    private function record(Product $product, int $after, string $type, string $reason, string $key, ?int $userId, ?int $orderId = null): void
    {
        if ($after < 0 || $after > 2147483647) {
            throw ValidationException::withMessages(['quantity' => 'Stock insuficiente o cantidad fuera del límite permitido.']);
        }
        $before = $product->stock;
        $product->forceFill(['stock' => $after, 'stock_version' => $product->stock_version + 1])->save();
        StockMovement::create(['product_id' => $product->id, 'user_id' => $userId, 'order_id' => $orderId, 'type' => $type, 'quantity' => $after - $before, 'before' => $before, 'after' => $after, 'reason' => $reason, 'idempotency_key' => $key]);
    }
}
