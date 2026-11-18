<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomPaymentIntentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }
}
