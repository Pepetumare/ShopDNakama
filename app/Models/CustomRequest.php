<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomRequest extends Model
{
    public const PRODUCTS = ['mug' => 'Taza', 'shirt_white' => 'Polera blanca', 'shirt_black' => 'Polera negra', 'badges' => 'Chapitas', 'stickers' => 'Stickers', 'patch' => 'Parche personalizado', '3d_print' => 'Impresión 3D'];

    // Read/filter historical requests without rewriting their stored product type.
    public const LEGACY_PATCH_TYPES = ['prints'];

    public const PATCH_SHAPES = ['circular' => 'Circular', 'square' => 'Cuadrado', 'rectangular' => 'Rectangular', 'custom' => 'Personalizada', 'other' => 'Otra'];

    public const PIECE_TYPES = ['figure' => 'Figura', 'keychain' => 'Llavero', 'stand' => 'Soporte', 'accessory' => 'Accesorio', 'prototype' => 'Prototipo', 'replacement' => 'Pieza de reemplazo', 'decoration' => 'Decoración', 'other' => 'Otro'];

    public const USAGE_TYPES = ['decorative' => 'Decorativa', 'functional' => 'Funcional', 'unknown' => 'No estoy seguro'];

    public const PRINT_POSITIONS = ['Delantera', 'Trasera', 'Ambos lados'];

    public const STATUSES = ['pending' => 'Pendiente', 'reviewing' => 'En revisión', 'quoted' => 'Cotizado', 'approved' => 'Aprobado', 'in_production' => 'En producción', 'ready' => 'Listo', 'completed' => 'Completado', 'rejected' => 'Rechazado', 'cancelled' => 'Cancelado'];

    protected $guarded = ['id'];

    protected $hidden = ['admin_notes'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quoted_price' => 'decimal:2', 'base_quoted_price' => 'decimal:2', 'preview_data' => 'array', 'design_assistance' => 'boolean', 'design_assistance_fee' => 'integer'];
    }

    public static function supportsDesignAssistance(mixed $type): bool
    {
        return is_string($type) && $type !== '3d_print' && in_array($type, config('custom_orders.design_assistance_products', []), true);
    }

    public function getEffectiveBaseQuotedPriceAttribute(): ?string
    {
        // Existing quotes already contain their final amount; never add a fee on read.
        return $this->base_quoted_price ?? $this->quoted_price;
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $sequence = $request->getConnection()->table('custom_request_sequences')->insertGetId(['created_at' => now()]);
            $request->request_number = 'PER-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(CustomRequestFile::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function getReadyForProductionAttribute(): bool
    {
        return $this->status === 'approved' && in_array($this->order?->payment_status, ['paid', 'pagado'], true)
            && $this->order->status !== 'cancelado';
    }

    public function getProductLabelAttribute(): string
    {
        if ($this->is_patch) {
            return 'Parche personalizado';
        }
        if ($this->product_type === 'badges') {
            return 'Chapita personalizada';
        }

        return self::PRODUCTS[$this->product_type] ?? $this->product_type;
    }

    public function getIsPatchAttribute(): bool
    {
        return $this->product_type === 'patch' || in_array($this->product_type, self::LEGACY_PATCH_TYPES, true);
    }

    public function displayOrderDescription(?string $description): ?string
    {
        if (! $this->is_patch || $description === null) {
            return $description;
        }

        // Change the old generated label for display only, never the order snapshot or customer text.
        return preg_replace('/\AEstampados?(?: personalizados?)?(?=\s*(?:·|—|-|$))/iu', 'Parche personalizado', $description);
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->product_type === '3d_print') {
            if ($this->status === 'in_production') {
                return 'En impresión';
            }
            if ($this->status === 'ready') {
                return 'Listo para entrega';
            }
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }
}
