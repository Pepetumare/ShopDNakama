<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCollection extends Model
{
    use HasSlug;

    protected $fillable = ['category_id', 'name', 'slug', 'description', 'image', 'is_published', 'sort_order', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(ProductCollectionVolume::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereHas('category', fn (Builder $category) => $category->storefront());
    }
}
