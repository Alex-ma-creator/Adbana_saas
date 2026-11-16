<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke()
    {
        $inicio = microtime(true);
        $servicios = [
            'app' => ['status' => 'ok'],
            'database' => ['status' => 'ok', 'latency_ms' => 0.0],
            'cache' => ['status' => 'ok', 'driver' => config('cache.default'), 'latency_ms' => 0.0],
        ];
        $saludable = true;

        try {
            $t0 = microtime(true);
            DB::select('SELECT 1');
            $servicios['database']['latency_ms'] = round((microtime(true) - $t0) * 1000, 2);
        } catch (\Throwable $e) {
            $saludable = false;
            $servicios['database']['status'] = 'down';
            $servicios['database']['error'] = 'Sin conexión a PostgreSQL';
        }

        try {
            $t0 = microtime(true);
            $clave = 'healthcheck:ping';
            Cache::put($clave, 'ok', 10);
            $valor = Cache::get($clave);
            $servicios['cache']['latency_ms'] = round((microtime(true) - $t0) * 1000, 2);
            if ($valor !== 'ok') {
                $saludable = false;
                $servicios['cache']['status'] = 'degraded';
            }
        } catch (\Throwable $e) {
            $saludable = false;
            $servicios['cache']['status'] = 'down';
            $servicios['cache']['error'] = 'Sin conexión al motor de caché';
        }

        $codigo = $saludable ? 200 : 503;

        return response()->json([
            'status' => $saludable ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'total_ms' => round((microtime(true) - $inicio) * 1000, 2),
            'services' => $servicios,
        ], $codigo);
    }
}
