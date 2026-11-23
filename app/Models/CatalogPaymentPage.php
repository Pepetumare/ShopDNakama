<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogPaymentPage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['targets' => 'array', 'last_checked_at' => 'datetime'];
    }
}
