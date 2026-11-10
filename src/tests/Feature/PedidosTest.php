<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class PedidosTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private $restaurante;
    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = $this->crearRestaurante('Picanteria');
        $this->dueno = $this->crearUsuario($this->restaurante);
    }

    private function pedir(array $items, array $extra = [])
    {
        return $this->postJson("/api/carta/{$this->restaurante->slug}/pedidos", array_merge([
            'clave_operacion' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_nombre' => 'Ana',
            'tipo'           => 'recojo',
            'items'          => $items,
        ], $extra));
    }

    public function test_el_total_se_calcula_con_los_precios_del_servidor(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['precio' => 18.50]);

        // El cliente intenta mandar un precio falso de 1.
        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 1]])
            ->assertCreated()
            ->assertJsonPath('total', '37.00');
    }

    public function test_el_pedido_descuenta_stock_y_registra_el_movimiento(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['stock' => 10]);

        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 3]])->assertCreated();

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 7]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'tipo'        => 'salida',
            'cantidad'    => 3,
            'motivo'      => 'pedido',
        ]);
    }

    public function test_el_detalle_guarda_el_precio_y_costo_del_momento(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['precio' => 18.50, 'costo' => 9.20]);

        $pedidoId = $this->pedir([['producto_id' => $producto->id, 'cantidad' => 1]])
            ->assertCreated()
            ->json('pedido_id');

        // El dueño sube los precios después de la venta.
        $producto->update(['precio' => 25, 'costo' => 12]);

        $this->assertDatabaseHas('detalle_pedidos', [
            'pedido_id'       => $pedidoId,
            'precio_unitario' => 18.50,
            'costo_unitario'  => 9.20,
        ]);
    }

    public function test_si_falta_stock_no_queda_nada_a_medias(): void
    {
        $a = $this->crearProducto($this->restaurante, ['stock' => 10]);
        $b = $this->crearProducto($this->restaurante, ['stock' => 1]);

        $this->pedir([
            ['producto_id' => $a->id, 'cantidad' => 2],
            ['producto_id' => $b->id, 'cantidad' => 5],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        // Todo se revierte: ni stock descontado, ni pedido, ni movimientos.
        $this->assertDatabaseHas('productos', ['id' => $a->id, 'stock' => 10]);
        $this->assertDatabaseHas('productos', ['id' => $b->id, 'stock' => 1]);
        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    public function test_el_stock_nunca_queda_negativo(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['stock' => 5]);

        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 2]])->assertCreated();
        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 2]])->assertCreated();
        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 2]])->assertUnprocessable();

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 1]);
    }

    public function test_la_cantidad_agrupada_por_producto_no_supera_el_maximo(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['stock' => 200]);

        $this->pedir([
            ['producto_id' => $producto->id, 'cantidad' => 50],
            ['producto_id' => $producto->id, 'cantidad' => 50],
        ])->assertCreated();

        $this->pedir([
            ['producto_id' => $producto->id, 'cantidad' => 100],
            ['producto_id' => $producto->id, 'cantidad' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 100]);
    }

    public function test_cancelar_devuelve_el_stock_una_sola_vez(): void
    {
        $producto = $this->crearProducto($this->restaurante, ['stock' => 10]);

        $pedidoId = $this->pedir([['producto_id' => $producto->id, 'cantidad' => 4]])
            ->assertCreated()
            ->json('pedido_id');

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 6]);

        $this->actuarComo($this->dueno);

        $this->patchJson("/api/pedidos/{$pedidoId}/estado", ['estado' => 'cancelado', 'motivo' => 'Cancelación de prueba'])->assertOk();
        $this->patchJson("/api/pedidos/{$pedidoId}/estado", ['estado' => 'cancelado', 'motivo' => 'Cancelación de prueba'])->assertUnprocessable();

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 10]);
        $this->assertSame(1, DB::table('movimientos_stock')->where('tipo', 'entrada')->count());
    }

    public function test_no_se_puede_pedir_productos_ajenos_ni_inactivos(): void
    {
        $otro = $this->crearRestaurante('Otro');
        $productoAjeno = $this->crearProducto($otro);
        $inactivo = $this->crearProducto($this->restaurante, ['activo' => false]);

        $this->pedir([['producto_id' => $productoAjeno->id, 'cantidad' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->pedir([['producto_id' => $inactivo->id, 'cantidad' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_valida_los_datos_del_pedido(): void
    {
        $producto = $this->crearProducto($this->restaurante);

        // Delivery sin dirección ni teléfono.
        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 1]], ['tipo' => 'delivery'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direccion', 'cliente_telefono']);

        // Sin productos.
        $this->pedir([])->assertUnprocessable()->assertJsonValidationErrors('items');

        // Cantidad en cero.
        $this->pedir([['producto_id' => $producto->id, 'cantidad' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.cantidad');
    }

    public function test_los_pedidos_publicos_tienen_limite_de_peticiones(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/carta/{$this->restaurante->slug}/pedidos", [])->assertUnprocessable();
        }

        $this->postJson("/api/carta/{$this->restaurante->slug}/pedidos", [])->assertStatus(429);
    }
}