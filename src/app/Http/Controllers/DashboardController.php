<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $hoy = CarbonImmutable::today();
        $inicio = $hoy->subDays(6);
        $fin = $hoy->addDay();
        $cobros = Pedido::where('cobrado_at', '>=', $inicio)->where('cobrado_at', '<', $fin);
        $dias = (clone $cobros)->selectRaw('DATE(cobrado_at) as dia, SUM(total) as ventas, COUNT(*) as cantidad')->groupBy(DB::raw('DATE(cobrado_at)'))->get()->keyBy('dia');
        $serie = collect(range(0, 6))->map(function ($i) use ($inicio, $dias) {
            $dia = $inicio->addDays($i)->toDateString();
            return ['fecha' => $dia, 'ventas' => (string) ($dias->get($dia)?->ventas ?? '0.00')];
        });
        $pagadosHoy = (clone $cobros)->where('cobrado_at', '>=', $hoy);
        $ventas = Money::cents((clone $pagadosHoy)->sum('total'));
        $cantidad = (clone $pagadosHoy)->count();
        $top = DetallePedido::join('pedidos', 'pedidos.id', '=', 'detalle_pedidos.pedido_id')
            ->where('pedidos.restaurante_id', $request->user()->restaurante_id)
            ->where('pedidos.cobrado_at', '>=', $inicio)->where('pedidos.cobrado_at', '<', $fin)
            ->selectRaw('detalle_pedidos.nombre_producto as nombre, SUM(detalle_pedidos.cantidad) as cantidad, SUM(detalle_pedidos.subtotal) as ventas')
            ->groupBy('detalle_pedidos.nombre_producto')->orderByDesc('cantidad')->limit(5)->get();
        $recientes = Pedido::select(['id', 'cliente_nombre', 'tipo', 'estado', 'total', 'cobrado_at', 'created_at'])->latest('id')->limit(6)->get();
        return response()->json([
            'ventas_hoy' => Money::decimal($ventas), 'cobrados_hoy' => $cantidad,
            'pedidos_hoy' => Pedido::where('created_at', '>=', $hoy)->where('created_at', '<', $fin)->count(),
            'abiertos' => Pedido::whereNotIn('estado', ['entregado', 'cancelado'])->count(),
            'ticket_promedio' => Money::decimal($cantidad ? (int) round($ventas / $cantidad) : 0),
            'ventas_semana' => Money::decimal(Money::cents((clone $cobros)->sum('total'))),
            'serie' => $serie, 'top' => $top, 'recientes' => $recientes,
        ]);
    }
}
