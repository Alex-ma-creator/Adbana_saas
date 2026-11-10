<?php

namespace App\Http\Controllers;

use App\Models\CuentaMesa;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Support\Cobros;
use App\Support\CuentasMesa;
use App\Support\Money;
use App\Support\OperacionIdempotente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CuentaMesaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cuentas = CuentaMesa::where('abierta', 1)->when($request->filled('q'), fn ($q) => $q->whereLike('mesa', '%'.$request->string('q').'%'))->orderBy('mesa')->paginate(30);
        $ids = $cuentas->getCollection()->pluck('id');
        $totales = Pedido::whereIn('cuenta_id', $ids)->where('estado', '!=', 'cancelado')->whereNull('anulado_at')->selectRaw('cuenta_id, COUNT(*) as pedidos, MIN(CASE WHEN estado = \'listo\' THEN id END) as listo, SUM(CASE WHEN estado != \'entregado\' THEN 1 ELSE 0 END) as por_entregar, SUM(total) as total, SUM(pagado) as pagado')->groupBy('cuenta_id')->get()->keyBy('cuenta_id');
        $cuentas->through(function ($c) use ($totales) {
            $t = $totales->get($c->id);

            return ['id' => $c->id, 'mesa' => $c->mesa, 'estado' => match (true) {
                $t?->listo !== null => 'listo',
                (int) ($t?->por_entregar ?? 0) > 0 => 'preparando',
                Money::cents($t?->total ?? 0) > Money::cents($t?->pagado ?? 0) => 'pendiente',
                default => 'ocupada',
            }, 'primero_listo' => $t?->listo, 'pedidos' => (int) ($t?->pedidos ?? 0), 'total' => Money::decimal(Money::cents($t?->total ?? 0)), 'pendiente' => Money::decimal(Money::cents($t?->total ?? 0) - Money::cents($t?->pagado ?? 0))];
        });

        if ($request->boolean('servicio') && $cuentas->currentPage() === 1) {
            $activas = Mesa::where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
            $filas = $cuentas->getCollection();
            $ocupadas = CuentaMesa::where('abierta', 1)->pluck('mesa_key')->all();
            foreach ($activas as $mesa) {
                if (! in_array(mb_strtolower($mesa->nombre), $ocupadas, true)
                    && str_contains(mb_strtolower($mesa->nombre), mb_strtolower((string) $request->query('q', '')))) {
                    $filas->push(['id' => null, 'mesa_id' => $mesa->id, 'mesa' => $mesa->nombre, 'estado' => 'disponible', 'pendiente' => '0.00']);
                }
            }
        }

        return response()->json($cuentas);
    }

    public function ocupar(int $id): JsonResponse
    {
        $cuenta = DB::transaction(function () use ($id): CuentaMesa {
            $mesa = Mesa::where('activa', true)->lockForUpdate()->findOrFail($id);

            return app(CuentasMesa::class)->abrir($mesa->nombre);
        });

        return response()->json(['id' => $cuenta->id]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(app(CuentasMesa::class)->resumen(CuentaMesa::findOrFail($id), max(1, $request->integer('page', 1))));
    }

    public function cobrar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(Cobros::reglas() + ['mantener_ocupada' => ['sometimes', 'boolean']]);
        $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'cuenta_cobro:'.$id, $datos, function () use ($request, $id, $datos) {
            $cuenta = CuentaMesa::lockForUpdate()->findOrFail($id);
            abort_unless($cuenta->abierta, 409, 'La cuenta está cerrada.');
            $pedidos = Pedido::where('cuenta_id', $id)->where('estado', '!=', 'cancelado')->whereNull('anulado_at')->orderBy('id')->lockForUpdate()->get();
            $caja = app(CajaController::class)->bloquear($request);
            $cobros = app(Cobros::class)->registrar($pedidos->all(), $datos['pagos'], $request->user(), $caja->id);

            if (($datos['mantener_ocupada'] ?? null) === false
                && ! Pedido::where('cuenta_id', $id)->whereNull('anulado_at')->where('estado', '!=', 'cancelado')
                    ->where(fn ($q) => $q->where('estado', '!=', 'entregado')->orWhereColumn('pagado', '<', 'total'))->exists()) {
                $cuenta->update(['abierta' => null, 'cerrada_at' => now()]);
            }

            return app(CuentasMesa::class)->resumen($cuenta) + ['cobros' => $cobros];
        });

        return response()->json($resultado);
    }

    public function cerrar(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $c = CuentaMesa::lockForUpdate()->findOrFail($id);
            $pendientes = Pedido::where('cuenta_id', $id)->whereNull('anulado_at')->where('estado', '!=', 'cancelado')->where(fn ($q) => $q->where('estado', '!=', 'entregado')->orWhereColumn('pagado', '<', 'total'))->exists();
            if ($pendientes) {
                throw ValidationException::withMessages(['cuenta' => 'Entrega o cancela los pedidos y completa el pago antes de cerrar la mesa.']);
            }
            if ($c->abierta) {
                $c->update(['abierta' => null, 'cerrada_at' => now()]);
                DB::table('eventos_operacion')->insert(['restaurante_id' => $request->user()->restaurante_id, 'user_id' => $request->user()->id, 'accion' => 'cerrar_mesa', 'motivo' => 'Cierre de cuenta '.$id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return response()->json(app(CuentasMesa::class)->resumen($c));
        });
    }
}
