<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Gasto;
use App\Models\Pedido;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function resumen(Request $request)
    {
        $request->validate([
            'desde' => ['sometimes', 'date'],
            'hasta' => ['sometimes', 'date', 'after_or_equal:desde'],
        ]);

        $desde = $request->input('desde', now()->toDateString());
        $hasta = $request->input('hasta', $desde);
        $inicio = \Carbon\CarbonImmutable::parse($desde)->startOfDay();
        $fin = \Carbon\CarbonImmutable::parse($hasta)->startOfDay()->addDay();

        // El filtro por restaurante lo aplica solo el modelo Pedido.
        $cobrados = Pedido::whereNotNull('cobrado_at')
            ->where('cobrado_at', '>=', $inicio)
            ->where('cobrado_at', '<', $fin);

        $ventas   = Money::cents((clone $cobrados)->sum('total'));
        $cantidad = (clone $cobrados)->count();

        $costo = Money::cents(DetallePedido::whereIn('pedido_id', (clone $cobrados)->select('id'))
            ->sum(DB::raw('cantidad * costo_unitario')));

        $gastos = Money::cents(Gasto::whereBetween('fecha', [$desde, $hasta])->sum('monto'));

        $gananciaBruta = $ventas - $costo;
        $gananciaNeta  = $gananciaBruta - $gastos;

        $cancelados = Pedido::where('estado', 'cancelado')
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->count();

        $porMedioPago = (clone $cobrados)
            ->select('medio_pago', DB::raw('count(*) as pedidos'), DB::raw('sum(total) as total'))
            ->groupBy('medio_pago')
            ->get();

        $topProductos = DetallePedido::join('pedidos', 'pedidos.id', '=', 'detalle_pedidos.pedido_id')
            ->where('pedidos.restaurante_id', $request->user()->restaurante_id)
            ->whereNotNull('pedidos.cobrado_at')
            ->where('pedidos.cobrado_at', '>=', $inicio)
            ->where('pedidos.cobrado_at', '<', $fin)
            ->select(
                'detalle_pedidos.nombre_producto as producto',
                DB::raw('sum(detalle_pedidos.cantidad) as cantidad'),
                DB::raw('sum(detalle_pedidos.subtotal) as ventas')
            )
            ->groupBy('detalle_pedidos.nombre_producto')
            ->orderByDesc('cantidad')
            ->limit(5)
            ->get();

        return response()->json([
            'desde'              => $desde,
            'hasta'              => $hasta,
            'pedidos_cobrados'   => $cantidad,
            'pedidos_entregados' => (clone $cobrados)->where('estado', 'entregado')->count(),
            'pedidos_cancelados' => $cancelados,
            'ventas'             => Money::decimal($ventas),
            'costo_vendido'      => Money::decimal($costo),
            'ganancia_bruta'     => Money::decimal($gananciaBruta),
            'gastos'             => Money::decimal($gastos),
            'ganancia_neta'      => Money::decimal($gananciaNeta),
            'ticket_promedio'    => Money::decimal($cantidad > 0 ? (int) round($ventas / $cantidad) : 0),
            'por_medio_pago'     => $porMedioPago,
            'top_productos'      => $topProductos,
        ]);
    }

}