<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Diagnóstico local: no expone SQL ni parámetros y no se activa en producción. */
class TiemposRespuesta
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return $next($request);
        }
        $request->attributes->set('_timing_enabled', true);
        $request->attributes->set('_timing_sql_ms', 0.0);
        $request->attributes->set('_timing_sql_count', 0);
        $inicio = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $response = $next($request);
        if ($response instanceof Response && ! headers_sent()) {
            $response->headers->set('Server-Timing', sprintf('app;dur=%.2f, db;dur=%.2f;desc="%d consultas"',
                (microtime(true) - $inicio) * 1000,
                $request->attributes->get('_timing_sql_ms', 0.0), $request->attributes->get('_timing_sql_count', 0)));
        }
        return $response;
    }
}
