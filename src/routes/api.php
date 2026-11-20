<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CartaController;
use App\Http\Controllers\CatalogoRestaurantesController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\ConfiguracionRestauranteController;
use App\Http\Controllers\CuentaMesaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\ModeloCartaController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\RecetaController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\SeguimientoPedidoController;
use App\Http\Controllers\SeguridadCuentaController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/registro-dueno', [AuthController::class, 'registroDueno'])
    ->when(config('adbana.limite_login') > 0, fn ($r) => $r->middleware('throttle:' . config('adbana.limite_login') . ',1'));

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::post('/auth/recuperar', [SeguridadCuentaController::class, 'solicitar'])->middleware('throttle:3,1');
Route::post('/auth/restablecer', [SeguridadCuentaController::class, 'restablecer'])->middleware('throttle:5,1');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
Route::get('/restaurantes', [CatalogoRestaurantesController::class, 'index'])->middleware('throttle:120,1');
Route::get('/carta/{slug}/seguimiento', [SeguimientoPedidoController::class, 'show'])->middleware('throttle:seguimiento-publico');
Route::get('/carta/{slug}/productos/{id}/modelo', [ModeloCartaController::class, 'show']);
Route::get('/carta/{slug}', [CartaController::class, 'mostrar']);
Route::post('/carta/{slug}/pedidos', [CartaController::class, 'crearPedido'])->middleware('throttle:pedidos-publicos');

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('rol:dueno')->group(function () {
        Route::get('/configuracion', [ConfiguracionRestauranteController::class, 'show']);
        Route::put('/configuracion', [ConfiguracionRestauranteController::class, 'update']);
        Route::post('/configuracion/logo', [ConfiguracionRestauranteController::class, 'guardarLogo']);
        Route::patch('/configuracion/mesas/{id}', [ConfiguracionRestauranteController::class, 'mesa']);
        Route::delete('/configuracion/mesas/{id}', [ConfiguracionRestauranteController::class, 'eliminarMesa']);
        Route::get('/auditoria', [AuditoriaController::class, 'index']);
        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::get('/backups/{archivo}', [BackupController::class, 'descargar']);
    });

    Route::get('/avisos/listos', [PedidoController::class, 'listos'])->middleware('permiso:pedidos');
    Route::get('/caja/destinos', [CajaController::class, 'destinos'])->middleware('permiso:caja');
    Route::post('/caja/transferir', [CajaController::class, 'transferir'])->middleware('permiso:caja');
    Route::put('/productos/{id}/receta', [RecetaController::class, 'guardar'])->middleware('permiso:productos');
    Route::post('/ventas/{id}/devoluciones', [DevolucionController::class, 'store'])->middleware('permiso:devoluciones');

    Route::get('/cuentas', [CuentaMesaController::class, 'index'])->middleware('permiso:pedidos,caja');
    Route::post('/mesas/{id}/ocupar', [CuentaMesaController::class, 'ocupar'])->middleware('permiso:pedidos');
    Route::get('/cuentas/{id}', [CuentaMesaController::class, 'show'])->middleware('permiso:pedidos,caja');
    Route::post('/cuentas/{id}/cobrar', [CuentaMesaController::class, 'cobrar'])->middleware('permiso:caja,cobrar_efectivo');
    Route::post('/cuentas/{id}/cerrar', [CuentaMesaController::class, 'cerrar'])->middleware('permiso:pedidos');

    Route::patch('/perfil', [PerfilController::class, 'update'])->middleware('rol:dueno,cajero,mozo,mozo_cajero,cocina');
    Route::get('/auth/sesiones', [SeguridadCuentaController::class, 'sesiones']);
    Route::delete('/auth/sesiones/{id}', [SeguridadCuentaController::class, 'revocar']);
    Route::post('/auth/cerrar-otras', [SeguridadCuentaController::class, 'cerrarOtras']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/usuarios/permisos', [UsuarioController::class, 'catalogo'])->middleware('permiso:equipo');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:equipo');
    Route::get('/usuarios/{id}', [UsuarioController::class, 'show'])->middleware('permiso:equipo');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('permiso:equipo');
    Route::patch('/usuarios/{id}', [UsuarioController::class, 'update'])->middleware('permiso:equipo');
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy'])->middleware('permiso:equipo');

    Route::post('/pedidos', [VentaController::class, 'crear'])->middleware('permiso:pedidos');
    Route::post('/ventas', [VentaController::class, 'crear'])->middleware('permiso:pedidos');

    Route::get('/categorias', [CategoriaController::class, 'index'])->middleware('permiso:pedidos,productos,stock');
    Route::get('/categorias/{id}', [CategoriaController::class, 'show'])->middleware('permiso:pedidos,productos,stock');

    Route::get('/productos', [ProductoController::class, 'index'])->middleware('permiso:pedidos,productos,stock');
    Route::get('/productos/{id}', [ProductoController::class, 'show'])->middleware('permiso:pedidos,productos,stock');

    Route::get('/pedidos', [PedidoController::class, 'index'])->middleware('permiso:pedidos,caja,cancelar');
    Route::get('/pedidos/{id}', [PedidoController::class, 'show'])->middleware('permiso:pedidos,caja,cancelar');

    Route::get('/mesas', [MesaController::class, 'index'])->middleware('permiso:pedidos');
    Route::get('/cocina', [CocinaController::class, 'index'])->middleware('permiso:cocina');
    Route::patch('/pedidos/{id}/estado', [PedidoController::class, 'cambiarEstado'])->middleware('permiso:pedidos,cocina,cancelar');
    Route::post('/ventas/{id}/cobrar', [VentaController::class, 'cobrar'])->middleware('permiso:caja');

    Route::get('/caja/actual', [CajaController::class, 'actual'])->middleware('permiso:caja');
    Route::post('/caja/abrir', [CajaController::class, 'abrir'])->middleware('permiso:caja');
    Route::post('/caja/movimientos', [CajaController::class, 'movimiento'])->middleware('permiso:caja');
    Route::post('/caja/cerrar', [CajaController::class, 'cerrar'])->middleware('permiso:caja');
    Route::get('/stock/alertas', [StockController::class, 'alertas'])->middleware('permiso:caja,stock');

    Route::middleware('permiso:productos')->group(function () {
        Route::post('/categorias', [CategoriaController::class, 'store']);
        Route::patch('/categorias/{id}', [CategoriaController::class, 'update']);
        Route::delete('/categorias/{id}', [CategoriaController::class, 'destroy']);
        Route::post('/productos', [ProductoController::class, 'store']);
        Route::patch('/productos/{id}', [ProductoController::class, 'update']);
        Route::delete('/productos/{id}', [ProductoController::class, 'destroy']);
    });

    Route::get('/stock/movimientos', [StockController::class, 'movimientos'])->middleware('permiso:stock');
    Route::post('/stock/movimientos', [StockController::class, 'registrar'])->middleware('permiso:stock');

    Route::get('/gastos', [GastoController::class, 'index'])->middleware('permiso:gastos');
    Route::get('/gastos/{id}', [GastoController::class, 'show'])->middleware('permiso:gastos');
    Route::post('/gastos', [GastoController::class, 'store'])->middleware('permiso:gastos');
    Route::patch('/gastos/{id}', [GastoController::class, 'update'])->middleware('permiso:gastos');
    Route::delete('/gastos/{id}', [GastoController::class, 'destroy'])->middleware('permiso:gastos');

    Route::get('/reportes/dashboard', DashboardController::class)->middleware('permiso:reportes');
    Route::get('/reportes/resumen', [ReporteController::class, 'resumen'])->middleware('permiso:reportes');
});
