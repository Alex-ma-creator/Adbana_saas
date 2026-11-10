<?php

namespace App\Http\Controllers;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Support\Money;
use App\Support\OperacionIdempotente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function alertas()
    {
        return Producto::with('receta.insumo')->where('activo', true)->where(fn ($q) => $q->whereColumn('stock', '<=', 'stock_minimo')->orWhereHas('receta'))->get(['id', 'categoria_id', 'nombre', 'stock', 'stock_minimo'])->filter(fn ($p) => $p->stock_disponible <= $p->stock_minimo)->sortBy('stock_disponible')->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'stock' => $p->stock_disponible, 'stock_minimo' => $p->stock_minimo, 'estado' => $p->stock_disponible <= 0 ? 'agotado' : 'bajo'])->values();
    }

    public function movimientos(Request $request)
    {
        $request->validate(['producto_id' => ['sometimes', 'integer'], 'tipo' => ['sometimes', 'in:entrada,salida,ajuste'], 'desde' => ['sometimes', 'date'], 'hasta' => ['sometimes', 'date']]);

        return MovimientoStock::with('producto:id,nombre')->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->integer('producto_id')))->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('desde')))->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('hasta')))->latest()->paginate(20);
    }

    public function registrar(Request $request)
    {
        $datos = $request->validate(['es_merma' => ['sometimes', 'boolean'], 'clave_operacion' => ['required', 'uuid'], 'producto_id' => ['required', 'integer'], 'tipo' => ['required', 'in:entrada,salida,ajuste'], 'cantidad' => ['required_if:tipo,entrada,salida', 'nullable', 'integer', 'min:1', 'max:1000000'], 'stock_real' => ['required_if:tipo,ajuste', 'nullable', 'integer', 'min:0', 'max:1000000'], 'motivo' => ['required_if:tipo,salida,ajuste', 'nullable', 'string', 'max:255']]);
        $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'stock_movimiento', $datos, function () use ($request, $datos) {
            // Bloquea la fila para que dos movimientos a la vez no se pisen.
            // El filtro por restaurante devuelve 404 si el producto es de otro.
            $producto = Producto::lockForUpdate()->findOrFail($datos['producto_id']);
            if ($producto->receta()->exists()) {
                throw ValidationException::withMessages(['producto_id' => 'Este producto consume insumos. Ajusta el inventario de sus componentes.']);
            }
            $cantidad = $datos['cantidad'] ?? 0;
            if ($datos['tipo'] === 'entrada') {
                $producto->stock += $cantidad;
            } elseif ($datos['tipo'] === 'salida') {
                if ($producto->stock < $cantidad) {
                    throw ValidationException::withMessages(['cantidad' => "Stock insuficiente: hay {$producto->stock} de {$producto->nombre}."]);
                }
                $producto->stock -= $cantidad;
            } else {
                // Ajuste = conteo físico. Se guarda la diferencia con signo.
                $cantidad = $datos['stock_real'] - $producto->stock;
                if ($cantidad === 0) {
                    throw ValidationException::withMessages(['stock_real' => 'El stock real ya coincide con el del sistema.']);
                }
                $producto->stock = $datos['stock_real'];
            }
            $producto->save();
            $movimiento = MovimientoStock::create(['producto_id' => $producto->id, 'user_id' => $request->user()->id, 'tipo' => $datos['tipo'], 'cantidad' => $cantidad, 'motivo' => $datos['motivo'] ?? null]);

            if ($datos['tipo'] === 'salida' && ($datos['es_merma'] ?? false)) {
                DB::table('eventos_operacion')->insert(['restaurante_id' => $producto->restaurante_id, 'user_id' => $request->user()->id, 'accion' => 'merma_stock', 'motivo' => $datos['motivo'], 'datos' => json_encode(['costo_merma_centavos' => Money::cents($producto->costo) * $cantidad], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['producto' => $producto->only(['id', 'nombre', 'stock', 'stock_minimo']), 'movimiento' => $movimiento->toArray()];
        });

        return response()->json($resultado, 201);
    }
}
