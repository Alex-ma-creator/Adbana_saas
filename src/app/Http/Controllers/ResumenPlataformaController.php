<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResumenPlataformaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['dias' => ['sometimes', 'integer', 'in:7,30']]);
        $dias = (int) ($datos['dias'] ?? 30);
        $hoy = CarbonImmutable::today();
        $inicio = $hoy->subDays($dias - 1);
        $fin = $hoy->addDay();
        // Estas consultas globales pertenecen exclusivamente al guard de plataforma.
        $pedidos = DB::table('pedidos')->whereIn('restaurante_id', DB::table('restaurantes')->whereNull('deleted_at')->select('id'))->where('created_at', '>=', $inicio)->where('created_at', '<', $fin);
        $serie = $pedidos->clone()->selectRaw('DATE(created_at) as dia, COUNT(*) as cantidad')->groupByRaw('DATE(created_at)')->get()->keyBy('dia');
        $altas = DB::table('restaurantes')->whereNull('deleted_at')->where('created_at', '>=', $inicio)->where('created_at', '<', $fin)->selectRaw('DATE(created_at) as dia, COUNT(*) as cantidad')->groupByRaw('DATE(created_at)')->get()->keyBy('dia');
        $grafico = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = $inicio->addDays($i)->toDateString();
            $grafico[] = ['fecha' => $dia, 'pedidos' => (int) ($serie->get($dia)?->cantidad ?? 0), 'restaurantes' => (int) ($altas->get($dia)?->cantidad ?? 0)];
        }
        $actividad = DB::table('pedidos')->whereIn('restaurante_id', DB::table('restaurantes')->whereNull('deleted_at')->select('id'))->where('created_at', '>=', $hoy->subDays(6))->where('created_at', '<', $fin);
        $sinActividad = DB::table('restaurantes')->whereNull('deleted_at')->where('activo', true)->whereNotIn('id', $actividad->clone()->select('restaurante_id'))->count();

        return response()->json(['actualizado_at' => now()->toIso8601String(), 'dias' => $dias, 'restaurantes_total' => DB::table('restaurantes')->whereNull('deleted_at')->count(), 'restaurantes_activos' => DB::table('restaurantes')->whereNull('deleted_at')->where('activo', true)->count(),
            'restaurantes_con_pedidos_7dias' => $actividad->clone()->distinct()->count('restaurante_id'), 'altas_periodo' => $altas->sum('cantidad'), 'pedidos_periodo' => $pedidos->count(),
            'administradores_activos' => DB::table('administradores_plataforma')->where('activo', true)->whereNull('deleted_at')->count(), 'sin_pedidos_7dias' => $sinActividad, 'serie' => $grafico,
            'recientes' => DB::table('restaurantes')->whereNull('deleted_at')->select('id', 'nombre', 'slug', 'activo', 'created_at')->latest('id')->limit(6)->get(),
            'suscripciones' => ['estado' => 'sin_configurar'], 'incidencias' => ['estado' => 'sin_configurar']]);
    }
}
