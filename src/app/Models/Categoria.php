<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRestaurante;
use App\Models\Concerns\LimpiaCacheCarta;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    use BelongsToRestaurante, LimpiaCacheCarta, Auditable;

    protected $fillable = ['restaurante_id', 'nombre', 'orden'];

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }
}