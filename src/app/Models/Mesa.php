<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Mesa extends Model
{
    use BelongsToRestaurante;

    protected $fillable = ['nombre', 'nombre_key', 'zona', 'activa', 'codigo', 'capacidad'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'capacidad' => 'integer'];
    }
}
