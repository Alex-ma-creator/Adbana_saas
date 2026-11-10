<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Cobro;
use App\Models\User;
use App\Support\Money;
use App\Support\OperacionIdempotente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CajaController extends Controller
{
    public function actual(Request $request)
    {
        $caja = Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->first();

        return response()->json(['caja' => $caja, 'resumen' => $caja ? $this->resumen($caja) : null]);
    }

    public function abrir(Request $request)
    {
        $data = $request->validate(['saldo_inicial' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']]);
        $caja = DB::transaction(function () use ($request, $data) {
            $operador = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($operador->tienePermiso('caja'), 403);
            if (Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->exists()) {
                throw ValidationException::withMessages(['caja' => 'Ya tienes una caja abierta.']);
            }

            return Caja::create($data + ['user_id' => $request->user()->id]);
        });

        return response()->json($caja, 201);
    }

    public function movimiento(Request $request)
    {
        $data = $request->validate(['clave_operacion' => ['required', 'uuid'], 'tipo' => ['required', 'in:entrada,salida'], 'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'], 'motivo' => ['required', 'string', 'max:255']]);
        $data['monto'] = Money::decimal(Money::cents($data['monto']));
        $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'caja_movimiento', $data, function () use ($request, $data) {
            unset($data['clave_operacion']);
            $caja = $this->bloquear($request);
            if ($data['tipo'] === 'salida' && Money::cents($data['monto']) > Money::cents($this->resumen($caja)['efectivo_esperado'])) {
                throw ValidationException::withMessages(['monto' => 'No alcanza el efectivo en caja.']);
            }
            DB::table('movimientos_caja')->insert($data + ['caja_id' => $caja->id, 'created_at' => now(), 'updated_at' => now()]);

            return $this->resumen($caja);
        });

        return response()->json($resultado, 201);
    }

    public function cerrar(Request $request)
    {
        $data = $request->validate(['efectivo_contado' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']]);

        return DB::transaction(function () use ($request, $data) {
            $caja = $this->bloquear($request);
            $esperado = $this->resumen($caja)['efectivo_esperado'];
            $caja->update($data + ['efectivo_esperado' => $esperado, 'diferencia' => Money::decimal(Money::cents($data['efectivo_contado']) - Money::cents($esperado)), 'cerrada_at' => now()]);

            return response()->json($caja);
        });
    }

    public function destinos(Request $request): JsonResponse
    {
        $ids = User::where('restaurante_id', $request->user()->restaurante_id)->where('activo', true)->get()->filter(fn ($u) => $u->tienePermiso('caja'))->pluck('id');

        return response()->json(Caja::whereIn('user_id', $ids)->where('user_id', '!=', $request->user()->id)->whereNull('cerrada_at')->with('user:id,name')->get(['id', 'user_id']));
    }

    public function transferir(Request $request): JsonResponse
    {
        $datos = $request->validate(['clave_operacion' => ['required', 'uuid'], 'caja_destino_id' => ['required', 'integer'], 'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'], 'motivo' => ['required', 'string', 'min:5', 'max:255']]);
        $resultado = app(OperacionIdempotente::class)->ejecutar($request->user(), 'entrega_efectivo', $datos, function () use ($request, $datos) {
            $origenId = Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->value('id');
            $cajas = Caja::whereIn('id', [$origenId, $datos['caja_destino_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $origen = $cajas->get($origenId);
            $destino = $cajas->get($datos['caja_destino_id']);
            if (! $origen || ! $destino || $origen->id === $destino->id || $origen->cerrada_at || $destino->cerrada_at) {
                throw ValidationException::withMessages(['caja' => 'Selecciona dos turnos abiertos del restaurante.']);
            }
            $receptor = User::findOrFail($destino->user_id);
            abort_unless($receptor->tienePermiso('caja'), 403);
            if (Money::cents($datos['monto']) > Money::cents($this->resumen($origen)['efectivo_esperado'])) {
                throw ValidationException::withMessages(['monto' => 'El turno no tiene suficiente efectivo.']);
            }
            foreach ([[$origen, 'salida'], [$destino, 'entrada']] as [$caja, $tipo]) {
                DB::table('movimientos_caja')->insert(['caja_id' => $caja->id, 'tipo' => $tipo, 'monto' => Money::decimal(Money::cents($datos['monto'])), 'motivo' => 'Entrega entre cajas '.$origen->id.' → '.$destino->id.': '.$datos['motivo'], 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('eventos_operacion')->insert(['restaurante_id' => $request->user()->restaurante_id, 'user_id' => $request->user()->id, 'accion' => 'entrega_efectivo', 'motivo' => $datos['motivo'], 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return ['origen' => $this->resumen($origen), 'destino_id' => $destino->id];
        });

        return response()->json($resultado, 201);
    }

    public function bloquear(Request $request): Caja
    {
        abort_unless($request->user()->tienePermiso('caja'), 403);
        $caja = Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->lockForUpdate()->first();
        if (! $caja) {
            throw ValidationException::withMessages(['caja' => 'Abre tu caja antes de cobrar.']);
        }

        return $caja;
    }

    public function resumen(Caja $caja): array
    {
        $pagos = Cobro::where('caja_id', $caja->id)->selectRaw("medio_pago, SUM(CASE WHEN tipo = 'cobro' THEN monto ELSE -monto END) as total")->groupBy('medio_pago')->pluck('total', 'medio_pago');
        $movimientos = DB::table('movimientos_caja')->where('caja_id', $caja->id)->selectRaw('COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) as entradas, COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) as salidas', ['entrada', 'salida'])->first();
        $entradas = $movimientos->entradas;
        $salidas = $movimientos->salidas;

        return ['ventas_por_medio' => $pagos, 'entradas' => Money::decimal(Money::cents($entradas)), 'salidas' => Money::decimal(Money::cents($salidas)), 'efectivo_esperado' => Money::decimal(Money::cents($caja->saldo_inicial) + Money::cents($pagos['efectivo'] ?? 0) + Money::cents($entradas) - Money::cents($salidas))];
    }
}
