<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OperacionIdempotente
{
    /** La clave, el movimiento y su respuesta se confirman juntos o se revierten juntos. */
    public function ejecutar(User $user, string $ambito, array $datos, Closure $accion): array
    {
        $identidad = ['restaurante_id' => $user->restaurante_id, 'user_id' => $user->id, 'ambito' => $ambito, 'clave' => $datos['clave_operacion']];
        $huella = HuellaOperacion::de($datos);

        return DB::transaction(function () use ($identidad, $huella, $accion) {
            DB::table('operaciones_idempotentes')->insertOrIgnore($identidad + ['huella' => $huella, 'created_at' => now(), 'updated_at' => now()]);
            $registro = DB::table('operaciones_idempotentes')->where($identidad)->lockForUpdate()->first();
            if (! hash_equals($registro->huella, $huella)) {
                throw new ConflictHttpException('Clave reutilizada con datos diferentes.');
            }
            if ($registro->resultado !== null) {
                return json_decode($registro->resultado, true, 512, JSON_THROW_ON_ERROR);
            }
            $resultado = $accion();
            DB::table('operaciones_idempotentes')->where('id', $registro->id)->update(['resultado' => json_encode($resultado, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $resultado;
        }, 3);
    }
}
