<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PerfilController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => ['nullable', 'string'],
        ]);
        if (($request->filled('password') || $datos['email'] !== $user->email)
            && ! Hash::check($datos['current_password'] ?? '', $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Confirma tu contraseña actual para cambiar correo o contraseña.']);
        }
        DB::transaction(function () use ($user, $datos, $request) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->refresh();
            if (($request->filled('password') || $datos['email'] !== $user->email)
                && ! Hash::check($datos['current_password'] ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Confirma tu contraseña actual.']);
            }

            $user->name = $datos['name'];
            $user->email = $datos['email'];
            if ($request->filled('password')) {
                $user->password = $datos['password'];
                $token = $user->currentAccessToken();
                $tokenId = $token instanceof PersonalAccessToken ? $token->getKey() : null;
                $user->tokens()->when($tokenId, fn ($q, $id) => $q->whereKeyNot($id))->delete();
            }
            $user->save();
        });

        return response()->json($user->load('restaurante')->append('permisos'));
    }
}
