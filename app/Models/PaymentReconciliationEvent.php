<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider_date_evidence' => 'array'];
    }
}
