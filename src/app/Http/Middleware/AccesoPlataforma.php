<?php

namespace App\Http\Middleware;

use App\Models\AdministradorPlataforma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AccesoPlataforma
{
    public function handle(Request $request, Closure $next, string $permiso = ''): Response
    {
        $sesion = $request->user('plataforma');
        $admin = $sesion ? AdministradorPlataforma::find($sesion->id) : null;
        abort_unless($admin, 401);
        abort_unless($request->session()->get('plataforma_version') === $admin->sesion_version, 401);
        Auth::guard('plataforma')->setUser($admin);
        abort_unless($admin->activo && ! $admin->trashed(), 403);
        if ($permiso !== '') {
            abort_unless($admin->tienePermiso($permiso), 403);
        }

        return $next($request);
    }
}
