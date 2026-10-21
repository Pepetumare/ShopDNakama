<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasSlug
{
    protected static function bootHasSlug(): void
    {
        static::creating(function (Model $model) {
            if (filled($model->slug)) {
                return;
            }

            $base = Str::slug($model->name) ?: 'item';
            $slug = $base;
            $suffix = 2;
            while (static::where('slug', $slug)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            $model->slug = $slug;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
