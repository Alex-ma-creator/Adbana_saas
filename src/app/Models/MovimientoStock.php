<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class MovimientoStock extends Model
{
    use BelongsToRestaurante;

    protected $table = 'movimientos_stock';

    protected $fillable = [
        'restaurante_id', 'producto_id', 'user_id', 'pedido_id',
        'tipo', 'cantidad', 'motivo',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}