<?php

namespace App\Http\Controllers;

use App\Models\AdministradorPlataforma;
use App\Support\AdministracionPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccesoPlataformaController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string']]);
        $admin = DB::transaction(function () use ($datos, $request) {
            $admin = AdministradorPlataforma::where('email', strtolower(trim($datos['email'])))->lockForUpdate()->first();
            abort_unless($admin && $admin->activo && Hash::check($datos['password'], $admin->password), 401);
            $admin->forceFill(['ultimo_acceso_at' => now()])->save();
            app(AdministracionPlataforma::class)->registrar($admin, 'iniciar_sesion', 'administrador', $admin->id, null, null, null, $request->ip());

            return $admin;
        });
        Auth::guard('plataforma')->login($admin, false);
        $request->session()->regenerate();
        $request->session()->put('plataforma_version', $admin->sesion_version);

        return response()->json(['administrador' => $admin->append('permisos_efectivos'), 'csrf' => csrf_token()]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user('plataforma')->append('permisos_efectivos'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('plataforma')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.', 'csrf' => csrf_token()]);
    }

    public function perfil(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password_actual' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:12', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen($value) > 72) {
                    $fail('La contraseña admite un máximo de 72 bytes.');
                }
            }],
        ]);
        $admin = DB::transaction(function () use ($request, $datos) {
            DB::table('control_plataforma')->where('id', 1)->lockForUpdate()->firstOrFail();
            $admin = AdministradorPlataforma::lockForUpdate()->findOrFail($request->user('plataforma')->id);
            abort_unless($admin->activo && $request->session()->get('plataforma_version') === $admin->sesion_version, 401);
            abort_unless(Hash::check($datos['password_actual'], $admin->password), 422, 'Contraseña actual incorrecta.');
            $antes = $admin->only(['id', 'name', 'email']);
            $admin->name = $datos['name'];
            if (! empty($datos['password'])) {
                $admin->password = $datos['password'];
                $admin->sesion_version++;
            }
            $admin->save();
            app(AdministracionPlataforma::class)->registrar($admin, 'actualizar_perfil', 'administrador', $admin->id, $antes, $admin->only(['id', 'name', 'email']), 'Actualización del perfil propio', $request->ip());

            return $admin;
        });
        $request->session()->regenerate();
        $request->session()->put('plataforma_version', $admin->sesion_version);

        return response()->json(['administrador' => $admin->append('permisos_efectivos'), 'csrf' => csrf_token()])->header('Cache-Control', 'no-store');
    }
}
