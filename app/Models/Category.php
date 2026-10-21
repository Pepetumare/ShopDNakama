<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasSlug;

    protected $fillable = ['name', 'slug', 'description', 'image', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeStorefront(Builder $query): Builder
    {
        return $query->active()->whereNotIn('slug', [
            'impresion-3d', 'estampados', 'estampados-personalizados',
            'personalizacion', 'productos-personalizables', 'productos-personalizados',
            'servicios', 'servicios-personalizados', 'cotizaciones',
            'tazas-personalizadas', 'tote-bags-personalizadas', 'stickers-personalizados',
        ]);
    }
}
