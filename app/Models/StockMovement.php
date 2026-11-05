<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPES = ['receipt' => 'Entrada de mercadería', 'issue' => 'Salida manual', 'count' => 'Conteo físico', 'damage' => 'Merma o daño', 'replacement' => 'Reposición', 'return' => 'Devolución de pedido', 'sale' => 'Venta aprobada'];

    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
