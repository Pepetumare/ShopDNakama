<?php

namespace App\Services;

use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockReservations
{
    // A locking read avoids stale MySQL snapshots after acquiring the product lock.
    public function held(Product $product, bool $lock = false): int
    {
        $query = DB::table('stock_reservations')->where('product_id', $product->id)
            ->whereIn('status', ['active', 'expired']);

        return $lock ? (int) $query->lockForUpdate()->get(['quantity'])->sum('quantity')
            : (int) $query->sum('quantity');
    }

    public function available(Product $product, bool $lock = false): int
    {
        return max(0, $product->stock - $this->held($product, $lock));
    }

    // Checkout already holds the product locks, and creates the order in the same transaction.
    public function reserve(Order $order, array $lines): void
    {
        $now = now();
        $expiresAt = $now->copy()->addMinutes(max(1, (int) config('inventory.reservation_minutes')));
        DB::table('stock_reservations')->insert(array_map(fn ($line) => [
            'order_id' => $order->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
            'status' => 'active', 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
        ], $lines));
    }

    public function rows(Order $order, bool $lock = false): Collection
    {
        return DB::table('stock_reservations')->where('order_id', $order->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('product_id');
    }

    public function isCurrent(object $row): bool
    {
        return $row->status === 'active' && now()->lt($row->expires_at);
    }

    public function consume(Order $order): void
    {
        DB::table('stock_reservations')->where('order_id', $order->id)->where('status', 'active')
            ->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
    }

    public function expire(): int
    {
        $expired = DB::transaction(fn () => DB::table('stock_reservations')->where('status', 'active')
            ->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]), 3);

        Order::whereNull('mercadopago_preference_id')->whereExists(function ($query) {
            $query->selectRaw('1')->from('stock_reservations')->whereColumn('order_id', 'orders.id')
                ->where('status', 'expired');
        })->whereNotIn('id', CheckoutAttempt::whereIn('state', [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN])
            ->whereNotNull('order_id')->select('order_id'))->eachById(fn (Order $order) => $this->releaseUnpublished($order));

        return $expired;
    }

    public function releaseUnpublished(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $mayHavePreference = CheckoutAttempt::where('order_id', $locked->id)
                ->whereIn('state', [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN])->exists();
            if (! $locked->mercadopago_preference_id && ! $mayHavePreference) {
                $this->release($locked, ['active', 'expired'], 'Preferencia no publicada');
            }
        }, 3);
    }

    public function releaseReconciled(Order $order, string $reason): int
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            return $this->release($locked, ['expired'], $reason);
        }, 3);
    }

    private function release(Order $order, array $states, string $reason): int
    {
        return DB::table('stock_reservations')->where('order_id', $order->id)->whereIn('status', $states)
            ->update(['status' => 'released', 'released_at' => now(), 'release_reason' => $reason, 'updated_at' => now()]);
    }
}
