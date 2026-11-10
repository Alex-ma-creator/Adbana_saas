<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Receta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecetaController extends Controller
{
    public function guardar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['insumos' => ['present', 'array', 'max:30'], 'insumos.*.insumo_id' => ['required', 'integer', 'distinct'], 'insumos.*.cantidad' => ['required', 'integer', 'min:1', 'max:10000']]);
        $producto = DB::transaction(function () use ($request, $id, $datos) {
            $ids = collect($datos['insumos'])->pluck('insumo_id')->push($id)->sort()->unique();
            $productos = Producto::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $producto = $productos->get($id);
            abort_unless($producto, 404);
            if ($producto->es_insumo || Receta::where('insumo_id', $id)->exists()) {
                throw ValidationException::withMessages(['insumos' => 'Un insumo de otra receta no puede tener receta propia.']);
            }
            foreach ($datos['insumos'] as $item) {
                if ((int) $item['insumo_id'] === $id || ! $productos->has($item['insumo_id']) || Receta::where('producto_id', $item['insumo_id'])->exists()) {
                    throw ValidationException::withMessages(['insumos' => 'Usa insumos del restaurante, sin recetas anidadas ni referencias al mismo producto.']);
                }
            }
            $producto->receta()->delete();
            foreach ($datos['insumos'] as $item) {
                $producto->receta()->create($item);
            }
            DB::table('eventos_operacion')->insert(['restaurante_id' => $request->user()->restaurante_id, 'user_id' => $request->user()->id, 'accion' => 'editar_receta', 'motivo' => 'Receta de '.$producto->nombre, 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $producto->load('receta.insumo');
        }, 3);
        Cache::forget('carta:'.$request->user()->restaurante->slug);

        return response()->json($producto);
    }
}
