<?php

use App\Http\Controllers\AccesoPlataformaController;
use App\Http\Controllers\AccesoUnificadoController;
use App\Http\Controllers\AdministradorPlataformaController;
use App\Http\Controllers\AuditoriaPlataformaController;
use App\Http\Controllers\RestaurantePlataformaController;
use App\Http\Controllers\ResumenPlataformaController;
use App\Http\Middleware\AccesoPlataforma;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

Route::view('/', 'en-construccion');
Route::post('/acceso', AccesoUnificadoController::class)->middleware('throttle:login');
Route::get('/administracion', function (Request $request): Response {
    $admin = $request->user('plataforma');
    if (! $admin || ! $admin->activo || $request->session()->get('plataforma_version') !== $admin->sesion_version) {
        return redirect('/login?administracion=1')->header('Cache-Control', 'no-store');
    }

    return response()->view('administracion')->header('Cache-Control', 'no-store');
});
Route::prefix('administracion/api')->group(function () {
    Route::middleware(AccesoPlataforma::class)->group(function () {
        Route::get('/me', [AccesoPlataformaController::class, 'me']);
        Route::patch('/perfil', [AccesoPlataformaController::class, 'perfil'])->middleware('throttle:5,1');
        Route::post('/logout', [AccesoPlataformaController::class, 'logout']);
        Route::get('/resumen', ResumenPlataformaController::class)->middleware(AccesoPlataforma::class.':resumen');
        Route::get('/restaurantes', [RestaurantePlataformaController::class, 'index'])->middleware(AccesoPlataforma::class.':restaurantes');
        Route::patch('/restaurantes/{id}', [RestaurantePlataformaController::class, 'update'])->middleware(AccesoPlataforma::class.':gestionar_restaurantes');
        Route::delete('/restaurantes/{id}', [RestaurantePlataformaController::class, 'destroy'])->middleware(AccesoPlataforma::class.':eliminar_restaurantes');
        Route::get('/administradores', [AdministradorPlataformaController::class, 'index'])->middleware(AccesoPlataforma::class.':administradores');
        Route::post('/administradores', [AdministradorPlataformaController::class, 'store'])->middleware(AccesoPlataforma::class.':administradores');
        Route::patch('/administradores/{id}', [AdministradorPlataformaController::class, 'update'])->middleware(AccesoPlataforma::class.':administradores');
        Route::delete('/administradores/{id}', [AdministradorPlataformaController::class, 'destroy'])->middleware(AccesoPlataforma::class.':administradores');
        Route::get('/auditoria', [AuditoriaPlataformaController::class, 'index'])->middleware(AccesoPlataforma::class.':auditoria');
    });
});

Route::view('/panel', 'panel');
Route::view('/login', 'panel');
Route::get('/auth/google/redirect', [\App\Http\Controllers\GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [\App\Http\Controllers\GoogleAuthController::class, 'callback']);
Route::get('/restablecer-password', fn () => response()->view('panel')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store'))->name('password.reset');
Route::get('/c/{slug}', fn (string $slug) => view('carta', ['slug' => $slug]))->where('slug', '[a-z0-9\-]+');

Route::get('/fotos-platos/{archivo}', [\App\Http\Controllers\ProductoController::class, 'foto'])
    ->where('archivo', '[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)');
