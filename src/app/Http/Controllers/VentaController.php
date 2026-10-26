<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentaController extends Controller
{
    public function crear(Request $request)
    {
        $data = $request->validate([
            'clave_operacion' => ['required', 'uuid'],
            'cliente_nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:mesa,recojo,delivery'],
            'mesa' => ['required_if:tipo,mesa', 'nullable', 'string', 'max:20'],
            'direccion' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'],
            'cliente_telefono' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:30'],
            'notas' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $pedido = DB::transaction(function () use ($request, $data) {
            // Serializa los reintentos del mismo operador antes de comprobar la clave.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $previo = Pedido::where('clave_operacion', $data['clave_operacion'])->first();
            if ($previo) {
                abort_unless($previo->user_id === $request->user()->id, 409);
                return $previo;
            }
            $cantidades = collect($data['items'])->groupBy('producto_id')->map(fn ($items) => (int) $items->sum('cantidad'))->sortKeys();
            $productos = Producto::whereIn('id', $cantidades->keys())->where('activo', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($productos->count() !== $cantidades->count()) {
                throw ValidationException::withMessages(['items' => 'Hay productos no disponibles en tu restaurante.']);
            }
            $pedido = Pedido::create(collect($data)->except('items')->all() + ['user_id' => $request->user()->id, 'estado' => 'pendiente', 'total' => 0]);
            $total = 0;
            foreach ($cantidades as $id => $cantidad) {
                $producto = $productos[$id];
                if ($cantidad > 100 || $producto->stock < $cantidad) {
                    throw ValidationException::withMessages(['items' => "Cantidad no disponible para {$producto->nombre}."]);
                }
                $subtotal = Money::cents($producto->precio) * $cantidad;
                $total += $subtotal;
                if ($total > 9999999999) {
                    throw ValidationException::withMessages(['items' => 'El total excede el máximo permitido.']);
                }
                $producto->decrement('stock', $cantidad);
                DetallePedido::create(['pedido_id' => $pedido->id, 'producto_id' => $id, 'nombre_producto' => $producto->nombre, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'costo_unitario' => $producto->costo, 'subtotal' => Money::decimal($subtotal)]);
                MovimientoStock::create(['producto_id' => $id, 'pedido_id' => $pedido->id, 'user_id' => $request->user()->id, 'tipo' => 'salida', 'cantidad' => $cantidad, 'motivo' => 'venta_pos']);
            }
            $pedido->update(['total' => Money::decimal($total)]);
            return $pedido;
        }, 3);
        Cache::forget('carta:'.$request->user()->restaurante->slug);
        return $this->respuesta($request, $pedido, 201);
    }

    public function cobrar(Request $request, string $id)
    {
        $data = $request->validate([
            'medio_pago' => ['required', 'in:efectivo,yape,plin,tarjeta'],
            'recibido' => ['required_if:medio_pago,efectivo', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ]);
        $pedido = DB::transaction(function () use ($request, $id, $data) {
            // Todas las operaciones sobre un pedido bloquean primero el pedido.
            $pedido = Pedido::lockForUpdate()->findOrFail($id);
            // Un reintento devuelve el comprobante existente, sin duplicar el cobro.
            if ($pedido->cobrado_at) {
                return $pedido;
            }
            if ($pedido->estado === 'cancelado' || $pedido->estado === 'entregado') {
                throw ValidationException::withMessages(['pedido' => 'Este pedido no admite cobros.']);
            }
            $caja = app(CajaController::class)->bloquear($request);
            $total = Money::cents($pedido->total);
            $recibido = $data['medio_pago'] === 'efectivo' ? Money::cents($data['recibido']) : $total;
            if ($recibido < $total) {
                throw ValidationException::withMessages(['recibido' => 'El efectivo recibido no alcanza para pagar.']);
            }
            $pedido->update(['caja_id' => $caja->id, 'medio_pago' => $data['medio_pago'], 'recibido' => Money::decimal($recibido), 'vuelto' => Money::decimal($recibido - $total), 'cobrado_at' => now()]);
            return $pedido;
        }, 3);
        return $this->respuesta($request, $pedido);
    }

    private function respuesta(Request $request, Pedido $pedido, int $status = 200)
    {
        $pedido->load('detalles');
        if (! $request->user()->esDueno()) {
            $pedido->detalles->each->makeHidden('costo_unitario');
        }
        return response()->json($pedido, $status);
    }
}
