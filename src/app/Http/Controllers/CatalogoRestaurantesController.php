<?php

namespace App\Http\Controllers;

use App\Models\Restaurante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogoRestaurantesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'comida' => ['nullable', 'string', 'max:60']]);
        $restaurantes = Restaurante::where('activo', true)->when(! $request->boolean('directorio'), fn ($query) => $query->where('publicado', true))
            ->when($request->filled('q'), fn ($query) => $query->whereLike('nombre', '%'.$request->string('q').'%'))
            ->when($request->filled('comida'), function ($query) use ($request) {
                $termino = '%'.$request->string('comida').'%';
                $query->whereHas('productos', fn ($productos) => $productos
                    ->withoutGlobalScope('restaurante')->where('activo', true)->where('es_insumo', false)
                    ->where(fn ($platos) => $platos->whereLike('nombre', $termino)
                        ->orWhereHas('categoria', fn ($categorias) => $categorias->withoutGlobalScope('restaurante')->whereColumn('categorias.restaurante_id', 'productos.restaurante_id')->whereLike('nombre', $termino))));
            })
            ->orderBy('nombre')->orderBy('id')->paginate(20, $request->boolean('directorio') ? ['nombre', 'slug'] : ['nombre', 'slug', 'telefono', 'configuracion']);

        return response()->json($restaurantes);
    }
}
