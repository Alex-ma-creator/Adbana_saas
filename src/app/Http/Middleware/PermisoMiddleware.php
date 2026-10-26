<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermisoMiddleware
{

    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $user = $request->user();
        abort_unless($user && collect($permisos)->contains(fn ($p) => $user->tienePermiso($p)), 403);
        return $next($request);
    }
}
