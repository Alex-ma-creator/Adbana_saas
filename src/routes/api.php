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
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware('rol:dueno,cajero,mozo,mozo_cajero')->group(function () {
        Route::post('/pedidos', [\App\Http\Controllers\VentaController::class, 'crear']);
        Route::get('/categorias', [CategoriaController::class, 'index']);
        Route::get('/productos', [ProductoController::class, 'index']);
        Route::get('/productos/{id}', [ProductoController::class, 'show']);
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
    });

    Route::middleware('rol:dueno,cajero,mozo,mozo_cajero,cocina')->group(function () {
        Route::get('/cocina', [\App\Http\Controllers\CocinaController::class, 'index'])->middleware('rol:dueno,cajero,cocina');
        Route::patch('/pedidos/{id}/estado', [PedidoController::class, 'cambiarEstado']);
    });

    Route::middleware('rol:dueno,cajero,mozo_cajero')->group(function () {
        Route::post('/ventas', [\App\Http\Controllers\VentaController::class, 'crear']);
        Route::post('/ventas/{id}/cobrar', [\App\Http\Controllers\VentaController::class, 'cobrar']);
        Route::get('/caja/actual', [\App\Http\Controllers\CajaController::class, 'actual']);
        Route::post('/caja/abrir', [\App\Http\Controllers\CajaController::class, 'abrir']);
        Route::post('/caja/movimientos', [\App\Http\Controllers\CajaController::class, 'movimiento']);
        Route::post('/caja/cerrar', [\App\Http\Controllers\CajaController::class, 'cerrar']);

        Route::get('/stock/alertas', [StockController::class, 'alertas']);
    });

    Route::middleware('rol:dueno')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::patch('/usuarios/{id}', [UsuarioController::class, 'update']);

        Route::post('/categorias', [CategoriaController::class, 'store']);
        Route::patch('/categorias/{id}', [CategoriaController::class, 'update']);
        Route::delete('/categorias/{id}', [CategoriaController::class, 'destroy']);

        Route::post('/productos', [ProductoController::class, 'store']);
        Route::patch('/productos/{id}', [ProductoController::class, 'update']);
        Route::delete('/productos/{id}', [ProductoController::class, 'destroy']);

        Route::get('/stock/movimientos', [StockController::class, 'movimientos']);
        Route::post('/stock/movimientos', [StockController::class, 'registrar']);

        Route::get('/gastos', [GastoController::class, 'index']);
        Route::post('/gastos', [GastoController::class, 'store']);

        Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
        Route::get('/auditoria', [AuditoriaController::class, 'index']);
    });
});