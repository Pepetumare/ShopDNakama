<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogPaymentWork extends Model
{
    protected $table = 'catalog_payment_work';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verification_version' => 'integer', 'attempts' => 'integer', 'failures' => 'integer',
            'last_http' => 'integer', 'last_attempt_at' => 'datetime',
            'last_verified_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
