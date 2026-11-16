<?php

namespace App\Http\Controllers;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            $motivo = $request->input('error_description') ?: 'Inicio de sesión cancelado o denegado por Google.';

            return redirect('/panel?error=' . urlencode($motivo));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('Error en Google OAuth callback: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return redirect('/panel?error=' . urlencode('No se pudo verificar la cuenta de Google. Inténtalo de nuevo.'));
        }

        $email = $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();
        $name = trim($googleUser->getName() ?: '') ?: 'Usuario Google';

        if (! $email) {
            return redirect('/panel?error=' . urlencode('Google no proporcionó un correo electrónico válido.'));
        }

        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::where('email', $email)->first();
            if ($user && empty($user->google_id)) {
                $user->update(['google_id' => $googleId]);
            }
        }

        if ($user) {
            if (! $user->activo) {
                return redirect('/panel?error=' . urlencode('Tu cuenta se encuentra desactivada.'));
            }
            if ($user->restaurante && ! $user->restaurante->activo) {
                return redirect('/panel?error=' . urlencode('El restaurante asociado a esta cuenta está desactivado.'));
            }
        } else {
            [$restaurante, $user] = DB::transaction(function () use ($name, $email, $googleId) {
                $nombreRestaurante = 'Restaurante ' . $name;
                $slug = $this->slugUnico($nombreRestaurante);

                $restaurante = Restaurante::create([
                    'nombre' => $nombreRestaurante,
                    'slug' => $slug,
                ]);

                $user = User::create([
                    'restaurante_id' => $restaurante->id,
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(32)),
                    'rol' => 'dueno',
                    'activo' => true,
                ]);

                return [$restaurante, $user];
            });
        }

        $minutosExpiracion = (int) config('sanctum.expiration', 480);
        $expira = $minutosExpiracion > 0 ? now()->addMinutes($minutosExpiracion) : null;
        $token = $user->createToken('panel', ['*'], $expira)->plainTextToken;

        if ($request->wantsJson()) {
            return response()->json([
                'token' => $token,
                'expires_at' => $expira?->toIso8601String(),
                'user' => $user->load('restaurante')->append('permisos'),
            ]);
        }

        return redirect('/panel?token=' . urlencode($token));
    }

    private function slugUnico(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'restaurante';
        $slug = $base;
        $i = 2;
        while (Restaurante::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
