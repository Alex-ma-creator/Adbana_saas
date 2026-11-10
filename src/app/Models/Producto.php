<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRestaurante;
use App\Models\Concerns\LimpiaCacheCarta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use Auditable, BelongsToRestaurante, LimpiaCacheCarta;

    // El stock ya queda trazado en movimientos_stock.
    protected array $auditoriaExcluir = ['stock'];

    protected $fillable = [
        'restaurante_id', 'categoria_id', 'nombre', 'descripcion',
        'precio', 'costo', 'stock', 'stock_minimo', 'imagen', 'modelo_3d', 'activo', 'requiere_cocina', 'es_insumo', 'unidad',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'costo' => 'decimal:2',
            'activo' => 'boolean', 'requiere_cocina' => 'boolean', 'es_insumo' => 'boolean',
        ];
    }

    protected $appends = ['stock_disponible'];

    public function receta(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    public function getStockDisponibleAttribute(): int
    {
        if (! $this->relationLoaded('receta') || $this->receta->isEmpty()) {
            return (int) $this->stock;
        }

        return (int) $this->receta->min(fn ($r) => intdiv(max(0, (int) $r->insumo?->stock), max(1, (int) $r->cantidad)));
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }
}
