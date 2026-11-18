<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    public function scopeBlocksCustomCheckout(Builder $query): Builder
    {
        return $query->where(fn (Builder $held) => $held
            ->where(fn (Builder $incident) => $incident->whereNotNull('incident')->whereNull('resolved_at'))
            ->orWhereNotNull('approved_at')->orWhere('incident', 'approval_regression')
            ->orWhereNotIn('provider_status', ['rejected', 'cancelled']));
    }

    public function scopeUnappliedCustomApproval(Builder $query): Builder
    {
        return $query->whereNotNull('custom_payment_intent_id')->where('provider_status', 'approved')
            ->whereHas('order', fn (Builder $order) => $order
                ->where(fn (Builder $pending) => $pending->whereNull('mercadopago_payment_id')
                    ->orWhere(fn (Builder $secondary) => $secondary->whereColumn('orders.mercadopago_payment_id', '!=', 'payment_transactions.provider_payment_id')->whereNull('payment_transactions.resolved_at'))
                    ->orWhereNotIn('payment_status', ['paid', 'pagado', 'refunded', 'reembolsado'])));
    }

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'approved_at' => 'datetime', 'provider_updated_at' => 'datetime',
            'last_verified_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customPaymentIntent(): BelongsTo
    {
        return $this->belongsTo(CustomPaymentIntent::class);
    }
}
