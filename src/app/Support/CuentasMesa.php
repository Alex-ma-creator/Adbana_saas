<?php

namespace App\Support;

use App\Models\CuentaMesa;
use App\Models\Mesa;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CuentasMesa
{
    public function abrir(string $mesa): CuentaMesa
    {

        $mesa = trim($mesa);

        if ($mesa === '') {

            throw ValidationException::withMessages(['mesa' => 'Indica la mesa.']);
        }

        $registrada = Mesa::where('nombre_key', mb_strtolower($mesa))->lockForUpdate()->first();

        if ($registrada && ! $registrada->activa) {

            throw ValidationException::withMessages(['mesa' => 'Esta mesa está desactivada.']);
        }

        if (! $registrada && Mesa::exists() && ! CuentaMesa::where('mesa_key', mb_strtolower($mesa))->where('abierta', 1)->exists()) {

            throw ValidationException::withMessages(['mesa' => 'Selecciona una mesa registrada en la configuración.']);
        }

        $identidad = ['restaurante_id' => app(RestauranteContexto::class)->id(), 'mesa_key' => mb_strtolower($mesa), 'abierta' => 1];

        DB::table('cuentas_mesa')->insertOrIgnore($identidad + ['mesa' => $mesa, 'created_at' => now(), 'updated_at' => now()]);

        return CuentaMesa::where($identidad)->lockForUpdate()->firstOrFail();

    }

    public function resumen(CuentaMesa $cuenta, int $pagina = 1): array
    {

        $base = Pedido::where('cuenta_id', $cuenta->id);

        $totales = (clone $base)->whereNull('anulado_at')->where('estado', '!=', 'cancelado')->selectRaw('COALESCE(SUM(total),0) as total, COALESCE(SUM(pagado),0) as pagado')->first();

        $pedidos = (clone $base)->with('detalles')->orderBy('id')->paginate(20, ['*'], 'page', $pagina);

        if (! auth()->user()?->esDueno()) {

            $pedidos->getCollection()->each(fn ($p) => $p->detalles->each->makeHidden('costo_unitario'));

        }

        return ['id' => $cuenta->id, 'mesa' => $cuenta->mesa, 'abierta' => (bool) $cuenta->abierta, 'total' => Money::decimal(Money::cents($totales->total)), 'pagado' => Money::decimal(Money::cents($totales->pagado)), 'pendiente' => Money::decimal(Money::cents($totales->total) - Money::cents($totales->pagado)), 'pedidos' => $pedidos->items(), 'pagina' => $pedidos->currentPage(), 'ultima_pagina' => $pedidos->lastPage(), 'total_pedidos' => $pedidos->total()];

    }
}
