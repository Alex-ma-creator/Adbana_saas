<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class OperacionRestauranteTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private User $dueno;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $r = $this->crearRestaurante();
        $this->dueno = $this->crearUsuario($r);
        $this->actuarComo($this->dueno);
        $this->producto = $this->crearProducto($r, ['precio' => 10, 'costo' => 4, 'stock' => 30, 'requiere_cocina' => false]);
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 100])->assertCreated();
    }

    private function pedido(array $extra = []): array
    {
        return $this->postJson('/api/pedidos', array_replace(['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'mesa', 'mesa' => 'Mesa 1', 'items' => [['producto_id' => $this->producto->id, 'cantidad' => 1]]], $extra))->assertCreated()->json();
    }

    private function pago(float $monto = 10, string $medio = 'efectivo'): array
    {
        return ['clave_operacion' => (string) Str::uuid(), 'pagos' => [['medio_pago' => $medio, 'monto' => $monto, 'recibido' => $monto]]];
    }

    public function test_jornada_con_entrega_antes_del_pago_abonos_combinados_y_cierre(): void
    {
        $a = $this->pedido();
        $b = $this->pedido(['mesa' => ' mesa 1 ']);
        $this->assertSame($a['cuenta_id'], $b['cuenta_id']);
        $cuenta = $a['cuenta_id'];
        foreach ([$a, $b] as $p) {
            $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => 'confirmado'])->assertOk();
            $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => 'entregado'])->assertOk()->assertJsonPath('saldo_pendiente', '10.00');
        }
        $this->postJson('/api/cuentas/'.$cuenta.'/cerrar')->assertUnprocessable();
        $abono = $this->pago(5);
        $this->postJson('/api/cuentas/'.$cuenta.'/cobrar', $abono)->assertOk()->assertJsonPath('pendiente', '15.00');
        $this->postJson('/api/cuentas/'.$cuenta.'/cobrar', $abono)->assertOk()->assertJsonPath('pendiente', '15.00');
        $abono['pagos'][0]['monto'] = 6;
        $this->postJson('/api/cuentas/'.$cuenta.'/cobrar', $abono)->assertConflict();
        $final = ['clave_operacion' => (string) Str::uuid(), 'pagos' => [['medio_pago' => 'efectivo', 'monto' => 5, 'recibido' => 10], ['medio_pago' => 'yape', 'monto' => 10]]];
        $this->postJson('/api/cuentas/'.$cuenta.'/cobrar', $final)->assertOk()->assertJsonPath('pendiente', '0.00');
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '110.00');
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '20.00')->assertJsonPath('costo_vendido', '8.00');
        $this->postJson('/api/cuentas/'.$cuenta.'/cerrar')->assertOk()->assertJsonPath('abierta', false);
        $nuevo = $this->pedido();
        $this->assertNotSame($cuenta, $nuevo['cuenta_id']);
        $this->postJson('/api/caja/cerrar', ['efectivo_contado' => 110])->assertOk()->assertJsonPath('diferencia', '0.00');
    }

    public function test_no_cocina_para_bebidas_y_plato_no_se_entrega_sin_preparar(): void
    {
        $bebida = $this->pedido();
        $this->producto->update(['requiere_cocina' => true]);
        $plato = $this->pedido();
        $this->getJson('/api/cocina')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plato['id']);
        $this->patchJson('/api/pedidos/'.$plato['id'].'/estado', ['estado' => 'confirmado'])->assertOk();
        $this->patchJson('/api/pedidos/'.$plato['id'].'/estado', ['estado' => 'entregado'])->assertUnprocessable();
        foreach (['preparando', 'listo', 'entregado'] as $estado) {
            $this->patchJson('/api/pedidos/'.$plato['id'].'/estado', ['estado' => $estado])->assertOk();
        }
        $this->postJson('/api/ventas/'.$plato['id'].'/cobrar', $this->pago())->assertOk();
    }

    public function test_cancelar_preparado_registra_merma_y_no_repone_stock(): void
    {
        $this->producto->update(['requiere_cocina' => true]);
        $p = $this->pedido();
        foreach (['confirmado', 'preparando'] as $estado) {
            $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => $estado])->assertOk();
        }
        $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => 'cancelado', 'motivo' => 'Cliente canceló', 'reponer_stock' => true])->assertUnprocessable();
        $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => 'cancelado', 'motivo' => 'Cliente canceló', 'reponer_stock' => false])->assertOk();
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 29]);
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('costo_mermas', '4.00')->assertJsonPath('ganancia_neta', '-4.00');
    }

    public function test_devolucion_idempotente_respeta_caja_y_no_borra_el_cobro(): void
    {
        $p = $this->pedido();
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', $this->pago())->assertOk();
        $d = ['clave_operacion' => (string) Str::uuid(), 'monto' => 10, 'medio_pago' => 'efectivo', 'motivo' => 'Producto devuelto intacto', 'anular' => true, 'reponer_stock' => true];
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', $d)->assertCreated();
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', $d)->assertCreated();
        $this->assertDatabaseCount('cobros', 2);
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 30]);
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '100.00');
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '0.00')->assertJsonPath('devoluciones', '10.00');
        $d['clave_operacion'] = (string) Str::uuid();
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', $d)->assertUnprocessable();
    }

    public function test_receta_comparte_botellas_con_cajas_y_conserva_consumo_historico(): void
    {
        $botella = $this->producto;
        $caja = $this->crearProducto($this->dueno->restaurante, ['precio' => 90, 'stock' => 0, 'requiere_cocina' => false]);
        $this->putJson('/api/productos/'.$caja->id.'/receta', ['insumos' => [['insumo_id' => $botella->id, 'cantidad' => 12]]])->assertOk()->assertJsonPath('stock_disponible', 2);
        $p = $this->pedido(['items' => [['producto_id' => $caja->id, 'cantidad' => 2]]]);
        $this->assertDatabaseHas('productos', ['id' => $botella->id, 'stock' => 6]);
        $this->pedido();
        $this->assertDatabaseHas('productos', ['id' => $botella->id, 'stock' => 5]);
        $this->putJson('/api/productos/'.$caja->id.'/receta', ['insumos' => []])->assertOk();
        $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => 'cancelado', 'motivo' => 'No se entregó la caja', 'reponer_stock' => true])->assertOk();
        $this->assertDatabaseHas('productos', ['id' => $botella->id, 'stock' => 29]);
    }

    public function test_transferencia_de_efectivo_es_pareada_y_no_crea_ventas(): void
    {
        $otro = $this->crearUsuario($this->dueno->restaurante, 'cajero');
        $this->actuarComo($otro);
        $destino = $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated()->json('id');
        $this->actuarComo($this->dueno);
        $d = ['clave_operacion' => (string) Str::uuid(), 'caja_destino_id' => $destino, 'monto' => 40, 'motivo' => 'Entrega física confirmada'];
        $this->postJson('/api/caja/transferir', $d)->assertCreated();
        $this->postJson('/api/caja/transferir', $d)->assertCreated();
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '60.00');
        $this->assertDatabaseCount('movimientos_caja', 2);
        $this->assertDatabaseCount('cobros', 0);
        $this->actuarComo($otro);
        $this->getJson('/api/caja/actual')->assertJsonPath('resumen.efectivo_esperado', '40.00');
    }

    public function test_aislamiento_de_cuenta_receta_y_devolucion_y_permisos(): void
    {
        $p = $this->pedido();
        $r = $this->crearRestaurante('Otro');
        $ajeno = $this->crearProducto($r);
        $u = $this->crearUsuario($r);
        $this->actuarComo($u);
        $this->getJson('/api/cuentas/'.$p['cuenta_id'])->assertNotFound();
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', ['clave_operacion' => (string) Str::uuid(), 'monto' => 10, 'medio_pago' => 'efectivo', 'motivo' => 'Prueba ajena', 'anular' => true, 'reponer_stock' => false])->assertNotFound();
        $this->putJson('/api/productos/'.$ajeno->id.'/receta', ['insumos' => [['insumo_id' => $this->producto->id, 'cantidad' => 1]]])->assertUnprocessable();
        $cajero = $this->crearUsuario($this->dueno->restaurante, 'cajero');
        $this->actuarComo($cajero);
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', [])->assertForbidden();
    }

    public function test_rechaza_sobrepago_y_no_deja_movimientos_parciales(): void
    {
        $p = $this->pedido();
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', $this->pago(11))->assertUnprocessable();
        $this->assertDatabaseCount('cobros', 0);
        $this->assertDatabaseHas('pedidos', ['id' => $p['id'], 'pagado' => 0]);
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', ['clave_operacion' => (string) Str::uuid(), 'pagos' => [['medio_pago' => 'yape', 'monto' => 5], ['medio_pago' => 'efectivo', 'monto' => 5, 'recibido' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('cobros', 0);
    }

    public function test_receta_publica_no_filtra_costos_y_descuenta_insumos(): void
    {
        $r = $this->dueno->restaurante;
        $this->producto->update(['es_insumo' => true]);
        $plato = $this->crearProducto($r, ['stock' => 0]);
        $this->putJson('/api/productos/'.$plato->id.'/receta', ['insumos' => [['insumo_id' => $this->producto->id, 'cantidad' => 2]]])->assertOk();
        $this->getJson('/api/stock/alertas')->assertOk()->assertExactJson([]);
        $this->getJson('/api/carta/'.$r->slug)->assertOk()->assertJsonCount(1, 'carta.0.productos')->assertJsonPath('carta.0.productos.0.disponible', true)->assertJsonMissingPath('carta.0.productos.0.costo');
        $this->postJson('/api/carta/'.$r->slug.'/pedidos', ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente web', 'tipo' => 'recojo', 'items' => [['producto_id' => $plato->id, 'cantidad' => 2]]])->assertCreated();
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 26]);
        $this->putJson('/api/productos/'.$plato->id.'/receta', ['insumos' => []])->assertOk();
        $this->deleteJson('/api/productos/'.$this->producto->id)->assertConflict();
        $this->putJson('/api/productos/'.$plato->id.'/receta', ['insumos' => [['insumo_id' => (string) $plato->id, 'cantidad' => 1]]])->assertUnprocessable();
    }

    public function test_abono_aparece_en_reporte_y_devolucion_preparada_registra_merma(): void
    {
        $this->producto->update(['requiere_cocina' => true]);
        $p = $this->pedido();
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', $this->pago(5))->assertOk()->assertJsonPath('saldo_pendiente', '5.00');
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '5.00')->assertJsonPath('costo_vendido', '2.00');
        foreach (['confirmado', 'preparando'] as $estado) {
            $this->patchJson('/api/pedidos/'.$p['id'].'/estado', ['estado' => $estado])->assertOk();
        }
        $this->postJson('/api/ventas/'.$p['id'].'/devoluciones', ['clave_operacion' => (string) Str::uuid(), 'monto' => 5, 'medio_pago' => 'efectivo', 'motivo' => 'Plato preparado cancelado', 'anular' => true, 'reponer_stock' => false])->assertCreated();
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '0.00')->assertJsonPath('costo_mermas', '4.00')->assertJsonPath('ganancia_neta', '-4.00');
    }

    public function test_cuenta_paginada_mantiene_total_de_todos_los_consumos(): void
    {
        $p = $this->pedido();
        for ($i = 0; $i < 24; $i++) {
            Pedido::create(['cuenta_id' => $p['cuenta_id'], 'cliente_nombre' => 'Cliente', 'tipo' => 'mesa', 'mesa' => 'Mesa 1', 'estado' => 'pendiente', 'total' => 10]);
        }
        $this->getJson('/api/cuentas/'.$p['cuenta_id'])->assertOk()->assertJsonCount(20, 'pedidos')->assertJsonPath('total', '250.00')->assertJsonPath('ultima_pagina', 2);
        $this->getJson('/api/cuentas/'.$p['cuenta_id'].'?page=2')->assertOk()->assertJsonCount(5, 'pedidos')->assertJsonPath('pendiente', '250.00');
    }

    public function test_reintento_de_cobro_legacy_no_oculta_cambio_de_medio(): void
    {
        $p = $this->pedido();
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', ['medio_pago' => 'yape'])->assertOk();
        $this->postJson('/api/ventas/'.$p['id'].'/cobrar', ['medio_pago' => 'tarjeta'])->assertConflict();
        $this->assertDatabaseCount('cobros', 1);
    }

    public function test_merma_manual_idempotente_se_incluye_en_resultados(): void
    {
        $datos = ['clave_operacion' => (string) Str::uuid(), 'producto_id' => $this->producto->id, 'tipo' => 'salida', 'cantidad' => 2, 'motivo' => 'Botellas rotas en almacén', 'es_merma' => true];
        $this->postJson('/api/stock/movimientos', $datos)->assertCreated();
        $this->postJson('/api/stock/movimientos', $datos)->assertCreated();
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('costo_mermas', '8.00')->assertJsonPath('ganancia_neta', '-8.00');
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 28]);
    }

    public function test_avisos_de_cocina_no_exponen_importes_ni_otros_restaurantes(): void
    {
        $p = $this->pedido();
        Pedido::whereKey($p['id'])->update(['estado' => 'listo']);
        $this->getJson('/api/avisos/listos')->assertOk()->assertJsonPath('0.id', $p['id'])->assertJsonMissingPath('0.total')->assertJsonMissingPath('0.saldo_pendiente');
        $otro = $this->crearUsuario($this->crearRestaurante('Otro'));
        $this->actuarComo($otro);
        $this->getJson('/api/avisos/listos')->assertOk()->assertExactJson([]);
    }

    public function test_listados_aceptan_busqueda_vacia_y_abiertos_en_orden_de_llegada(): void
    {
        $this->producto->update(['requiere_cocina' => true]);
        $primero = $this->pedido();
        $segundo = $this->pedido();
        foreach (['/api/pedidos?abiertos=1&q=', '/api/cocina?q='] as $ruta) {
            $this->getJson($ruta)->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.id', $primero['id'])->assertJsonPath('data.1.id', $segundo['id']);
        }
        $this->getJson('/api/pedidos?q=')->assertOk()->assertJsonPath('data.0.id', $segundo['id']);
        $this->getJson('/api/pedidos?q[]=invalido')->assertUnprocessable();
        $this->getJson('/api/cocina?q[]=invalido')->assertUnprocessable();
    }

    public function test_cocina_toma_pedido_publico_y_cliente_consulta_preparacion_y_listo(): void
    {
        $this->producto->update(['requiere_cocina' => true]);
        $restaurante = $this->dueno->restaurante;
        $pedido = $this->postJson('/api/carta/'.$restaurante->slug.'/pedidos', [
            'clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo',
            'items' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated()->json();
        $id = $pedido['pedido_id'];
        $seguimiento = '/api/carta/'.$restaurante->slug.'/seguimiento?token='.urlencode($pedido['seguimiento_token']);
        $this->actuarComo($this->crearUsuario($restaurante, 'cocina'));
        $this->getJson('/api/cocina?q=')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.total')->assertJsonMissingPath('data.0.cliente_telefono');
        foreach (['preparando', 'listo'] as $estado) {
            $this->patchJson('/api/pedidos/'.$id.'/estado', ['estado' => $estado])->assertOk()
                ->assertJsonPath('estado', $estado)->assertJsonMissingPath('total');
            $this->getJson($seguimiento)->assertOk()->assertJsonPath('estado', $estado)->assertJsonPath('pagado', '0.00');
        }
        $this->patchJson('/api/pedidos/'.$id.'/estado', ['estado' => 'preparando'])->assertUnprocessable();
        $this->patchJson('/api/pedidos/'.$id.'/estado', ['estado' => 'entregado'])->assertForbidden();
        $this->patchJson('/api/pedidos/'.$id.'/estado', ['estado' => 'cancelado', 'motivo' => 'Sin autorización'])->assertForbidden();
        $this->assertDatabaseHas('productos', ['id' => $this->producto->id, 'stock' => 29]);
    }

    public function test_tomar_pedido_exige_permiso_y_aislamiento_del_restaurante(): void
    {
        $this->producto->update(['requiere_cocina' => true]);
        $pedido = $this->pedido();
        $ruta = '/api/pedidos/'.$pedido['id'].'/estado';
        $this->actuarComo($this->crearUsuario($this->dueno->restaurante, 'mozo'));
        $this->patchJson($ruta, ['estado' => 'preparando'])->assertForbidden();
        $otro = $this->crearRestaurante('Otro restaurante');
        $this->actuarComo($this->crearUsuario($otro, 'cocina'));
        $this->patchJson($ruta, ['estado' => 'preparando'])->assertNotFound();
        $this->assertDatabaseHas('pedidos', ['id' => $pedido['id'], 'estado' => 'pendiente']);
    }
}
