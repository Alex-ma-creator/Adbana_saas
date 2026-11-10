<?php

namespace App\Support;

use App\Models\Cobro;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class Cobros
{
    /** @param array<int, Pedido> $pedidos @param array<int, array{medio_pago:string,monto:mixed,recibido?:mixed}> $pagos */
    public function registrar(array $pedidos, array $pagos, User $user, int $cajaId): array
    {
        $saldo = array_sum(array_map(fn ($p) => Money::cents($p->saldo_pendiente), $pedidos));
        $importe = array_sum(array_map(fn ($p) => Money::cents($p['monto']), $pagos));
        if ($importe < 1 || $importe > $saldo) {
            throw ValidationException::withMessages(['pagos' => 'El importe debe ser mayor que cero y no superar el saldo pendiente.']);
        }
        foreach ($pedidos as $pedido) {
            if ($pedido->estado === 'cancelado' || $pedido->anulado_at) {
                throw ValidationException::withMessages(['pedido' => 'El pedido está anulado.']);
            }
        }
        $registrados = [];
        foreach ($pagos as $pago) {
            $restante = Money::cents($pago['monto']);
            $recibido = $pago['medio_pago'] === 'efectivo' ? Money::cents($pago['recibido'] ?? 0) : $restante;
            if ($recibido < $restante) {
                throw ValidationException::withMessages(['recibido' => 'El efectivo recibido no alcanza.']);
            }
            $vuelto = $recibido - $restante;
            foreach ($pedidos as $pedido) {
                $aplicar = min($restante, Money::cents($pedido->saldo_pendiente));
                if ($aplicar === 0) {
                    continue;
                }
                $cobro = Cobro::create(['pedido_id' => $pedido->id, 'caja_id' => $cajaId, 'user_id' => $user->id, 'medio_pago' => $pago['medio_pago'], 'tipo' => 'cobro', 'monto' => Money::decimal($aplicar), 'recibido' => Money::decimal($aplicar + $vuelto), 'vuelto' => Money::decimal($vuelto)]);
                $registrados[] = $cobro->toArray();
                $pedido->pagado = Money::decimal(Money::cents($pedido->pagado) + $aplicar);
                $pedido->medio_pago = $pedido->medio_pago && $pedido->medio_pago !== $pago['medio_pago'] ? 'mixto' : $pago['medio_pago'];
                $pedido->caja_id = $cajaId;
                $pedido->recibido = Money::decimal(Money::cents($pedido->recibido ?? 0) + $aplicar + $vuelto);
                $pedido->vuelto = Money::decimal(Money::cents($pedido->vuelto ?? 0) + $vuelto);
                if (Money::cents($pedido->pagado) === Money::cents($pedido->total)) {
                    $pedido->cobrado_at = now();
                }
                $pedido->save();
                $restante -= $aplicar;
                $vuelto = 0;
                if ($restante === 0) {
                    break;
                }
            }
        }

        return $registrados;
    }

    public static function reglas(): array
    {
        return ['clave_operacion' => ['required', 'uuid'], 'pagos' => ['required', 'array', 'min:1', 'max:20'], 'pagos.*.medio_pago' => ['required', 'in:efectivo,yape,plin,tarjeta'], 'pagos.*.monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'], 'pagos.*.recibido' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']];
    }
}
