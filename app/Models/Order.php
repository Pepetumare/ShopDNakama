<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    public const STATUSES = ['pending' => 'Pendiente', 'pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'preparando' => 'Preparando', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];

    public const PAYMENT_STATUSES = ['pending' => 'Pendiente de pago', 'paid' => 'Pagado', 'rejected' => 'Rechazado', 'refunded' => 'Reembolsado', 'cancelled' => 'Cancelado', 'pendiente' => 'Pendiente de pago', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado', 'reembolsado' => 'Reembolsado'];

    protected $fillable = ['number', 'user_id', 'customer_name', 'customer_email', 'customer_phone', 'shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country', 'status', 'payment_method', 'payment_status', 'subtotal', 'shipping_cost', 'discount_total', 'total'];

    protected $attributes = ['status' => 'pendiente', 'payment_status' => 'pendiente'];

    public function getPaymentMethodLabelAttribute(): string
    {
        return $this->payment_method === 'mercadopago' ? 'Mercado Pago' : ($this->payment_method ?: 'No registrado');
    }

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'subtotal' => 'decimal:2', 'shipping_cost' => 'decimal:2', 'discount_total' => 'decimal:2', 'total' => 'decimal:2'];
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
