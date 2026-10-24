<?php

namespace App\Http\Controllers;

use App\Models\MovimientoStock;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function alertas()
    {
        return Producto::where('activo', true)
            ->whereColumn('stock', '<=', 'stock_minimo')
            ->orderBy('stock')
            ->get(['id', 'categoria_id', 'nombre', 'stock', 'stock_minimo'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'nombre'       => $p->nombre,
                'stock'        => $p->stock,
                'stock_minimo' => $p->stock_minimo,
                'estado'       => $p->stock <= 0 ? 'agotado' : 'bajo',
            ])
            ->values();
    }

    public function movimientos(Request $request)
    {
        $request->validate([
            'producto_id' => ['sometimes', 'integer'],
            'tipo'        => ['sometimes', 'in:entrada,salida,ajuste'],
            'desde'       => ['sometimes', 'date'],
            'hasta'       => ['sometimes', 'date'],
        ]);

        return MovimientoStock::with('producto:id,nombre')
            ->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->integer('producto_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('hasta')))
            ->latest()
            ->paginate(20);
    }

    public function registrar(Request $request)
    {
        $datos = $request->validate([
            'producto_id' => ['required', 'integer'],
            'tipo'        => ['required', 'in:entrada,salida,ajuste'],
            'cantidad'    => ['required_if:tipo,entrada,salida', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'stock_real'  => ['required_if:tipo,ajuste', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'motivo'      => ['required_if:tipo,salida,ajuste', 'nullable', 'string', 'max:255'],
        ]);

        $resultado = DB::transaction(function () use ($request, $datos) {
            // Bloquea la fila para que dos movimientos a la vez no se pisen.
            // El filtro por restaurante devuelve 404 si el producto es de otro.
            $producto = Producto::lockForUpdate()->findOrFail($datos['producto_id']);

            $cantidad = $datos['cantidad'] ?? 0;

            if ($datos['tipo'] === 'entrada') {
                $producto->stock += $cantidad;
            } elseif ($datos['tipo'] === 'salida') {
                if ($producto->stock < $cantidad) {
                    throw ValidationException::withMessages([
                        'cantidad' => "Stock insuficiente: hay {$producto->stock} de {$producto->nombre}.",
                    ]);
                }
                $producto->stock -= $cantidad;
            } else {
                // Ajuste = conteo físico. Se guarda la diferencia con signo.
                $cantidad = $datos['stock_real'] - $producto->stock;

                if ($cantidad === 0) {
                    throw ValidationException::withMessages([
                        'stock_real' => 'El stock real ya coincide con el del sistema.',
                    ]);
                }
                $producto->stock = $datos['stock_real'];
            }

            $producto->save();

            $movimiento = MovimientoStock::create([
                'producto_id' => $producto->id,
                'user_id'     => $request->user()->id,
                'tipo'        => $datos['tipo'],
                'cantidad'    => $cantidad,
                'motivo'      => $datos['motivo'] ?? null,
            ]);

            return [$producto, $movimiento];
        });

        [$producto, $movimiento] = $resultado;

        return response()->json([
            'producto'   => $producto->only(['id', 'nombre', 'stock', 'stock_minimo']),
            'movimiento' => $movimiento,
        ], 201);
    }
}