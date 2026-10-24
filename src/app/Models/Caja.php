<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    use BelongsToRestaurante;

    protected $fillable = ['restaurante_id', 'user_id', 'saldo_inicial', 'efectivo_contado', 'efectivo_esperado', 'diferencia', 'cerrada_at'];

    protected function casts(): array
    {
        return ['saldo_inicial' => 'decimal:2', 'efectivo_contado' => 'decimal:2', 'efectivo_esperado' => 'decimal:2', 'diferencia' => 'decimal:2', 'cerrada_at' => 'datetime'];
    }
}
