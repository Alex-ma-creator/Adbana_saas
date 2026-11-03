<?php

use App\Http\Middleware\RolMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $middleware->prepend(\App\Http\Middleware\VerificarCorsEstricto::class);
        $middleware->append(\App\Http\Middleware\TiemposRespuesta::class);

        // La API no tiene una ruta de login HTML para redirigir invitados.
        $middleware->redirectGuestsTo(null);

        $middleware->alias([
            'rol' => RolMiddleware::class,
            'permiso' => \App\Http\Middleware\PermisoMiddleware::class,
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

        // Caída de conexión a base de datos (503 Service Unavailable).
        $exceptions->render(function (PDOException $e, Request $request) {
            if ($e instanceof QueryException) {
                $codigoSql = (string) $e->getCode();
                $codigosConexion = ['08000', '08001', '08003', '08004', '08006', '08007', '57P01', '57P02', '57P03', '2002'];
                if (!in_array($codigoSql, $codigosConexion, true) && !str_contains(strtolower($e->getMessage()), 'connection refused') && !str_contains(strtolower($e->getMessage()), 'could not connect')) {
                    return null;
                }
            }

            if ($request->is('api', 'api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Servicio temporalmente no disponible. La base de datos no responde en este momento.',
                    'status' => 'degraded',
                ], 503);
            }

            return response()->view('errors.503', [], 503);
        });

        // 403, 404, 405, 429, 503 y otros errores HTTP.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api', 'api/*')) {
                return null;
            }

            $estado = $e->getStatusCode();

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
            if (! $request->is('api', 'api/*') || config('app.debug')) {
                return null;
            }

            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            return response()->json(['message' => 'Error interno del servidor.'], 500);
        });
    })->create();
