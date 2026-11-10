<?php

namespace App\Http\Controllers;

use App\Models\Cobro;
use App\Models\Pedido;
use App\Support\InventarioPedido;
use App\Support\Money;
use App\Support\OperacionIdempotente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DevolucionController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['clave_operacion' => ['required', 'uuid'], 'motivo' => ['required', 'string', 'min:5', 'max:500'], 'medio_pago' => ['required', 'in:efectivo,yape,plin,tarjeta'], 'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'], 'anular' => ['required', 'boolean'], 'reponer_stock' => ['required', 'boolean']]);
        $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'devolucion:'.$id, $datos, function () use ($request, $id, $datos) {
            $pedido = Pedido::lockForUpdate()->findOrFail($id);
            $importe = Money::cents($datos['monto']);
            $disponible = Money::cents($pedido->pagado) - Money::cents($pedido->devuelto);
            if ($pedido->anulado_at || $importe > $disponible || ($datos['anular'] && $importe !== $disponible)) {
                throw ValidationException::withMessages(['monto' => 'La devolución excede lo cobrado o no devuelve todo el saldo para anular.']);
            }
            if ($datos['reponer_stock'] && (! $datos['anular'] || ($pedido->detalles()->where('requiere_cocina', true)->exists() && ! in_array($pedido->estado, ['pendiente', 'confirmado'], true)))) {
                throw ValidationException::withMessages(['reponer_stock' => 'Un producto preparado se registra como merma; no se repone automáticamente.']);
            }
            $caja = app(CajaController::class)->bloquear($request);
            if ($datos['medio_pago'] === 'efectivo' && $importe > Money::cents(app(CajaController::class)->resumen($caja)['efectivo_esperado'])) {
                throw ValidationException::withMessages(['monto' => 'No hay suficiente efectivo para devolver.']);
            }
            Cobro::create(['pedido_id' => $id, 'caja_id' => $caja->id, 'user_id' => $request->user()->id, 'tipo' => 'devolucion', 'medio_pago' => $datos['medio_pago'], 'monto' => Money::decimal($importe), 'motivo' => $datos['motivo']]);
            $pedido->devuelto = Money::decimal(Money::cents($pedido->devuelto) + $importe);
            if ($datos['anular']) {
                if ($datos['reponer_stock']) {
                    app(InventarioPedido::class)->reponer($pedido, $request->user()->id);
                }
                $pedido->estado = 'cancelado';
                $pedido->anulado_at = now();
                $pedido->motivo_cancelacion = $datos['motivo'];
            }
            $pedido->save();
            DB::table('eventos_operacion')->insert(['restaurante_id' => $pedido->restaurante_id, 'user_id' => $request->user()->id, 'pedido_id' => $id, 'accion' => $datos['anular'] && ! $datos['reponer_stock'] ? 'devolucion_merma' : 'devolucion', 'motivo' => $datos['motivo'], 'datos' => json_encode($datos + ['costo_merma_centavos' => $datos['anular'] && ! $datos['reponer_stock'] ? $pedido->detalles->sum(fn ($d) => Money::cents($d->costo_unitario) * $d->cantidad) : 0], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $pedido->only(['id', 'estado', 'total', 'pagado', 'devuelto', 'saldo_pendiente']);
        });
        Cache::forget('carta:'.$request->user()->restaurante->slug);

        return response()->json($resultado, 201);
    }
}
