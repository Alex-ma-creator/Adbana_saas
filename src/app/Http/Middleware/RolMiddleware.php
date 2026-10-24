<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RolMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->activo || ! in_array($user->rol, $roles, true)) {
            return response()->json(['message' => 'No tienes permiso para esta acción.'], 403);
        }

        if (! $user->esSuperadmin() && ! $user->restaurante?->activo) {
            return response()->json(['message' => 'El restaurante está desactivado.'], 403);
        }

        return $next($request);
    }
}