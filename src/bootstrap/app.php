<?php

use App\Http\Middleware\RolMiddleware;
use Illuminate\Auth\AuthenticationException;
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
        // La API no tiene una ruta de login HTML para redirigir invitados.
        $middleware->redirectGuestsTo(null);

        $middleware->alias([
            'rol' => RolMiddleware::class,
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

        // 403, 404, 405, 429 y otros errores HTTP.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api', 'api/*')) {
                return null;
            }

            $estado = $e->getStatusCode();

            $mensajes = [
                403 => 'No tienes permiso para esta acción.',
                404 => 'Recurso no encontrado.',
                405 => 'Método no permitido para esta ruta.',
                419 => 'La sesión expiró. Vuelve a intentarlo.',
                429 => 'Demasiadas peticiones. Espera un momento e intenta de nuevo.',
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
