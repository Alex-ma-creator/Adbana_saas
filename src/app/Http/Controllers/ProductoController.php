<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\MovimientoStock;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        $productos = Producto::with('categoria:id,nombre')
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('nombre', 'ilike', '%' . $request->input('q') . '%'))
            ->orderBy('nombre')
            ->paginate(20);

        if (! $request->user()->esDueno()) {
            $productos->getCollection()->each->makeHidden('costo');
        }

        return $productos;
    }

    public function show(Request $request, string $id)
    {
        $producto = Producto::with('categoria:id,nombre')->findOrFail($id);

        if (! $request->user()->esDueno()) {
            $producto->makeHidden('costo');
        }

        return $producto;
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);

        $producto = DB::transaction(function () use ($request, $datos) {
            $producto = Producto::create($datos);

            if (($datos['stock'] ?? 0) > 0) {
                MovimientoStock::create([
                    'producto_id' => $producto->id,
                    'user_id'     => $request->user()->id,
                    'tipo'        => 'entrada',
                    'cantidad'    => $datos['stock'],
                    'motivo'      => 'stock_inicial',
                ]);
            }

            return $producto;
        });

        return response()->json($producto->load('categoria:id,nombre'), 201);
    }

    public function update(Request $request, string $id)
    {
        $producto = Producto::findOrFail($id);
        $producto->update($this->validar($request, true));

        return response()->json($producto->load('categoria:id,nombre'));
    }

    public function destroy(string $id)
    {
        $producto = Producto::findOrFail($id);

        if (DetallePedido::where('producto_id', $producto->id)->exists()) {
            return response()->json([
                'message' => 'El producto tiene pedidos. Desactívalo en vez de eliminarlo.',
            ], 409);
        }

        $producto->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $obligatorio = $parcial ? 'sometimes' : 'required';

        $reglas = [
            'categoria_id' => [
                'nullable',
                Rule::exists('categorias', 'id')->where('restaurante_id', $request->user()->restaurante_id),
            ],
            'nombre'       => [$obligatorio, 'string', 'max:150'],
            'descripcion'  => ['nullable', 'string', 'max:1000'],
            'precio'       => [$obligatorio, 'numeric', 'min:0', 'max:99999999.99'],
            'costo'        => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'activo'       => ['sometimes', 'boolean'],
            'stock'        => $parcial ? ['prohibited'] : ['sometimes', 'integer', 'min:0'],
        ];

        return $request->validate($reglas, [
            'stock.prohibited' => 'El stock se cambia desde /api/stock/movimientos.',
        ]);
    }
}