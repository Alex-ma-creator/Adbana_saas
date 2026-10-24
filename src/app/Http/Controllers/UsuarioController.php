<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        return User::where('restaurante_id', $request->user()->restaurante_id)
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $cajero = User::create([
            'restaurante_id' => $request->user()->restaurante_id,
            'name'           => $datos['name'],
            'email'          => $datos['email'],
            'password'       => $datos['password'],
            'rol'            => in_array($request->input('rol'), ['cajero', 'mozo', 'mozo_cajero', 'cocina'], true) ? $request->input('rol') : 'cajero',
        ]);

        return response()->json($cajero, 201);
    }

    public function update(Request $request, string $id)
    {
        $cajero = User::where('restaurante_id', $request->user()->restaurante_id)
            ->whereIn('rol', ['cajero', 'mozo', 'mozo_cajero', 'cocina'])
            ->findOrFail($id);

        $datos = $request->validate([
            'name'     => ['sometimes', 'string', 'max:120'],
            'password' => ['sometimes', 'string', 'min:8'],
            'activo'   => ['sometimes', 'boolean'],
        ]);

        $cajero->update($datos);

        if (array_key_exists('activo', $datos) && ! $datos['activo']) {
            $cajero->tokens()->delete();
        }

        return response()->json($cajero);
    }
}