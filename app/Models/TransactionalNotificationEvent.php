<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionalNotificationEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(TransactionalNotification::class, 'notification_id');
    }
}
