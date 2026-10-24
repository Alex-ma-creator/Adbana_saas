<?php

namespace App\Http\Controllers;

use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    private const TRANSICIONES = [
        'pendiente'  => ['confirmado', 'cancelado'],
        'confirmado' => ['preparando', 'cancelado'],
        'preparando' => ['listo', 'cancelado'],
        'listo'      => ['entregado', 'cancelado'],
        'entregado'  => [],
        'cancelado'  => [],
    ];

    public function index(Request $request)
    {
        $request->validate([
            'estado' => ['sometimes', 'string'],
            'tipo'   => ['sometimes', 'in:mesa,delivery,recojo'],
            'desde'  => ['sometimes', 'date'],
            'hasta'  => ['sometimes', 'date'],
        ]);

        $pedidos = Pedido::with('detalles')
            ->when($request->filled('estado'), fn ($q) => $q->whereIn('estado', explode(',', $request->input('estado'))))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('hasta')))
            ->latest()
            ->paginate(20);

        if (! $request->user()->esDueno()) {
            $pedidos->getCollection()->each(
                fn ($pedido) => $pedido->detalles->each->makeHidden('costo_unitario')
            );
        }

        return $pedidos;
    }

    public function show(Request $request, string $id)
    {
        $pedido = Pedido::with('detalles')->findOrFail($id);

        if (! $request->user()->esDueno()) {
            $pedido->detalles->each->makeHidden('costo_unitario');
        }

        return $pedido;
    }

    public function cambiarEstado(Request $request, string $id)
    {
        $rol = $request->user()->rol;
        $destino = $request->input('estado');
        if ($rol === 'cocina' && ! in_array($destino, ['preparando', 'listo'], true)) {
            abort(403);
        }
        if (($rol === 'mozo' && $destino !== 'confirmado') || ($rol === 'mozo_cajero' && ! in_array($destino, ['confirmado', 'entregado'], true))) {
            abort(403);
        }

        $datos = $request->validate([
            'estado'     => ['required', 'in:pendiente,confirmado,preparando,listo,entregado,cancelado'],
            'medio_pago' => ['required_if:estado,entregado', 'nullable', 'in:efectivo,yape,plin,tarjeta'],
        ]);

        // Todas las entregas deben pasar por el mismo cobro y quedar asociadas a caja.
        if ($datos['estado'] === 'entregado') {
            return app(VentaController::class)->cobrar($request, $id);
        }
        $pedido = DB::transaction(function () use ($request, $id, $datos) {
            // Bloquea la fila: evita cancelar dos veces el mismo pedido a la vez.
            $pedido = Pedido::lockForUpdate()->findOrFail($id);

            $permitidos = self::TRANSICIONES[$pedido->estado] ?? [];

            if (! in_array($datos['estado'], $permitidos, true)) {
                throw ValidationException::withMessages([
                    'estado' => "No se puede pasar de {$pedido->estado} a {$datos['estado']}.",
                ]);
            }

            if ($datos['estado'] === 'cancelado') {
                foreach ($pedido->detalles as $detalle) {
                    if (! $detalle->producto_id) {
                        continue;
                    }

                    Producto::withoutGlobalScopes()
                        ->where('id', $detalle->producto_id)
                        ->increment('stock', $detalle->cantidad);

                    MovimientoStock::create([
                        'restaurante_id' => $pedido->restaurante_id,
                        'producto_id'    => $detalle->producto_id,
                        'pedido_id'      => $pedido->id,
                        'user_id'        => $request->user()->id,
                        'tipo'           => 'entrada',
                        'cantidad'       => $detalle->cantidad,
                        'motivo'         => 'cancelacion_pedido',
                    ]);
                }
            }

            $pedido->estado = $datos['estado'];

            if (! empty($datos['medio_pago'])) {
                $pedido->medio_pago = $datos['medio_pago'];
            }

            $pedido->save();

            return $pedido;
        });

        if ($pedido->estado === 'cancelado') {
            Cache::forget('carta:' . $request->user()->restaurante->slug);
        }

        $pedido->load('detalles');

        if (! $request->user()->esDueno()) {
            $pedido->detalles->each->makeHidden('costo_unitario');
        }

        if ($request->user()->rol === 'cocina') {
            return response()->json($pedido->only(['id', 'tipo', 'mesa', 'estado', 'notas', 'created_at']) + [
                'detalles' => $pedido->detalles->map(fn ($detalle) => $detalle->only(['id', 'pedido_id', 'nombre_producto', 'cantidad'])),
            ]);
        }
        return response()->json($pedido);
    }
}