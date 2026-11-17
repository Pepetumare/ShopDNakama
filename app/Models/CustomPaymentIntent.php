<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CustomPaymentIntent extends Model
{
    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'reconciliation_required';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'legacy' => 'boolean', 'sending_at' => 'datetime', 'lookup_at' => 'datetime',
            'financial_review_required' => 'boolean', 'financial_reviewed_at' => 'datetime', 'financial_next_review_at' => 'datetime'];
    }

    // Callers hold request -> order -> intent locks. Financial states never change here.
    public function transition(string $state): void
    {
        $allowed = match ($this->state) {
            self::DRAFT, self::FAILED => [self::SENDING],
            self::SENDING => [self::READY, self::FAILED, self::UNCERTAIN],
            self::UNCERTAIN => [self::READY],
            self::READY => [],
            default => [],
        };
        if ($state !== $this->state && ! in_array($state, $allowed, true)) {
            throw new LogicException('Invalid custom payment intent transition');
        }
        $this->state = $state;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomPaymentIntentEvent::class);
    }
}
