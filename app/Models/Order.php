<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    public const STATUSES = ['pending' => 'Pendiente', 'pendiente' => 'Pendiente', 'confirmed' => 'Confirmado', 'confirmado' => 'Confirmado', 'preparando' => 'Preparando', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];

    public const PAYMENT_STATUSES = ['pending' => 'Pendiente de pago', 'paid' => 'Pagado', 'rejected' => 'Rechazado', 'refunded' => 'Reembolsado', 'cancelled' => 'Cancelado', 'pendiente' => 'Pendiente de pago', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado', 'reembolsado' => 'Reembolsado'];

    // The caller must hold the order row lock throughout validation and update.
    public function assertFulfillable(): void
    {
        if (! in_array($this->payment_status, ['paid', 'pagado'], true)) {
            throw ValidationException::withMessages(['status' => 'El pedido requiere un pago confirmado y vigente.']);
        }
        if ($this->status === 'cancelado' || filled($this->fulfillment_issue)
            || $this->paymentTransactions()->whereNotNull('incident')->whereNull('resolved_at')->lockForUpdate()->get()->isNotEmpty()) {
            throw ValidationException::withMessages(['status' => 'Resuelve la cancelación o incidencia del pedido antes de continuar.']);
        }
        $items = $this->items()->get();
        $requiresStock = ! $this->custom_request_id || $items->whereNotNull('product_id')->isNotEmpty();
        if ($items->isEmpty() || ($requiresStock && (! $this->stock_deducted_at || $this->stock_restored_at))) {
            throw ValidationException::withMessages(['status' => 'El pedido requiere stock asignado y sin devolución antes de continuar.']);
        }
    }

    protected $fillable = ['number', 'user_id', 'customer_name', 'customer_email', 'customer_phone', 'shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country', 'status', 'payment_method', 'payment_status', 'subtotal', 'shipping_cost', 'discount_total', 'total'];

    protected $attributes = ['status' => 'pendiente', 'payment_status' => 'pendiente'];

    public function getPaymentMethodLabelAttribute(): string
    {
        return $this->payment_method === 'mercadopago' ? 'Mercado Pago' : ($this->payment_method ?: 'No registrado');
    }

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'custom_request_id' => 'integer', 'subtotal' => 'decimal:2', 'shipping_cost' => 'decimal:2', 'discount_total' => 'decimal:2', 'total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (! filled($order->number)) {
                $order->number = 'SDN-'.Str::ulid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function customPaymentIntent(): HasOne
    {
        return $this->hasOne(CustomPaymentIntent::class);
    }

    // For the final URL decision callers hold the order lock and lock the observed payments too.
    public function hasCustomPaymentHold(bool $lock = false): bool
    {
        if (! $this->custom_request_id) {
            return false;
        }
        $payments = $this->paymentTransactions()->blocksCustomCheckout()->orderBy('id');

        return $lock ? $payments->lockForUpdate()->get()->isNotEmpty() : $payments->exists();
    }

    public function customRequest(): BelongsTo
    {
        return $this->belongsTo(CustomRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereIn('payment_status', ['pagado', 'paid'])->where('status', '!=', 'cancelado');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? $this->payment_status;
    }

    public function getCustomerDisplayNameAttribute(): string
    {
        return $this->customer_name ?: ($this->user?->name ?? 'Cliente no disponible');
    }

    public function getCustomerDisplayEmailAttribute(): string
    {
        return $this->customer_email ?: ($this->user?->email ?? 'Correo no disponible');
    }
}
