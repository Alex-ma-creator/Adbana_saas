<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use BelongsToRestaurante;

    protected $fillable = [
        'restaurante_id', 'user_id', 'cliente_nombre', 'cliente_telefono',
        'tipo', 'mesa', 'direccion', 'estado', 'medio_pago', 'total', 'notas',
        'caja_id', 'recibido', 'vuelto', 'cobrado_at', 'clave_operacion',
    ];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'recibido' => 'decimal:2', 'vuelto' => 'decimal:2', 'cobrado_at' => 'datetime'];
    }

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class);
    }
}