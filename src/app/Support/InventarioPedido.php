<?php

namespace App\Support;

use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventarioPedido
{
    /** @return array<int, string> Costos unitarios históricos, calculados con la receta vigente. */
    public function descontar(Pedido $pedido, Collection $cantidades, ?int $userId, string $motivo): array
    {
        $recetas = DB::table('recetas')->where('restaurante_id', $pedido->restaurante_id)->whereIn('producto_id', $cantidades->keys())->get()->groupBy('producto_id');
        $necesarios = [];
        $componentes = [];
        foreach ($cantidades as $id => $cantidad) {
            $componentes[$id] = $recetas->has($id) ? $recetas[$id]->mapWithKeys(fn ($r) => [$r->insumo_id => $r->cantidad])->all() : [$id => 1];
            foreach ($componentes[$id] as $insumo => $factor) {
                $necesarios[$insumo] = ($necesarios[$insumo] ?? 0) + $factor * $cantidad;
            }
        }
        ksort($necesarios);
        $stocks = Producto::whereIn('id', array_keys($necesarios))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($necesarios as $id => $cantidad) {
            $producto = $stocks->get($id);
            if (! $producto || $cantidad > $producto->stock) {
                throw ValidationException::withMessages(['items' => 'Stock insuficiente de '.($producto?->nombre ?? 'un insumo').'.']);
            }
            $producto->decrement('stock', $cantidad);
            MovimientoStock::create(['producto_id' => $id, 'pedido_id' => $pedido->id, 'user_id' => $userId, 'tipo' => 'salida', 'cantidad' => $cantidad, 'motivo' => $motivo]);
            DB::table('consumos_pedido')->insert(['restaurante_id' => $pedido->restaurante_id, 'pedido_id' => $pedido->id, 'producto_id' => $id, 'cantidad' => $cantidad]);
        }
        $costos = [];
        foreach ($componentes as $id => $partes) {
            $costo = 0;
            foreach ($partes as $insumo => $factor) {
                $costo += Money::cents($stocks[$insumo]->costo) * $factor;
            }
            $costos[$id] = Money::decimal($costo);
        }

        return $costos;
    }

    public function reponer(Pedido $pedido, ?int $userId): void
    {
        $consumos = DB::table('consumos_pedido')->where('restaurante_id', $pedido->restaurante_id)->where('pedido_id', $pedido->id)->where('repuesto', false)->orderBy('producto_id')->lockForUpdate()->get();
        if ($consumos->isEmpty()) {
            $consumos = $pedido->detalles()->whereNotNull('producto_id')->orderBy('producto_id')->get();
        }
        $productos = Producto::whereIn('id', $consumos->pluck('producto_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($consumos as $consumo) {
            if (! $productos->has($consumo->producto_id)) {
                continue;
            }
            $productos[$consumo->producto_id]->increment('stock', $consumo->cantidad);
            MovimientoStock::create(['producto_id' => $consumo->producto_id, 'pedido_id' => $pedido->id, 'user_id' => $userId, 'tipo' => 'entrada', 'cantidad' => $consumo->cantidad, 'motivo' => 'cancelacion_pedido']);
        }
        DB::table('consumos_pedido')->where('pedido_id', $pedido->id)->update(['repuesto' => true]);
    }
}
