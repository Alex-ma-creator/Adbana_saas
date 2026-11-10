<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeguridadCuentaController extends Controller
{
    public function solicitar(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email', 'max:255']]);
        // No revela si existe el correo, ni devuelve el token por la API.
        Password::sendResetLink($datos);

        return response()->json(['message' => 'Si el correo corresponde a una cuenta, recibirás instrucciones para restablecer tu contraseña.']);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $estado = DB::transaction(function () use ($datos) {
            DB::table(config('auth.passwords.users.table'))->where('email', $datos['email'])->lockForUpdate()->first();

            return Password::reset($datos, function (User $user, string $password) {
                // Comparte el bloqueo con emisión de tokens para evitar sesiones antiguas concurrentes.
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            });
        });
        if ($estado !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace es inválido o ha expirado. Solicita uno nuevo.']);
        }

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión de nuevo.']);
    }

    public function sesiones(Request $request): JsonResponse
    {
        $actual = $request->user()->currentAccessToken()?->getKey();

        return response()->json($request->user()->tokens()->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->where('created_at', '>', now()->subMinutes(config('sanctum.expiration')))->latest('id')->limit(50)->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])->map(fn ($t) => $t->toArray() + ['actual' => $t->id === $actual]));
    }

    public function revocar(Request $request, string $id): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($id);
        $token->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function cerrarOtras(Request $request): JsonResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        });

        return response()->json(['message' => 'Las demás sesiones se cerraron.']);
    }
}
