<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class RolesOperativosTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private function preparar(): array
    {
        $restaurante = $this->crearRestaurante();
        $mozo = $this->crearUsuario($restaurante, 'mozo');
        $cocina = $this->crearUsuario($restaurante, 'cocina');
        $producto = $this->crearProducto($restaurante);
        return [$restaurante, $mozo, $cocina, $producto];
    }

    public function test_mozo_toma_pedidos_sin_costos_ni_permisos_de_caja(): void
    {
        [$restaurante, $mozo, $cocina, $producto] = $this->preparar();
        $this->actuarComo($mozo);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('permisos.caja', false);
        $id = $this->postJson('/api/pedidos', [
            'clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Mesa 4', 'tipo' => 'mesa', 'mesa' => '4',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertCreated()->assertJsonMissingPath('detalles.0.costo_unitario')->json('id');
        $this->getJson('/api/productos')->assertOk()->assertJsonMissingPath('data.0.costo');
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertForbidden();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertForbidden();
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'confirmado'])->assertOk();
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'cancelado'])->assertForbidden();
        $this->getJson('/api/reportes/resumen')->assertForbidden();
    }

    public function test_cocina_solo_recibe_comandas_sin_importes_ni_contactos(): void
    {
        [$restaurante, $mozo, $cocina, $producto] = $this->preparar();
        $this->actuarComo($mozo);
        $id = $this->postJson('/api/pedidos', [
            'clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'confirmado'])->assertOk();
        $this->actuarComo($cocina);
        $this->getJson('/api/cocina')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.total')->assertJsonMissingPath('data.0.cliente_nombre')
            ->assertJsonMissingPath('data.0.detalles.0.costo_unitario');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'preparando'])->assertOk()
            ->assertJsonMissingPath('total')->assertJsonMissingPath('cliente_nombre');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'listo'])->assertOk();
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'cancelado'])->assertForbidden();
        $this->getJson('/api/pedidos')->assertForbidden();
        $this->getJson('/api/productos')->assertForbidden();
        $this->getJson('/api/caja/actual')->assertForbidden();
    }

    public function test_dueno_crea_personal_de_su_restaurante_y_no_otorga_superadmin(): void
    {
        [$restaurante] = $this->preparar();
        $this->actuarComo($this->crearUsuario($restaurante));
        foreach (['mozo', 'cocina'] as $rol) {
            $id = $this->postJson('/api/usuarios', ['name' => $rol, 'email' => "$rol@prueba.com", 'password' => 'clave12345', 'rol' => $rol])
                ->assertCreated()->assertJsonPath('rol', $rol)->assertJsonPath('restaurante_id', $restaurante->id)->json('id');
            $this->patchJson("/api/usuarios/$id", ['activo' => false])->assertOk();
        }
        $this->postJson('/api/usuarios', ['name' => 'Intento', 'email' => 'intento@prueba.com', 'password' => 'clave12345', 'rol' => 'superadmin'])
            ->assertCreated()->assertJsonPath('rol', 'cajero');
    }

    public function test_cocina_no_ve_ni_modifica_comandas_de_otro_restaurante(): void
    {
        [$restaurante, $mozo, $cocina] = $this->preparar();
        $otro = $this->crearRestaurante('Otro');
        $pedido = \App\Models\Pedido::create(['restaurante_id' => $otro->id, 'cliente_nombre' => 'Ajeno', 'tipo' => 'recojo', 'estado' => 'confirmado', 'total' => 0]);
        $this->actuarComo($cocina);
        $this->getJson('/api/cocina')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/pedidos/{$pedido->id}/estado", ['estado' => 'preparando'])->assertNotFound();
    }

    public function test_mozo_con_cobro_registra_dinero_en_su_turno_sin_ver_costos(): void
    {
        [$restaurante, $mozo, $cocina, $producto] = $this->preparar();
        $operador = $this->crearUsuario($restaurante, 'mozo_cajero');
        $this->actuarComo($operador);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('permisos.caja', true);
        $caja = $this->postJson('/api/caja/abrir', ['saldo_inicial' => 20])->assertCreated()->json('id');
        $id = $this->postJson('/api/pedidos', [
            'clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Para llevar', 'tipo' => 'recojo',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertCreated()->assertJsonMissingPath('detalles.0.costo_unitario')->json('id');
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => 20])
            ->assertOk()->assertJsonPath('vuelto', '1.50')->assertJsonPath('caja_id', $caja)
            ->assertJsonMissingPath('detalles.0.costo_unitario');
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '38.50');
        $this->assertDatabaseHas('cajas', ['id' => $caja, 'user_id' => $operador->id]);
        $this->getJson('/api/reportes/resumen')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/cocina')->assertForbidden();
    }
}
