<?php

namespace App\Http\Middleware;

use App\Support\ControlResiliencia;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Observabilidad, tiempos de respuesta y logging estructurado en JSON Lines para todos los entornos.
 * En entorno local mantiene además la cabecera Server-Timing para diagnóstico SQL.
 */
class TiemposRespuesta
{
    public function handle(Request $request, Closure $next): Response
    {
        $inicio = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
        $request->attributes->set('_req_start', $inicio);
        $requestId = ControlResiliencia::requestId($request);

        if (app()->environment('local')) {
            $request->attributes->set('_timing_enabled', true);
            $request->attributes->set('_timing_sql_ms', 0.0);
            $request->attributes->set('_timing_sql_count', 0);
        }

        $response = $next($request);

        if ($response instanceof Response && ! headers_sent()) {
            $elapsedMs = round(max(0.01, (microtime(true) - $inicio) * 1000), 2);
            $status = $response->getStatusCode();

            $bottleneckStage = (string) ($response->headers->get('X-Bottleneck-Stage')
                ?? $request->attributes->get('_bottleneck_stage', 'none'));
            $failureReason = (string) ($response->headers->get('X-Failure-Reason')
                ?? $request->attributes->get('_failure_reason', 'NONE'));

            $response->headers->set('X-Request-Id', $requestId);
            $response->headers->set('X-Response-Time-Ms', number_format($elapsedMs, 2, '.', ''));
            $response->headers->set('X-Bottleneck-Stage', $bottleneckStage);
            $response->headers->set('X-Failure-Reason', $failureReason);

            if (app()->environment('local')) {
                $response->headers->set('Server-Timing', sprintf(
                    'app;dur=%.2f, db;dur=%.2f;desc="%d consultas"',
                    $elapsedMs,
                    (float) $request->attributes->get('_timing_sql_ms', 0.0),
                    (int) $request->attributes->get('_timing_sql_count', 0),
                ));
            }

            $esContencion = (bool) $request->attributes->get('_contained_error', false)
                || $failureReason !== 'NONE';

            $event = 'http.request';
            $level = 'info';

            if ($esContencion) {
                $esCuelloBotella = in_array($failureReason, [
                    'DB_CONCURRENCY_SHEDDING',
                    'DB_LOCK_TIMEOUT',
                    'DB_POOL_SATURATED',
                    'NGINX_FPM_QUEUE_SATURATED',
                ], true);
                $event = $esCuelloBotella ? 'bottleneck.detected' : 'error.contained';
                $level = 'warning';
            } elseif ($status >= 500) {
                $event = 'error.contained';
                $level = 'error';
            }

            ControlResiliencia::registrarLogEstructurado($level, $event, [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $status,
                'response_time_ms' => $elapsedMs,
                'bottleneck_stage' => $bottleneckStage,
                'failure_reason' => $failureReason,
                'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
            ]);
        }

        return $response;
    }
}
