<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Gasto extends Model
{
    use BelongsToRestaurante, Auditable;

    protected $fillable = [
        'restaurante_id', 'user_id', 'descripcion', 'categoria', 'monto', 'fecha',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date:Y-m-d',
        ];
    }
}