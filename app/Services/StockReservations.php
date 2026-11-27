<?php

namespace App\Services;

use App\Models\CatalogPaymentWork;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockReservations
{
    private const RELEASE_REASON_CAPACITY = 320;

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
        $now = now()->utc();
        $expiresAt = $now->copy()->addMinutes(max(1, (int) config('inventory.reservation_minutes')));
        FinancialDatabaseTime::transaction(fn () => DB::table('stock_reservations')->insert(array_map(fn ($line) => [
            'order_id' => $order->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
            'absolute_window' => true, 'status' => 'active', 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
        ], $lines)));
    }

    public function rows(Order $order, bool $lock = false): Collection
    {
        $query = DB::table('stock_reservations')->where('order_id', $order->id)->select('stock_reservations.*');
        $sqlZone = FinancialDatabaseTime::usesSqlTimeZone();
        if ($sqlZone) {
            $query->selectRaw('UNIX_TIMESTAMP(created_at) AS start_epoch, UNIX_TIMESTAMP(expires_at) AS end_epoch');
        }

        return $query->when($lock, fn ($query) => $query->lockForUpdate())->get()->each(function ($row) use ($sqlZone) {
            if ($row->created_at === null || ($sqlZone && ($row->start_epoch === null || $row->end_epoch === null))) {
                $row->absolute_window = false;
            }
            if ($row->absolute_window) {
                $row->created_at = $sqlZone ? Carbon::createFromTimestampUTC($row->start_epoch)->toIso8601String()
                    : Carbon::parse($row->created_at, 'UTC')->toIso8601String();
                $row->expires_at = $sqlZone ? Carbon::createFromTimestampUTC($row->end_epoch)->toIso8601String()
                    : Carbon::parse($row->expires_at, 'UTC')->toIso8601String();
            }
        })->keyBy('product_id');
    }

    public function isCurrent(object $row): bool
    {
        return $row->absolute_window && $row->status === 'active' && now()->gte($row->created_at) && now()->lt($row->expires_at);
    }

    public function consume(Order $order): void
    {
        DB::table('stock_reservations')->where('order_id', $order->id)->whereIn('status', ['active', 'expired'])
            ->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
    }

    public function expire(): int
    {
        $expired = FinancialDatabaseTime::transaction(fn () => DB::table('stock_reservations')->where('status', 'active')
            ->where('expires_at', '<=', now()->utc())->update(['status' => 'expired', 'updated_at' => now()->utc()]));

        Order::whereIn('id', CheckoutAttempt::where('state', CheckoutAttempt::NOT_SENT)->select('order_id'))
            ->whereExists(fn ($rows) => $rows->selectRaw('1')->from('stock_reservations')
                ->whereColumn('order_id', 'orders.id')->where('status', 'expired'))
            ->eachById(fn (Order $order) => $this->recover($order));

        return $expired;
    }

    public function releaseUnpublished(Order $order): void
    {
        $this->recover($order, immediate: true);
    }

    // Checkout locks the attempt before the order. Payment and discovery writers also lock the order.
    public function recover(Order $order, string $note = '', bool $immediate = false): array
    {
        return DB::transaction(function () use ($order, $note, $immediate) {
            $attempt = CheckoutAttempt::where('order_id', $order->id)->lockForUpdate()->first();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $states = $immediate ? ['active', 'expired'] : ['expired'];
            $rows = DB::table('stock_reservations')->where('order_id', $locked->id)->whereIn('status', $states);
            if (! (clone $rows)->exists()) {
                return ['released' => 0, 'hold' => null];
            }
            $hold = $this->holdReason($locked, $attempt);
            if ($hold !== null) {
                return ['released' => 0, 'hold' => $hold];
            }
            $reason = trim("Preferencia no enviada (intento {$attempt->id}). ".$note);

            if (mb_strlen($reason, 'UTF-8') > self::RELEASE_REASON_CAPACITY) {
                return ['released' => 0, 'hold' => 'El registro completo supera los 320 caracteres. Acorta el motivo y conserva la referencia.'];
            }

            return ['released' => $rows->update(['status' => 'released', 'released_at' => now(),
                'release_reason' => $reason, 'updated_at' => now()]), 'hold' => null];
        }, 3);
    }

    public function holdReason(Order $order, ?CheckoutAttempt $attempt): ?string
    {
        if ($order->custom_request_id || $order->payment_method !== 'mercadopago') {
            return 'Flujo sin evidencia de no publicación del checkout de catálogo.';
        }
        if ($order->fulfillment_issue || $order->mercadopago_payment_id || $order->stock_deducted_at
            || ! in_array($order->payment_status, ['pending', 'pendiente'], true)
            || $order->paymentTransactions()->exists()) {
            return 'Pedido con pago observado, movimiento de inventario o incidencia financiera.';
        }
        if (CatalogPaymentWork::where('order_id', $order->id)->exists()
            || DB::table('payment_reconciliation_events')->where('order_id', $order->id)->whereNotNull('provider_payment_id')->exists()) {
            return 'Conciliación con IDs de pago descubiertos.';
        }
        if ($order->mercadopago_preference_id || $order->mercadopago_checkout_url || $attempt?->state === CheckoutAttempt::READY) {
            return 'Preferencia publicada. Pueden existir otros pagos.';
        }
        if ($order->catalogPaymentCursor()->where(fn ($query) => $query->where('scan_open', true)
            ->orWhereNotNull('lease_token')->orWhere('search_failures', '>', 0))->exists()
            || DB::table('catalog_payment_pages')->where('order_id', $order->id)->where('state', 'pending')->exists()) {
            return 'Conciliación incompleta o en curso.';
        }
        if ($attempt?->state !== CheckoutAttempt::NOT_SENT) {
            return 'Sin prueba de fallo anterior al envío. Mantener la reserva y conciliar.';
        }

        return null;
    }
}
