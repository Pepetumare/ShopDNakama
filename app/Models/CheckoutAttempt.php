<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutAttempt extends Model
{
    public const DRAFT = 'draft';
    public const SENDING = 'sending';
    public const UNCERTAIN = 'reconciliation_required';
    public const READY = 'ready';
    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lookup_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
