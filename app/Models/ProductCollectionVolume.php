<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCollectionVolume extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'volume_number', 'sort_order'];

    protected function casts(): array
    {
        return ['volume_number' => 'integer', 'sort_order' => 'integer'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ProductCollection::class, 'product_collection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
