<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Gasto;
use App\Models\Pedido;
use App\Support\LibroCobros;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->startOfDay()->addDay();

        // El filtro por restaurante lo aplica solo el modelo Pedido.
        $cobrados = Pedido::whereNotNull('cobrado_at')
            ->where('cobrado_at', '>=', $inicio)
            ->where('cobrado_at', '<', $fin);

        $libro = LibroCobros::consulta($inicio->toDateTimeString(), $fin->toDateTimeString());
        $ventas = Money::cents((clone $libro)->sum('monto'));
        $brutos = Money::cents((clone $libro)->where('monto', '>', 0)->sum('monto'));
        $devoluciones = -Money::cents((clone $libro)->where('monto', '<', 0)->sum('monto'));
        $cantidad = (clone $cobrados)->count();

        $porPedido = (clone $libro)->selectRaw('pedido_id, SUM(monto) as importe')->groupBy('pedido_id');
        $costos = DB::table('detalle_pedidos')->selectRaw('pedido_id, SUM(cantidad * costo_unitario) as costo')->whereIn('pedido_id', (clone $libro)->select('pedido_id'))->groupBy('pedido_id');
        $costo = (int) round((float) DB::query()->fromSub($porPedido, 'pagos')->join('pedidos', 'pedidos.id', '=', 'pagos.pedido_id')->joinSub($costos, 'costos', 'costos.pedido_id', '=', 'pedidos.id')->selectRaw('COALESCE(SUM(costos.costo * pagos.importe / NULLIF(pedidos.total, 0)), 0) as costo')->value('costo') * 100);
        $mermas = DB::table('eventos_operacion')->where('restaurante_id', $request->user()->restaurante_id)->whereIn('accion', ['cancelacion_merma', 'devolucion_merma', 'merma_stock'])->where('created_at', '>=', $inicio)->where('created_at', '<', $fin)->pluck('datos')->sum(fn ($datos) => (int) (json_decode($datos ?? '{}', true)['costo_merma_centavos'] ?? 0));

        $gastos = Money::cents(Gasto::whereBetween('fecha', [$desde, $hasta])->sum('monto'));

        $gananciaBruta = $ventas - $costo;
        $gananciaNeta = $gananciaBruta - $gastos - $mermas;

        $cancelados = Pedido::where('estado', 'cancelado')
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->count();

        $porMedioPago = (clone $libro)->selectRaw('medio_pago, COUNT(DISTINCT pedido_id) as pedidos, SUM(monto) as total')->groupBy('medio_pago')->get();
        $topProductos = DetallePedido::join('pedidos', 'pedidos.id', '=', 'detalle_pedidos.pedido_id')
            ->where('pedidos.restaurante_id', $request->user()->restaurante_id)
            ->whereNull('pedidos.anulado_at')->where('pedidos.estado', '!=', 'cancelado')->whereNotNull('pedidos.cobrado_at')
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
            'desde' => $desde,
            'hasta' => $hasta,
            'pedidos_cobrados' => $cantidad,
            'pedidos_entregados' => (clone $cobrados)->where('estado', 'entregado')->count(),
            'pedidos_cancelados' => $cancelados,
            'cobros_brutos' => Money::decimal($brutos),
            'devoluciones' => Money::decimal($devoluciones),
            'costo_mermas' => Money::decimal($mermas),
            'saldo_por_cobrar' => Money::decimal(Money::cents(Pedido::whereNull('anulado_at')->where('estado', '!=', 'cancelado')->selectRaw('COALESCE(SUM(total - pagado),0) as saldo')->value('saldo'))),
            'ventas' => Money::decimal($ventas),
            'costo_vendido' => Money::decimal($costo),
            'ganancia_bruta' => Money::decimal($gananciaBruta),
            'gastos' => Money::decimal($gastos),
            'ganancia_neta' => Money::decimal($gananciaNeta),
            'ticket_promedio' => Money::decimal($cantidad > 0 ? (int) round($ventas / $cantidad) : 0),
            'por_medio_pago' => $porMedioPago,
            'top_productos' => $topProductos,
        ]);
    }
}
