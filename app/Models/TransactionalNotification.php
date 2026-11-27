<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class TransactionalNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function setPayloadAttribute(?array $value): void
    {
        $this->attributes['payload'] = $value === null
            ? null
            : json_encode(Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    }

    public function getPayloadAttribute(?string $value): ?array
    {
        return $value === null
            ? null
            : json_decode(Crypt::decryptString(json_decode($value, true, flags: JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionalNotificationEvent::class, 'notification_id')->latest();
    }
}
