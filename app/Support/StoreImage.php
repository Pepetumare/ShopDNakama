<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class StoreImage
{
    public static function url(?string $path): string
    {
        if ($path && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! str_contains($path, ':') && ! str_contains($path, '\\') && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return asset('images/product-placeholder.svg');
    }
}
