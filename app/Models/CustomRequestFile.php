<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomRequestFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['stored_path'];

    public function customRequest(): BelongsTo
    {
        return $this->belongsTo(CustomRequest::class);
    }

    public function getIsImageAttribute(): bool
    {
        return $this->file_type === 'reference' && in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
