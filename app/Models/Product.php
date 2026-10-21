<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasSlug;

    // The same expression is used to display, filter and order the current price.
    public const EFFECTIVE_PRICE_SQL = 'CASE WHEN sale_price IS NOT NULL AND sale_price >= 0 AND sale_price < price THEN sale_price ELSE price END';

    protected $fillable = ['category_id', 'name', 'slug', 'sku', 'short_description', 'description', 'price', 'sale_price', 'stock', 'is_active', 'is_featured', 'is_new', 'is_customizable', 'product_type'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'sale_price' => 'decimal:2', 'stock' => 'integer', 'is_active' => 'boolean', 'is_featured' => 'boolean', 'is_new' => 'boolean', 'is_customizable' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    public function scopeWithFavoriteState(Builder $query, ?int $userId): Builder
    {
        return $userId === null ? $query : $query->withExists([
            'favoritedBy as is_favorite' => fn (Builder $users) => $users->whereKey($userId),
        ]);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('category', fn (Builder $category) => $category->active());
    }

    public function scopeStorefront(Builder $query): Builder
    {
        // Optional customization on a physical product does not make it a service.
        return $query->visible()
            ->whereHas('category', fn (Builder $category) => $category->storefront())
            ->where(fn (Builder $types) => $types->whereNull('product_type')->orWhereNotIn(DB::raw('LOWER(TRIM(product_type))'), [
                'servicio', 'servicios', 'service', 'personalizable', 'personalizado',
                'servicio personalizado', 'servicios personalizados', 'custom', 'customizable',
                'impresión 3d', 'impresion 3d', 'estampados', 'estampados personalizados',
            ]));
    }

    public function scopeStoreFilters(Builder $query, array $filters): Builder
    {
        $category = $filters['categoria'] ?? '';
        if ($category === 'ofertas') {
            $query->whereNotNull('sale_price')->where('sale_price', '>=', 0)->whereColumn('sale_price', '<', 'price');
        } elseif ($category !== '') {
            $query->whereHas('category', fn (Builder $query) => $query->where('slug', $category));
        }
        if (filled($filters['q'] ?? null)) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        match ($filters['precio'] ?? '') {
            'menos-10000' => $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' < ?', [10000]),
            '10000-30000' => $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' >= ? AND '.self::EFFECTIVE_PRICE_SQL.' < ?', [10000, 30000]),
            '30000-50000' => $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' >= ? AND '.self::EFFECTIVE_PRICE_SQL.' <= ?', [30000, 50000]),
            'mas-50000' => $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' > ?', [50000]),
            default => null,
        };
        match ($filters['disponibilidad'] ?? '') {
            'stock' => $query->where('stock', '>', 0),
            'agotado' => $query->where('stock', 0),
            default => null,
        };

        return $query;
    }

    public function getOnSaleAttribute(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price >= 0 && (float) $this->sale_price < (float) $this->price;
    }

    public function getCurrentPriceAttribute(): string
    {
        return $this->on_sale ? $this->sale_price : $this->price;
    }

    public function getBadgeAttribute(): ?string
    {
        return $this->on_sale ? 'Oferta' : ($this->is_new ? 'Nuevo' : null);
    }

    public function getStockLabelAttribute(): string
    {
        return match (true) {
            $this->stock > 5 => 'En stock',
            $this->stock > 0 => 'Últimas unidades',
            default => 'Agotado',
        };
    }
}
