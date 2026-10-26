<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/registro-dueno', [AuthController::class, 'registroDueno'])
    ->middleware('throttle:10,1');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::get('/carta/{slug}', [CartaController::class, 'mostrar']);

Route::post('/carta/{slug}/pedidos', [CartaController::class, 'crearPedido'])
    ->middleware('throttle:pedidos-publicos');

Route::middleware('auth:sanctum')->group(function () {
    Route::patch('/perfil', [\App\Http\Controllers\PerfilController::class, 'update'])->middleware('rol:dueno,cajero,mozo,mozo_cajero,cocina');
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/usuarios/permisos', [UsuarioController::class, 'catalogo'])->middleware('permiso:equipo');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:equipo');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('permiso:equipo');
    Route::patch('/usuarios/{id}', [UsuarioController::class, 'update'])->middleware('permiso:equipo');
    Route::post('/pedidos', [\App\Http\Controllers\VentaController::class, 'crear'])->middleware('permiso:pedidos');
    Route::post('/ventas', [\App\Http\Controllers\VentaController::class, 'crear'])->middleware('permiso:pedidos');
    Route::get('/categorias', [CategoriaController::class, 'index'])->middleware('permiso:pedidos,productos,stock');
    Route::get('/productos', [ProductoController::class, 'index'])->middleware('permiso:pedidos,productos,stock');
    Route::get('/productos/{id}', [ProductoController::class, 'show'])->middleware('permiso:pedidos,productos,stock');
    Route::get('/pedidos', [PedidoController::class, 'index'])->middleware('permiso:pedidos,caja,cancelar');
    Route::get('/pedidos/{id}', [PedidoController::class, 'show'])->middleware('permiso:pedidos,caja,cancelar');
    Route::get('/cocina', [\App\Http\Controllers\CocinaController::class, 'index'])->middleware('permiso:cocina');
    Route::patch('/pedidos/{id}/estado', [PedidoController::class, 'cambiarEstado'])->middleware('permiso:pedidos,cocina,cancelar');
    Route::post('/ventas/{id}/cobrar', [\App\Http\Controllers\VentaController::class, 'cobrar'])->middleware('permiso:caja');
    Route::get('/caja/actual', [\App\Http\Controllers\CajaController::class, 'actual'])->middleware('permiso:caja');
    Route::post('/caja/abrir', [\App\Http\Controllers\CajaController::class, 'abrir'])->middleware('permiso:caja');
    Route::post('/caja/movimientos', [\App\Http\Controllers\CajaController::class, 'movimiento'])->middleware('permiso:caja');
    Route::post('/caja/cerrar', [\App\Http\Controllers\CajaController::class, 'cerrar'])->middleware('permiso:caja');
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
    Route::post('/gastos', [GastoController::class, 'store'])->middleware('permiso:gastos');
    Route::get('/reportes/dashboard', \App\Http\Controllers\DashboardController::class)->middleware('permiso:reportes');
    Route::get('/reportes/resumen', [ReporteController::class, 'resumen'])->middleware('permiso:reportes');
    Route::get('/auditoria', [AuditoriaController::class, 'index'])->middleware('rol:dueno');
});
