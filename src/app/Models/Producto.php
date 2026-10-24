<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRestaurante;
use App\Models\Concerns\LimpiaCacheCarta;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use BelongsToRestaurante, LimpiaCacheCarta, Auditable;

    // El stock ya queda trazado en movimientos_stock.
    protected array $auditoriaExcluir = ['stock'];

    protected $fillable = [
        'restaurante_id', 'categoria_id', 'nombre', 'descripcion',
        'precio', 'costo', 'stock', 'stock_minimo', 'imagen', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'costo' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }
}