<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function index()
    {
        return Categoria::orderBy('orden')->orderBy('nombre')->get();
    }

    public function show(string $id)
    {
        return Categoria::findOrFail($id);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('categorias')->where('restaurante_id', $request->user()->restaurante_id),
            ],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        return response()->json(Categoria::create($datos), 201);
    }

    public function update(Request $request, string $id)
    {
        $categoria = Categoria::findOrFail($id);

        $datos = $request->validate([
            'nombre' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('categorias')
                    ->where('restaurante_id', $request->user()->restaurante_id)
                    ->ignore($categoria->id),
            ],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $categoria->update($datos);

        return response()->json($categoria);
    }

    public function destroy(string $id)
    {
        Categoria::findOrFail($id)->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }
}
