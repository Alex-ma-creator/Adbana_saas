<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Gasto;
use App\Models\Pedido;
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

        // El filtro por restaurante lo aplica solo el modelo Pedido.
        $entregados = Pedido::where('estado', 'entregado')
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta);

        $ventas   = (float) (clone $entregados)->sum('total');
        $cantidad = (clone $entregados)->count();

        $costo = (float) DetallePedido::whereIn('pedido_id', (clone $entregados)->select('id'))
            ->sum(DB::raw('cantidad * costo_unitario'));

        $gastos = (float) Gasto::whereBetween('fecha', [$desde, $hasta])->sum('monto');

        $gananciaBruta = $ventas - $costo;
        $gananciaNeta  = $gananciaBruta - $gastos;

        $cancelados = Pedido::where('estado', 'cancelado')
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
            ->count();

        $porMedioPago = (clone $entregados)
            ->select('medio_pago', DB::raw('count(*) as pedidos'), DB::raw('sum(total) as total'))
            ->groupBy('medio_pago')
            ->get();

        $topProductos = DetallePedido::join('pedidos', 'pedidos.id', '=', 'detalle_pedidos.pedido_id')
            ->where('pedidos.restaurante_id', $request->user()->restaurante_id)
            ->where('pedidos.estado', 'entregado')
            ->whereDate('pedidos.created_at', '>=', $desde)
            ->whereDate('pedidos.created_at', '<=', $hasta)
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
            'pedidos_entregados' => $cantidad,
            'pedidos_cancelados' => $cancelados,
            'ventas'             => $this->dinero($ventas),
            'costo_vendido'      => $this->dinero($costo),
            'ganancia_bruta'     => $this->dinero($gananciaBruta),
            'gastos'             => $this->dinero($gastos),
            'ganancia_neta'      => $this->dinero($gananciaNeta),
            'ticket_promedio'    => $this->dinero($cantidad > 0 ? $ventas / $cantidad : 0),
            'por_medio_pago'     => $porMedioPago,
            'top_productos'      => $topProductos,
        ]);
    }

    private function dinero(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }
}