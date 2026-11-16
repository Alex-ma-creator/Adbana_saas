<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Gasto;
use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\User;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    private const ROLES = ['cajero', 'mozo', 'mozo_cajero', 'cocina'];

    public function catalogo(Request $request)
    {
        $actor = $request->user();
        return response()->json(['permisos' => Permisos::CATALOGO,
            'roles' => collect(self::ROLES)->mapWithKeys(fn ($rol) => [$rol => Permisos::base($rol)]),
            'delegables' => array_values(array_filter(array_keys(Permisos::CATALOGO), fn ($p) => $actor->tienePermiso($p) && ($p !== 'equipo' || $actor->esDueno()))),
        ]);
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        return User::with('restaurante')->where('restaurante_id', $actor->restaurante_id)->orderBy('name')->get()
            ->each(function ($user) use ($actor) {
                $user->append('permisos');
                $user->setAttribute('editable', $user->id !== $actor->id && ! $user->esDueno() && $this->puedeGestionar($actor, $user));
            });
    }

    public function show(Request $request, string $id)
    {
        $actor = $request->user();
        $user = User::with('restaurante')
            ->where('restaurante_id', $actor->restaurante_id)
            ->findOrFail($id);

        $user->append('permisos');
        $user->setAttribute('editable', $user->id !== $actor->id && ! $user->esDueno() && $this->puedeGestionar($actor, $user));

        return response()->json($user);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        $rol = in_array($request->input('rol'), self::ROLES, true) ? $request->input('rol') : 'cajero';
        $this->validarDelegacion($request->user(), [], Permisos::base($rol));
        $user = User::create($datos + ['restaurante_id' => $request->user()->restaurante_id, 'rol' => $rol]);
        return response()->json($user->load('restaurante')->append('permisos'), 201);
    }

    public function update(Request $request, string $id)
    {
        $datos = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255'],
            'activo' => ['sometimes', 'boolean'], 'rol' => ['sometimes', Rule::in(self::ROLES)],
            'permisos_personalizados' => ['sometimes', 'array:'.implode(',', array_keys(Permisos::CATALOGO))],
            'permisos_personalizados.*' => ['boolean'],
        ]);
        $user = DB::transaction(function () use ($request, $id, $datos) {
            $actor = $request->user();
            $user = User::where('restaurante_id', $actor->restaurante_id)->lockForUpdate()->findOrFail($id);
            abort_if($user->esDueno(), 404);
            abort_if($user->id === $actor->id, 403);
            abort_unless($this->puedeGestionar($actor, $user), 403);
            $antes = Permisos::resolver($user->rol, $user->permisos_personalizados ?? []);
            $rol = $datos['rol'] ?? $user->rol;
            $cambios = array_key_exists('permisos_personalizados', $datos)
                ? array_map(fn ($v) => (bool) $v, $datos['permisos_personalizados'])
                : ($rol === $user->rol ? ($user->permisos_personalizados ?? []) : []);
            $despues = Permisos::resolver($rol, $cambios);
            $this->validarDelegacion($actor, $antes, $despues);
            if ((! $despues['caja'] || ! (bool) ($datos['activo'] ?? $user->activo))
                && Caja::where('user_id', $user->id)->whereNull('cerrada_at')->exists()) {
                throw new HttpResponseException(response()->json(['message' => 'Primero debe cerrar su turno de caja. Después podrás retirar el permiso o desactivar su cuenta.'], 409));
            }
            $user->fill(collect($datos)->except('permisos_personalizados')->all());
            $user->permisos_personalizados = $cambios;
            $user->save();
            if (! $user->activo || array_key_exists('password', $datos)) $user->tokens()->delete();
            return $user;
        });
        return response()->json($user->load('restaurante')->append('permisos'));
    }

    public function destroy(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $actor = $request->user();
            $user = User::where('restaurante_id', $actor->restaurante_id)->lockForUpdate()->findOrFail($id);

            abort_if($user->esDueno(), 404);
            abort_if($user->id === $actor->id, 403);
            abort_unless($this->puedeGestionar($actor, $user), 403);

            $tieneHistorial = Caja::withoutGlobalScopes()->where('user_id', $user->id)->exists()
                || Pedido::withoutGlobalScopes()->where('user_id', $user->id)->exists()
                || MovimientoStock::withoutGlobalScopes()->where('user_id', $user->id)->exists()
                || Gasto::withoutGlobalScopes()->where('user_id', $user->id)->exists();

            if ($tieneHistorial) {
                return response()->json([
                    'message' => 'El miembro del equipo tiene operaciones históricas registradas. Desactívalo en lugar de eliminarlo.',
                ], 409);
            }

            $user->tokens()->delete();
            $user->delete();

            return response()->json(['message' => 'Miembro del equipo eliminado.']);
        });
    }

    private function puedeGestionar(User $actor, User $user): bool
    {
        if ($actor->esDueno()) return true;
        if ($user->tienePermiso('equipo')) return false;
        foreach (Permisos::resolver($user->rol, $user->permisos_personalizados ?? []) as $clave => $permitido) {
            if ($permitido && ! $actor->tienePermiso($clave)) return false;
        }
        return true;
    }

    private function validarDelegacion(User $actor, array $antes, array $despues): void
    {
        if ($actor->esDueno()) return;
        foreach ($despues as $clave => $permitido) {
            if (($antes[$clave] ?? false) === $permitido) continue;
            abort_unless($clave !== 'equipo' && $actor->tienePermiso($clave), 403);
        }
    }
}
