<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use BelongsToRestaurante;

    protected $attributes = ['pagado' => '0.00', 'devuelto' => '0.00'];

    protected $hidden = ['huella_operacion'];

    protected $fillable = ['restaurante_id', 'user_id', 'cliente_nombre', 'cliente_telefono', 'tipo', 'mesa', 'direccion', 'estado', 'medio_pago', 'total', 'notas', 'caja_id', 'recibido', 'vuelto', 'cobrado_at', 'clave_operacion', 'cuenta_id', 'pagado', 'devuelto', 'anulado_at', 'motivo_cancelacion'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'pagado' => 'decimal:2', 'devuelto' => 'decimal:2', 'recibido' => 'decimal:2', 'vuelto' => 'decimal:2', 'cobrado_at' => 'datetime'];
    }

    protected $appends = ['saldo_pendiente'];

    public function getSaldoPendienteAttribute(): string
    {
        return Money::decimal($this->anulado_at || $this->estado === 'cancelado' ? 0 : max(0, Money::cents($this->total ?? 0) - Money::cents($this->pagado ?? 0)));
    }

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class);
    }
}
