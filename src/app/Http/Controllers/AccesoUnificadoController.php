<?php

namespace App\Http\Controllers;

use App\Models\AdministradorPlataforma;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccesoUnificadoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string'], 'espacio' => ['nullable', Rule::in(['restaurante', 'plataforma'])]]);
        $email = strtolower(trim($datos['email']));
        $request->merge(['email' => $email]);
        $usuario = User::with('restaurante')->where('email', $email)->first();
        $admin = AdministradorPlataforma::where('email', $email)->first();
        $espacios = [];
        if ($usuario && Hash::check($datos['password'], $usuario->password) && $usuario->activo && $usuario->restaurante?->activo) {
            $espacios[] = ['id' => 'restaurante', 'nombre' => $usuario->restaurante->nombre, 'descripcion' => 'Panel del restaurante'];
        }
        if ($admin && Hash::check($datos['password'], $admin->password) && $admin->activo) {
            $espacios[] = ['id' => 'plataforma', 'nombre' => 'Administración de Adbana', 'descripcion' => 'Equipo de la plataforma'];
        }
        abort_if($espacios === [], 401, 'Credenciales incorrectas o acceso no disponible.');
        $espacio = $datos['espacio'] ?? null;
        if (count($espacios) > 1 && $espacio === null) {
            return response()->json(['espacios' => $espacios])->header('Cache-Control', 'no-store');
        }
        $espacio ??= $espacios[0]['id'];
        abort_unless(in_array($espacio, array_column($espacios, 'id'), true), 401, 'Credenciales incorrectas o acceso no disponible.');
        if ($espacio === 'plataforma') {
            $respuesta = app(AccesoPlataformaController::class)->login($request)->getData(true);

            return response()->json(['destino' => '/administracion'] + $respuesta)->header('Cache-Control', 'no-store');
        }

        return app(AuthController::class)->login($request)->header('Cache-Control', 'no-store');
    }
}
