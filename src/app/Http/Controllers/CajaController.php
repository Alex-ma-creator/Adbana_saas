<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Pedido;
use App\Models\User;
use App\Support\Money;
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
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->exists()) {
                throw ValidationException::withMessages(['caja' => 'Ya tienes una caja abierta.']);
            }
            return Caja::create($data + ['user_id' => $request->user()->id]);
        });
        return response()->json($caja, 201);
    }

    public function movimiento(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:entrada,salida'],
            'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);
        return DB::transaction(function () use ($request, $data) {
            $caja = $this->bloquear($request);
            if ($data['tipo'] === 'salida' && Money::cents($data['monto']) > Money::cents($this->resumen($caja)['efectivo_esperado'])) {
                throw ValidationException::withMessages(['monto' => 'No alcanza el efectivo en caja.']);
            }
            DB::table('movimientos_caja')->insert($data + ['caja_id' => $caja->id, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json($this->resumen($caja), 201);
        });
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

    public function bloquear(Request $request): Caja
    {
        $caja = Caja::where('user_id', $request->user()->id)->whereNull('cerrada_at')->lockForUpdate()->first();
        if (! $caja) {
            throw ValidationException::withMessages(['caja' => 'Abre tu caja antes de cobrar.']);
        }
        return $caja;
    }

    private function resumen(Caja $caja): array
    {
        $pagos = Pedido::where('caja_id', $caja->id)->whereNotNull('cobrado_at')
            ->selectRaw('medio_pago, SUM(total) as total')->groupBy('medio_pago')->pluck('total', 'medio_pago');
        $entradas = DB::table('movimientos_caja')->where('caja_id', $caja->id)->where('tipo', 'entrada')->sum('monto');
        $salidas = DB::table('movimientos_caja')->where('caja_id', $caja->id)->where('tipo', 'salida')->sum('monto');
        return [
            'ventas_por_medio' => $pagos,
            'entradas' => Money::decimal(Money::cents($entradas)),
            'salidas' => Money::decimal(Money::cents($salidas)),
            'efectivo_esperado' => Money::decimal(Money::cents($caja->saldo_inicial) + Money::cents($pagos['efectivo'] ?? 0) + Money::cents($entradas) - Money::cents($salidas)),
        ];
    }
}
