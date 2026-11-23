<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogPaymentCursor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'offset' => 'integer', 'scan_open' => 'boolean',
            'next_discovery_at' => 'datetime', 'last_attempt_at' => 'datetime',
            'next_run_at' => 'datetime', 'lease_expires_at' => 'datetime'];
    }
}
