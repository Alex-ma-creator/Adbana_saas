<?php

namespace Tests\Concerns;

use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait PreparaDatos
{
    protected function crearRestaurante(string $nombre = 'Restaurante'): Restaurante
    {
        return Restaurante::create([
            'nombre' => $nombre,
            'slug'   => str($nombre)->slug()->toString() . '-' . uniqid(),
        ]);
    }

    protected function crearUsuario(Restaurante $restaurante, string $rol = 'dueno'): User
    {
        return User::create([
            'restaurante_id' => $restaurante->id,
            'name'           => ucfirst($rol) . ' de ' . $restaurante->nombre,
            'email'          => uniqid($rol . '_') . '@prueba.com',
            'password'       => 'clave12345',
            'rol'            => $rol,
            'activo'         => true,
        ]);
    }

    protected function crearProducto(Restaurante $restaurante, array $datos = []): Producto
    {
        return Producto::create(array_merge([
            'restaurante_id' => $restaurante->id,
            'nombre'         => 'Plato ' . uniqid(),
            'precio'         => 18.50,
            'costo'          => 9.20,
            'stock'          => 30,
            'stock_minimo'   => 5,
        ], $datos));
    }

    protected function actuarComo(User $usuario): void
    {
        Sanctum::actingAs($usuario);
    }

    protected function llevarPedidoAEntregado(int $pedidoId, User $dueno): void
    {
        $this->actuarComo($dueno);

        if (! \App\Models\Caja::where('user_id', $dueno->id)->whereNull('cerrada_at')->exists()) {
            $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        }
        foreach (['confirmado', 'preparando', 'listo'] as $estado) {
            $this->patchJson("/api/pedidos/{$pedidoId}/estado", ['estado' => $estado])->assertOk();
        }

        $this->patchJson("/api/pedidos/{$pedidoId}/estado", [
            'estado'     => 'entregado',
            'medio_pago' => 'yape',
        ])->assertOk();
    }
}