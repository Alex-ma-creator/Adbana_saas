<?php

namespace App\Http\Controllers;

use App\Models\AdministradorPlataforma;
use App\Support\AdministracionPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdministradorPlataformaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['administradores' => AdministradorPlataforma::when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->whereLike('name', '%'.$request->input('q').'%')->orWhereLike('email', '%'.$request->input('q').'%')))->orderBy('id')->paginate(20), 'permisos' => AdministracionPlataforma::PERMISOS]);
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate(['generar_password' => ['sometimes', 'boolean'], 'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('administradores_plataforma')->ignore($id)],
            'rol' => ['required', Rule::in(['superadmin', 'administrador'])], 'activo' => ['sometimes', 'boolean'],
            'permisos' => ['required_if:rol,administrador', 'array:'.implode(',', array_keys(AdministracionPlataforma::PERMISOS))], 'permisos.*' => ['boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:12', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen($value) > 72) {
                    $fail('La contraseña admite un máximo de 72 bytes.');
                }
            }],
            'password_actual' => ['required', 'string'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $temporal = $request->boolean('generar_password') ? Str::password(20) : null;
        if ($temporal !== null) {
            $request->merge(['password' => $temporal, 'password_confirmation' => $temporal]);
        }
        $datos = $this->validar($request);
        $admin = app(AdministracionPlataforma::class)->bloquear($request, 'administradores', function ($actor) use ($request, $datos) {
            $admin = AdministradorPlataforma::create(collect($datos)->only(['name', 'email', 'password', 'rol', 'activo', 'permisos'])->all());
            app(AdministracionPlataforma::class)->registrar($actor, 'crear_administrador', 'administrador', $admin->id, null, $admin->toArray(), $datos['motivo'], $request->ip());

            return $admin;
        });

        return response()->json($admin->toArray() + ($temporal === null ? [] : ['password_temporal' => $temporal]), 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $datos = $this->validar($request, $id);
        $admin = app(AdministracionPlataforma::class)->bloquear($request, 'administradores', function ($actor) use ($id, $datos, $request) {
            abort_if($id === $actor->id, 409, 'No puedes cambiar tu propio acceso desde este formulario.');
            $admin = AdministradorPlataforma::findOrFail($id);
            $antes = $admin->toArray();
            $this->protegerUltimo($admin, $datos['rol'] === 'superadmin' && ($datos['activo'] ?? $admin->activo));
            $cambios = collect($datos)->only(['name', 'email', 'password', 'rol', 'activo', 'permisos'])->all();
            if (empty($cambios['password'])) {
                unset($cambios['password']);
            }
            $admin->fill($cambios);
            $admin->sesion_version++;
            $admin->save();
            app(AdministracionPlataforma::class)->registrar($actor, 'actualizar_administrador', 'administrador', $id, $antes, $admin->toArray(), $datos['motivo'], $request->ip());

            return $admin;
        });

        return response()->json($admin);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['password_actual' => ['required', 'string'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        app(AdministracionPlataforma::class)->bloquear($request, 'administradores', function ($actor) use ($request, $id, $datos) {
            abort_if($id === $actor->id, 409, 'No puedes eliminar tu propia cuenta.');
            $admin = AdministradorPlataforma::findOrFail($id);
            $this->protegerUltimo($admin, false);
            $antes = $admin->toArray();
            $admin->delete();
            app(AdministracionPlataforma::class)->registrar($actor, 'eliminar_administrador', 'administrador', $id, $antes, null, $datos['motivo'], $request->ip());
        });

        return response()->json(['message' => 'Acceso retirado. El historial de auditoría se conserva.']);
    }

    private function protegerUltimo(AdministradorPlataforma $admin, bool $conserva): void
    {
        if ($admin->rol === 'superadmin' && $admin->activo && ! $conserva) {
            abort_if(AdministradorPlataforma::where('rol', 'superadmin')->where('activo', true)->count() <= 1, 409, 'Debe quedar al menos un superadministrador activo.');
        }
    }
}
