<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Support\InventarioPedido;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    private const TRANSICIONES = ['pendiente' => ['confirmado', 'preparando', 'cancelado'], 'confirmado' => ['preparando', 'listo', 'entregado', 'cancelado'], 'preparando' => ['listo', 'cancelado'], 'listo' => ['entregado', 'cancelado'], 'entregado' => ['cancelado'], 'cancelado' => []];

    public function index(Request $request)
    {
        $request->validate([
            'estado'   => ['sometimes', 'string'],
            'mesa'     => ['sometimes', 'string', 'max:20'],
            'q'        => ['sometimes', 'nullable', 'string', 'max:120'],
            'tipo'     => ['sometimes', 'in:mesa,delivery,recojo'],
            'desde'    => ['sometimes', 'date'],
            'hasta'    => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $request->integer('per_page', 20);

        $pedidos = Pedido::with('detalles')
            ->when($request->filled('mesa'), fn ($q) => $q->where('mesa', $request->input('mesa')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->whereLike('cliente_nombre', $term)->orWhereLike('mesa', $term)->orWhereHas('detalles', fn ($d) => $d->whereLike('nombre_producto', $term));
            }))
            ->when($request->boolean('abiertos'), fn ($q) => $q->where('estado', '!=', 'cancelado')->where(fn ($q) => $q->whereNotIn('estado', ['entregado', 'cancelado'])->orWhereColumn('pagado', '<', 'total')))
            ->when($request->filled('estado') && ! $request->boolean('abiertos'), fn ($q) => $q->whereIn('estado', explode(',', $request->input('estado'))))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('desde'), fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($request->input('desde'))->startOfDay()))
            ->when($request->filled('hasta'), fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse($request->input('hasta'))->startOfDay()->addDay()))
            ->when($request->boolean('abiertos'), fn ($q) => $q->orderBy('id'), fn ($q) => $q->latest('id'))
            ->paginate($perPage);

        if (! $request->user()->esDueno()) {
            $pedidos->getCollection()->each(fn ($pedido) => $pedido->detalles->each->makeHidden('costo_unitario'));
        }

        return $pedidos;
    }

    public function listos(): JsonResponse
    {
        return response()->json(Pedido::where('estado', 'listo')->latest('updated_at')->limit(20)->get(['id', 'mesa', 'tipo', 'estado', 'updated_at'])->each->setAppends([]));
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
        $destino = $request->input('estado');
        $permiso = match ($destino) {
            'confirmado', 'entregado' => 'pedidos',
            'preparando', 'listo' => 'cocina',
            'cancelado' => 'cancelar',
            default => null,
        };
        if ($permiso) {
            abort_unless($request->user()->tienePermiso($permiso), 403);
        }
        $datos = $request->validate(['motivo' => ['required_if:estado,cancelado', 'nullable', 'string', 'min:5', 'max:500'], 'reponer_stock' => ['sometimes', 'boolean'], 'estado' => ['required', 'in:pendiente,confirmado,preparando,listo,entregado,cancelado']]);
        $pedido = DB::transaction(function () use ($request, $id, $datos) {
            // Bloquea la fila: evita cancelar dos veces el mismo pedido a la vez.
            $pedido = Pedido::lockForUpdate()->findOrFail($id);
            $permitidos = self::TRANSICIONES[$pedido->estado] ?? [];
            if (! in_array($datos['estado'], $permitidos, true)) {
                throw ValidationException::withMessages(['estado' => "No se puede pasar de {$pedido->estado} a {$datos['estado']}."]);
            }
            $cocina = $pedido->detalles()->where('requiere_cocina', true)->exists();
            if ($cocina && $pedido->estado === 'confirmado' && in_array($datos['estado'], ['listo', 'entregado'], true)) {
                throw ValidationException::withMessages(['estado' => 'Este pedido debe pasar por cocina antes de entregarlo.']);
            }
            if ($datos['estado'] === 'cancelado') {
                if (Money::cents($pedido->pagado) > 0 || $pedido->cobrado_at) {
                    throw ValidationException::withMessages(['estado' => 'Un pedido con pagos requiere una devolución autorizada.']);
                }
                $preparado = $cocina && in_array($pedido->estado, ['preparando', 'listo', 'entregado'], true);
                $reponer = $datos['reponer_stock'] ?? ! $preparado;
                if ($preparado && $reponer) {
                    throw ValidationException::withMessages(['reponer_stock' => 'No se puede reponer un producto preparado. Registra la merma.']);
                }
                if ($reponer) {
                    app(InventarioPedido::class)->reponer($pedido, $request->user()->id);
                }
                $pedido->motivo_cancelacion = $datos['motivo'];
                $pedido->anulado_at = now();
                DB::table('eventos_operacion')->insert(['restaurante_id' => $pedido->restaurante_id, 'user_id' => $request->user()->id, 'pedido_id' => $pedido->id, 'accion' => $reponer ? 'cancelacion_repuesta' : 'cancelacion_merma', 'motivo' => $datos['motivo'], 'datos' => json_encode(['costo_merma_centavos' => $reponer ? 0 : $pedido->detalles->sum(fn ($d) => Money::cents($d->costo_unitario) * $d->cantidad)], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            $pedido->estado = $datos['estado'];
            $pedido->save();

            return $pedido;
        });
        if ($pedido->estado === 'cancelado') {
            Cache::forget('carta:'.$request->user()->restaurante->slug);
        }
        $pedido->load('detalles');
        if (! $request->user()->esDueno()) {
            $pedido->detalles->each->makeHidden('costo_unitario');
        }
        if (! $request->user()->tienePermiso('pedidos') && ! $request->user()->tienePermiso('caja') && ! $request->user()->tienePermiso('cancelar')) {
            return response()->json($pedido->only(['id', 'tipo', 'mesa', 'estado', 'notas', 'created_at']) + ['detalles' => $pedido->detalles->map(fn ($detalle) => $detalle->only(['id', 'pedido_id', 'nombre_producto', 'cantidad']))]);
        }

        return response()->json($pedido);
    }
}
