<?php

use App\Http\Middleware\PermisoMiddleware;
use App\Http\Middleware\RolMiddleware;
use App\Http\Middleware\TiemposRespuesta;
use App\Http\Middleware\VerificarCorsEstricto;
use App\Support\ControlResiliencia;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(VerificarCorsEstricto::class);
        $middleware->append(TiemposRespuesta::class);
        $middleware->redirectGuestsTo(null);

        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: array_values(array_filter(array_map('trim', explode(',', $proxies)))),
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        $middleware->alias([
            'rol' => RolMiddleware::class,
            'permiso' => PermisoMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda la API responde sus errores en JSON, con o sin cabecera Accept.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api', 'api/*') || $request->expectsJson()
        );

        // 401: sin sesión o con token inválido.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api', 'api/*')) {
                return response()->json(['message' => 'No autenticado. Inicia sesión para continuar.'], 401);
            }
        });

        // 429: Rate limiting contenido con diagnóstico estructurado y cabecera Retry-After.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $headers = $e->getHeaders();
            $retryAfterSec = max(1, (int) ($headers['Retry-After'] ?? 1));

            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return ControlResiliencia::respuestaContenida(
                    request: $request,
                    status: 429,
                    codigoFallo: 'RATE_LIMITED',
                    motivoCaida: 'Límite de tasa alcanzado: se contuvo el exceso de peticiones para proteger la capacidad del servidor.',
                    cuelloBotella: 'php_fpm_worker_pool',
                    reintentable: true,
                    retryAfterMs: $retryAfterSec * 1000,
                    message: 'Demasiadas peticiones. Espera un momento e intenta de nuevo.',
                    extraHeaders: $headers,
                );
            }

            return null;
        });

        // 503: LockTimeoutException (contención de bloqueo distribuido / caché).
        $exceptions->render(function (LockTimeoutException $e, Request $request) {
            $motivo = 'Cuello de botella controlado: tiempo máximo de espera de bloqueo agotado bajo alta concurrencia.';

            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return ControlResiliencia::respuestaContenida(
                    request: $request,
                    status: 503,
                    codigoFallo: 'DB_LOCK_TIMEOUT',
                    motivoCaida: $motivo,
                    cuelloBotella: 'redis_cache',
                    reintentable: true,
                    retryAfterMs: 300,
                );
            }

            return response()->view('errors.503', [
                'motivo_caida' => $motivo,
                'codigo_fallo' => 'DB_LOCK_TIMEOUT',
                'cuello_de_botella' => 'redis_cache',
                'request_id' => ControlResiliencia::requestId($request),
                'tiempo_respuesta_ms' => ControlResiliencia::elapsedMs($request),
            ], 503);
        });

        // 503: Caída o saturación de Redis (RedisException / Predis).
        $exceptions->render(function (Throwable $e, Request $request) {
            $esRedis = $e instanceof \RedisException
                || str_contains($e::class, 'Redis')
                || str_contains($e::class, 'Predis');

            if (! $esRedis) {
                return null;
            }

            $motivo = 'Servicio Redis temporalmente no disponible o saturado: contención fail-fast activada para evitar bloqueo de workers PHP-FPM.';

            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return ControlResiliencia::respuestaContenida(
                    request: $request,
                    status: 503,
                    codigoFallo: 'REDIS_UNAVAILABLE',
                    motivoCaida: $motivo,
                    cuelloBotella: 'redis_cache',
                    reintentable: true,
                    retryAfterMs: 500,
                );
            }

            return response()->view('errors.503', [
                'motivo_caida' => $motivo,
                'codigo_fallo' => 'REDIS_UNAVAILABLE',
                'cuello_de_botella' => 'redis_cache',
                'request_id' => ControlResiliencia::requestId($request),
                'tiempo_respuesta_ms' => ControlResiliencia::elapsedMs($request),
            ], 503);
        });

        // 503: Contención de errores esperados en PostgreSQL (Lock/Statement Timeout, Deadlock, Pool saturado o caída de conexión).
        $exceptions->render(function (PDOException $e, Request $request) {
            $codigoSql = (string) $e->getCode();
            $msg = strtolower($e->getMessage());

            $esTimeoutLock = in_array($codigoSql, ['55P03', '57014', '40P01'], true)
                || str_contains($msg, 'lock timeout')
                || str_contains($msg, 'statement timeout')
                || str_contains($msg, 'canceling statement due to')
                || str_contains($msg, 'deadlock detected');

            $codigosPool = ['53300', '08000', '08001', '08003', '08004', '08006', '08007', '57P01', '57P02', '57P03', '2002'];
            $esPoolOConexion = ! ($e instanceof QueryException)
                || in_array($codigoSql, $codigosPool, true)
                || str_contains($msg, 'too many connections')
                || str_contains($msg, 'remaining connection slots')
                || str_contains($msg, 'connection refused')
                || str_contains($msg, 'could not connect')
                || str_contains($msg, 'server closed the connection');

            if (! $esTimeoutLock && ! $esPoolOConexion) {
                return null;
            }

            $codigoFallo = $esTimeoutLock ? 'DB_LOCK_TIMEOUT' : 'DB_POOL_SATURATED';
            $motivo = $esTimeoutLock
                ? 'Cuello de botella controlado en PostgreSQL RDS: tiempo máximo de espera de bloqueo de fila (SELECT ... FOR UPDATE / lock_timeout 1800ms) excedido bajo alta concurrencia.'
                : 'Cuello de botella controlado en PostgreSQL RDS: pool de conexiones saturado o base de datos temporalmente inaccesible. Respuesta fail-fast para proteger el servidor.';

            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return ControlResiliencia::respuestaContenida(
                    request: $request,
                    status: 503,
                    codigoFallo: $codigoFallo,
                    motivoCaida: $motivo,
                    cuelloBotella: 'postgresql_lock_for_update',
                    reintentable: true,
                    retryAfterMs: $esTimeoutLock ? 250 : 500,
                );
            }

            $requestId = ControlResiliencia::requestId($request);
            $elapsedMs = ControlResiliencia::elapsedMs($request);

            return response()->view('errors.503', [
                'motivo_caida' => $motivo,
                'codigo_fallo' => $codigoFallo,
                'cuello_de_botella' => 'postgresql_lock_for_update',
                'request_id' => $requestId,
                'tiempo_respuesta_ms' => $elapsedMs,
            ], 503, [
                'X-Request-Id' => $requestId,
                'X-Response-Time-Ms' => number_format($elapsedMs, 2, '.', ''),
                'X-Bottleneck-Stage' => 'postgresql_lock_for_update',
                'X-Failure-Reason' => $codigoFallo,
            ]);
        });

        // 403, 404, 405, 409, 419, 429, 503 y otros errores HTTP.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api', 'api/*')) {
                return null;
            }

            $estado = $e->getStatusCode();

            if ($estado === 503) {
                return ControlResiliencia::respuestaContenida(
                    request: $request,
                    status: 503,
                    codigoFallo: 'DB_POOL_SATURATED',
                    motivoCaida: $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Servicio temporalmente en contención por alta carga. Intenta de nuevo en unos instantes.',
                    cuelloBotella: 'php_fpm_worker_pool',
                    reintentable: true,
                    retryAfterMs: 300,
                    message: 'Servicio temporalmente no disponible. Intenta de nuevo en unos instantes.',
                    extraHeaders: $e->getHeaders(),
                );
            }

            $mensajes = [
                403 => 'No tienes permiso para esta acción.',
                404 => 'Recurso no encontrado.',
                409 => 'La operación entra en conflicto con un pedido anterior. Usa la misma clave solo para el mismo pedido.',
                405 => 'Método no permitido para esta ruta.',
                419 => 'La sesión expiró. Vuelve a intentarlo.',
                429 => 'Demasiadas peticiones. Espera un momento e intenta de nuevo.',
                503 => 'Servicio temporalmente no disponible. Intenta de nuevo en unos instantes.',
            ];

            $mensaje = $mensajes[$estado]
                ?? ($estado >= 500 ? 'Error interno del servidor.' : 'No se pudo procesar la solicitud.');

            return response()->json(['message' => $mensaje], $estado, $e->getHeaders());
        });

        // 500: en producción (APP_DEBUG=false) nunca se muestran detalles internos.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            if ($request->is('api', 'api/*')) {
                if (config('app.debug')) {
                    return null;
                }

                return response()->json(['message' => 'Error interno del servidor.'], 500);
            }

            if (! config('app.debug')) {
                $requestId = ControlResiliencia::requestId($request);
                $elapsedMs = ControlResiliencia::elapsedMs($request);

                return response()->view('errors.500', [
                    'motivo_caida' => 'Error interno contenido en el servidor de aplicación. El incidente fue registrado en los logs estructurados.',
                    'codigo_fallo' => 'INTERNAL_SERVER_ERROR',
                    'cuello_de_botella' => 'php_fpm_worker_pool',
                    'request_id' => $requestId,
                    'tiempo_respuesta_ms' => $elapsedMs,
                ], 500);
            }

            return null;
        });
    })->create();
