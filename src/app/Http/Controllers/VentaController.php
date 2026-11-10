<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Support\Cobros;
use App\Support\CuentasMesa;
use App\Support\HuellaOperacion;
use App\Support\InventarioPedido;
use App\Support\Money;
use App\Support\OperacionIdempotente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentaController extends Controller
{
    public function crear(Request $request)
    {

        $data = $request->validate(['clave_operacion' => ['required', 'uuid'], 'cliente_nombre' => ['required', 'string', 'max:120'], 'tipo' => ['required', 'in:mesa,recojo,delivery'], 'mesa' => ['required_if:tipo,mesa', 'nullable', 'string', 'max:20'], 'direccion' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'], 'cliente_telefono' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:30'], 'notas' => ['nullable', 'string', 'max:500'], 'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.producto_id' => ['required', 'integer'], 'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100']]);

        $huella = HuellaOperacion::de($data);

        $pedido = DB::transaction(function () use ($request, $data, $huella) {

            // Serializa los reintentos del mismo operador antes de comprobar la clave.

            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            $previo = Pedido::where('clave_operacion', $data['clave_operacion'])->first();

            if ($previo) {

                abort_unless($previo->user_id === $request->user()->id && $previo->huella_operacion !== null && hash_equals($previo->huella_operacion, $huella), 409);

                return $previo;

            }

            $modalidades = $request->user()->restaurante->configuracion['modalidades'] ?? ['mesa', 'recojo', 'delivery'];

            if (! in_array($data['tipo'], $modalidades, true)) {

                throw ValidationException::withMessages(['tipo' => 'Activa esta modalidad en la configuración del restaurante.']);
            }

            $cantidades = collect($data['items'])->groupBy('producto_id')->map(fn ($items) => (int) $items->sum('cantidad'))->sortKeys();

            $productos = Producto::whereIn('id', $cantidades->keys())->where('activo', true)->where('es_insumo', false)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($productos->count() !== $cantidades->count()) {

                throw ValidationException::withMessages(['items' => 'Hay productos no disponibles en tu restaurante.']);
            }

            $cuenta = $data['tipo'] === 'mesa' ? app(CuentasMesa::class)->abrir($data['mesa']) : null;

            $pedido = Pedido::create(collect($data)->except('items')->all() + ['user_id' => $request->user()->id, 'estado' => 'pendiente', 'total' => 0, 'cuenta_id' => $cuenta?->id]);

            $costos = app(InventarioPedido::class)->descontar($pedido, $cantidades, $request->user()->id, 'venta_pos');

            $total = 0;

            foreach ($cantidades as $id => $cantidad) {

                $producto = $productos[$id];

                if ($cantidad > 100) {

                    throw ValidationException::withMessages(['items' => "Cantidad no disponible para {$producto->nombre}."]);
                }

                $subtotal = Money::cents($producto->precio) * $cantidad;

                $total += $subtotal;

                if ($total > 9999999999) {

                    throw ValidationException::withMessages(['items' => 'El total excede el máximo permitido.']);
                }

                DetallePedido::create(['pedido_id' => $pedido->id, 'producto_id' => $id, 'nombre_producto' => $producto->nombre, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'costo_unitario' => $costos[$id], 'requiere_cocina' => $producto->requiere_cocina, 'subtotal' => Money::decimal($subtotal)]);

            }

            $pedido->forceFill(['total' => Money::decimal($total), 'huella_operacion' => $huella])->save();

            return $pedido;

        }, 3);

        Cache::forget('carta:'.$request->user()->restaurante->slug);

        return $this->respuesta($request, $pedido, 201);

    }

    public function cobrar(Request $request, string $id)
    {

        if ($request->has('pagos')) {

            $datos = $request->validate(Cobros::reglas());

            $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'pedido_cobro:'.$id, $datos, function () use ($request, $id, $datos) {

                $pedido = Pedido::lockForUpdate()->findOrFail($id);

                $caja = app(CajaController::class)->bloquear($request);

                $pagos = app(Cobros::class)->registrar([$pedido], $datos['pagos'], $request->user(), $caja->id);

                $pedido->load('detalles');

                if (! $request->user()->esDueno()) {

                    $pedido->detalles->each->makeHidden('costo_unitario');

                }

                return $pedido->toArray() + ['cobros' => $pagos];

            });

            return response()->json($resultado);

        }

        $datos = $request->validate(['medio_pago' => ['required', 'in:efectivo,yape,plin,tarjeta'], 'recibido' => ['required_if:medio_pago,efectivo', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']]);

        $pedido = DB::transaction(function () use ($request, $id, $datos) {

            $pedido = Pedido::lockForUpdate()->findOrFail($id);

            if ($pedido->cobrado_at) {

                abort_unless($pedido->medio_pago === $datos['medio_pago'] && ($datos['medio_pago'] !== 'efectivo' || Money::cents($pedido->recibido ?? 0) === Money::cents($datos['recibido'] ?? 0)), 409, 'El cobro ya existe con otros datos. Consulta el historial.');

                return $pedido;

            }

            $caja = app(CajaController::class)->bloquear($request);

            app(Cobros::class)->registrar([$pedido], [['medio_pago' => $datos['medio_pago'], 'monto' => $pedido->saldo_pendiente, 'recibido' => $datos['recibido'] ?? null]], $request->user(), $caja->id);

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
