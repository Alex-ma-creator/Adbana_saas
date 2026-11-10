<?php

namespace App\Http\Controllers;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function registroDueno(Request $request)
    {
        $datos = $request->validate(['restaurante' => ['required', 'string', 'max:120'], 'telefono' => ['nullable', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:8']]);
        [$restaurante, $user] = DB::transaction(function () use ($datos) {
            $restaurante = Restaurante::create(['nombre' => $datos['restaurante'], 'slug' => $this->slugUnico($datos['restaurante']), 'telefono' => $datos['telefono'] ?? null]);
            $user = User::create(['restaurante_id' => $restaurante->id, 'name' => $datos['name'], 'email' => $datos['email'], 'password' => $datos['password'], 'rol' => 'dueno']);

            return [$restaurante, $user];
        });

        return response()->json(['token' => $user->createToken('panel', ['*'], now()->addMinutes(config('sanctum.expiration')))->plainTextToken, 'user' => $user->load('restaurante')->append('permisos'), 'restaurante' => $restaurante], 201);
    }

    public function login(Request $request)
    {
        $datos = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $datos['email'])->first();
        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }
        if (! $user->activo || $user->restaurante && ! $user->restaurante->activo) {
            return response()->json(['message' => 'Cuenta desactivada.'], 403);
        }

        return response()->json(['token' => DB::transaction(function () use ($user, $datos) {
            $actual = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actual->activo && $actual->restaurante?->activo, 403);
            abort_unless(Hash::check($datos['password'], $actual->password), 401);
            // Conserva nueve sesiones previas antes de emitir la nueva.
            $conservar = $actual->tokens()->latest('id')->limit(9)->pluck('id');
            $actual->tokens()->whereNotIn('id', $conservar)->delete();

            return $actual->createToken('panel', ['*'], now()->addMinutes(config('sanctum.expiration')))->plainTextToken;
        }), 'user' => $user->load('restaurante')->append('permisos')]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('restaurante')->append('permisos'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function slugUnico(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'restaurante';
        $slug = $base;
        $i = 2;
        while (Restaurante::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
