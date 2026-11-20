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
    public function descontar(Pedido $pedido, Collection $cantidades, ?int $userId, string $motivo, ?Collection $productosBloqueados = null): array
    {
        if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            $lockTimeoutMs = max(200, (int) config('adbana.db_lock_timeout_ms', 1800));
            $statementTimeoutMs = max(500, (int) config('adbana.db_statement_timeout_ms', 3500));
            DB::statement("SET LOCAL lock_timeout = '{$lockTimeoutMs}ms'");
            DB::statement("SET LOCAL statement_timeout = '{$statementTimeoutMs}ms'");
        }

        $recetas = DB::table('recetas')
            ->where('restaurante_id', $pedido->restaurante_id)
            ->whereIn('producto_id', $cantidades->keys())
            ->get(['producto_id', 'insumo_id', 'cantidad'])
            ->groupBy('producto_id');

        $necesarios = [];
        $componentes = [];
        foreach ($cantidades as $id => $cantidad) {
            $componentes[$id] = $recetas->has($id)
                ? $recetas[$id]->mapWithKeys(fn ($r) => [$r->insumo_id => $r->cantidad])->all()
                : [$id => 1];
            foreach ($componentes[$id] as $insumo => $factor) {
                $necesarios[$insumo] = ($necesarios[$insumo] ?? 0) + $factor * $cantidad;
            }
        }
        ksort($necesarios);

        // Reutiliza los productos ya bloqueados con FOR UPDATE en la transacción del pedido cuando no hay insumos externos.
        $stocks = $productosBloqueados ? $productosBloqueados->only(array_keys($necesarios)) : collect();
        $faltantes = array_values(array_diff(array_keys($necesarios), $stocks->keys()->all()));
        if ($faltantes !== []) {
            $extra = Producto::whereIn('id', $faltantes)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'nombre', 'costo', 'stock'])
                ->keyBy('id');
            $stocks = $stocks->union($extra);
        }

        foreach ($necesarios as $id => $cantidad) {
            $producto = $stocks->get($id);
            if (! $producto || $cantidad > $producto->stock) {
                throw ValidationException::withMessages(['items' => 'Stock insuficiente de '.($producto?->nombre ?? 'un insumo').'.']);
            }
        }

        $ahora = now();
        if (count($necesarios) === 1) {
            $id = array_key_first($necesarios);
            DB::table('productos')
                ->where('id', $id)
                ->where('restaurante_id', $pedido->restaurante_id)
                ->decrement('stock', $necesarios[$id], ['updated_at' => $ahora]);
        } elseif (count($necesarios) > 1) {
            $cases = [];
            $bindings = [];
            foreach ($necesarios as $id => $cantidad) {
                $cases[] = 'WHEN ? THEN stock - ?';
                $bindings[] = (int) $id;
                $bindings[] = (int) $cantidad;
            }
            $ids = array_keys($necesarios);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $bindings[] = $ahora;
            foreach ($ids as $id) {
                $bindings[] = (int) $id;
            }
            $bindings[] = $pedido->restaurante_id;

            DB::update(
                'UPDATE productos SET stock = CASE id '.implode(' ', $cases).' END, updated_at = ? WHERE id IN ('.$placeholders.') AND restaurante_id = ?',
                $bindings
            );
        }

        $movimientos = [];
        $consumos = [];
        foreach ($necesarios as $id => $cantidad) {
            $movimientos[] = [
                'restaurante_id' => $pedido->restaurante_id,
                'producto_id'    => $id,
                'pedido_id'      => $pedido->id,
                'user_id'        => $userId,
                'tipo'           => 'salida',
                'cantidad'       => $cantidad,
                'motivo'         => $motivo,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
            ];
            $consumos[] = [
                'restaurante_id' => $pedido->restaurante_id,
                'pedido_id'      => $pedido->id,
                'producto_id'    => $id,
                'cantidad'       => $cantidad,
            ];
        }

        if ($movimientos !== []) {
            DB::table('movimientos_stock')->insert($movimientos);
            DB::table('consumos_pedido')->insert($consumos);
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
