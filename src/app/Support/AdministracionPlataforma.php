<?php

namespace App\Support;

use App\Models\AdministradorPlataforma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class AdministracionPlataforma
{
    public const PERMISOS = ['resumen' => 'Ver dashboard', 'restaurantes' => 'Consultar restaurantes', 'gestionar_restaurantes' => 'Activar o suspender restaurantes', 'eliminar_restaurantes' => 'Eliminar restaurantes del servicio (conservar historial)', 'auditoria' => 'Consultar auditoría'];

    public function bloquear(Request $request, string $permiso, Closure $accion): mixed
    {
        return DB::transaction(function () use ($request, $permiso, $accion) {
            DB::table('control_plataforma')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = AdministradorPlataforma::find($request->user('plataforma')->id);
            abort_unless($actor && $request->session()->get('plataforma_version') === $actor->sesion_version, 401);
            abort_unless($actor && $actor->tienePermiso($permiso) && ($permiso !== 'administradores' || $actor->rol === 'superadmin'), 403);
            if ($actor->confirmacion_con_pin) {
                $key = 'confirmacion-pin-plataforma:'.$actor->id;
                abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Demasiados intentos de PIN. Espera un minuto y vuelve a intentarlo.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
                $pin = $request->string('password_actual')->toString();
                if (! preg_match('/\A[0-9]{9}\z/', $pin) || ! Hash::check($pin, $actor->pin_confirmacion)) {
                    RateLimiter::hit($key, 60);
                    abort(422, 'PIN de confirmación incorrecto.');
                }
                RateLimiter::clear($key);
            } else {
                abort_unless(Hash::check($request->string('password_actual')->toString(), $actor->password), 422, 'Contraseña de confirmación incorrecta.');
            }

            return $accion($actor);
        }, 3);
    }

    public function registrar(?AdministradorPlataforma $actor, string $accion, string $recurso, ?int $id, ?array $antes, ?array $despues, ?string $motivo = null, ?string $ip = null): void
    {
        DB::table('eventos_plataforma')->insert(['administrador_id' => $actor?->id, 'actor' => $actor?->name ?? 'Consola local', 'accion' => $accion, 'recurso' => $recurso, 'recurso_id' => $id, 'antes' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR), 'despues' => $despues === null ? null : json_encode($despues, JSON_THROW_ON_ERROR), 'motivo' => $motivo, 'ip' => $ip, 'created_at' => now()]);
    }
}
