<?php

namespace App\Support;

use App\Models\Pedido;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PedidoPublicoIdempotente
{
    public function ejecutar(int $restauranteId, string $clave, array $datos, Closure $crear): Pedido
    {
        unset($datos['clave_operacion']);
        ksort($datos);
        $huella = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($restauranteId, $clave, $huella, $crear) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $lockTimeoutMs = max(200, (int) config('adbana.db_lock_timeout_ms', 1800));
                $statementTimeoutMs = max(500, (int) config('adbana.db_statement_timeout_ms', 3500));
                DB::statement("SET LOCAL lock_timeout = '{$lockTimeoutMs}ms'");
                DB::statement("SET LOCAL statement_timeout = '{$statementTimeoutMs}ms'");
            }

            $ahora = now();
            $insertados = DB::table('solicitudes_publicas')->insertOrIgnore([
                'restaurante_id' => $restauranteId,
                'clave'          => $clave,
                'huella'         => $huella,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
            ]);

            if ($insertados === 0) {
                $solicitud = DB::table('solicitudes_publicas')
                    ->where('restaurante_id', $restauranteId)
                    ->where('clave', $clave)
                    ->lockForUpdate()
                    ->first();

                if (! hash_equals($solicitud->huella, $huella)) {
                    throw new ConflictHttpException('La clave ya se utilizó con otro pedido.');
                }
                if ($solicitud->pedido_id) {
                    return Pedido::whereKey($solicitud->pedido_id)->firstOrFail()->load('detalles');
                }
            }

            $pedido = $crear();

            DB::table('solicitudes_publicas')
                ->where('restaurante_id', $restauranteId)
                ->where('clave', $clave)
                ->update(['pedido_id' => $pedido->id, 'updated_at' => $ahora]);

            return $pedido;
        }, 3);
    }
}
