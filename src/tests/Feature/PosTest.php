<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private $dueno;
    private $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $restaurante = $this->crearRestaurante();
        $this->dueno = $this->crearUsuario($restaurante);
        $this->producto = $this->crearProducto($restaurante, ['precio' => 8, 'costo' => 5, 'stock' => 20]);
        $this->actuarComo($this->dueno);
    }

    private function vender(array $extra = [])
    {
        return $this->postJson('/api/ventas', array_replace([
            'clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo',
            'items' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ], $extra));
    }

    public function test_venta_calcula_precio_stock_y_vuelto_en_servidor(): void
    {
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 100])->assertCreated();
        $id = $this->vender(['total' => 1])->assertCreated()->assertJsonPath('total', '8.00')->json('id');
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => '10.00'])
            ->assertOk()->assertJsonPath('vuelto', '2.00')->assertJsonPath('estado', 'pendiente');
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 19]);
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '108.00');
    }

    public function test_reintentar_venta_y_cobro_no_duplica_stock_ni_ingreso(): void
    {
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $clave = (string) Str::uuid();
        $id = $this->vender(['clave_operacion' => $clave])->assertCreated()->json('id');
        $this->vender(['clave_operacion' => $clave])->assertCreated()->assertJsonPath('id', $id);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => 10])->assertOk();
        }
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 19]);
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '8.00');
    }

    public function test_sin_caja_o_con_pago_insuficiente_no_cobra(): void
    {
        $id = $this->vender()->assertCreated()->json('id');
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => 10])->assertUnprocessable();
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => 7])->assertUnprocessable();
        $this->assertDatabaseHas('pedidos', ['id' => $id, 'cobrado_at' => null]);
    }

    public function test_cierre_separa_pagos_digitales_y_registra_diferencia(): void
    {
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 100])->assertCreated();
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 100])->assertUnprocessable();
        $id = $this->vender()->assertCreated()->json('id');
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertOk()->assertJsonPath('vuelto', '0.00');
        $this->postJson('/api/caja/movimientos', ['tipo' => 'salida', 'monto' => 20, 'motivo' => 'Compra'])->assertCreated();
        $this->postJson('/api/caja/cerrar', ['efectivo_contado' => 79])->assertOk()
            ->assertJsonPath('efectivo_esperado', '80.00')->assertJsonPath('diferencia', '-1.00');
        $this->postJson('/api/caja/cerrar', ['efectivo_contado' => 79])->assertUnprocessable();
    }

    public function test_venta_rechaza_stock_insuficiente_y_productos_ajenos(): void
    {
        $this->vender(['items' => [['producto_id' => $this->producto->id, 'cantidad' => 21]]])->assertUnprocessable();
        $otro = $this->crearProducto($this->crearRestaurante('Otro'));
        $this->vender(['items' => [['producto_id' => $otro->id, 'cantidad' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 20]);
    }

    public function test_no_se_puede_cobrar_un_pedido_ajeno_o_cancelado(): void
    {
        $id = $this->vender()->assertCreated()->json('id');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'cancelado'])->assertOk();
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertUnprocessable();
        $ajeno = $this->crearUsuario($this->crearRestaurante('Otro'));
        $this->actuarComo($ajeno);
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertNotFound();
    }

    public function test_el_cajero_vende_sin_recibir_costos(): void
    {
        $this->actuarComo($this->crearUsuario($this->dueno->restaurante, 'cajero'));
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $id = $this->vender()->assertCreated()->assertJsonMissingPath('detalles.0.costo_unitario')->json('id');
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertOk()->assertJsonMissingPath('detalles.0.costo_unitario');
    }

    public function test_el_panel_y_login_cargan(): void
    {
        $this->get('/panel')->assertOk()->assertSee('Punto de venta');
        $this->get('/login')->assertOk()->assertSee('Ingresar');
    }

    public function test_entrega_no_cobra_y_exige_pago_y_preparacion(): void
    {
        $id = $this->vender()->assertCreated()->json('id');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'entregado', 'medio_pago' => 'yape'])->assertUnprocessable();
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertOk()->assertJsonPath('estado', 'pendiente');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'entregado'])->assertUnprocessable();
        foreach (['confirmado', 'preparando', 'listo', 'entregado'] as $estado) {
            $this->patchJson("/api/pedidos/$id/estado", ['estado' => $estado])->assertOk();
        }
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.ventas_por_medio.yape', '8.00');
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'cancelado'])->assertUnprocessable();
    }

    public function test_importes_con_mas_de_dos_decimales_no_se_cobran(): void
    {
        $id = $this->vender()->assertCreated()->json('id');
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'efectivo', 'recibido' => '10.005'])->assertUnprocessable();
        $this->assertDatabaseHas('pedidos', ['id' => $id, 'cobrado_at' => null]);
    }
}
