<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class WebPagesController extends Controller
{
    public function administracion(Request $request): Response
    {
        $admin = $request->user('plataforma');
        if (! $admin || ! $admin->activo || $request->session()->get('plataforma_version') !== $admin->sesion_version) {
            return redirect('/login?administracion=1')->header('Cache-Control', 'no-store');
        }

        return response()->view('administracion')->header('Cache-Control', 'no-store');
    }

    public function restablecerPassword(): Response
    {
        return response()->view('panel')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    public function carta(string $slug): View
    {
        return view('carta', ['slug' => $slug]);
    }
}
