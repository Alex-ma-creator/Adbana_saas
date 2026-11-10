<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receta extends Model
{
    use BelongsToRestaurante;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'insumo_id');
    }
}
