<?php

namespace Tests\Feature;

use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class AislamientoTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private $restauranteA;

    private $duenoA;

    private $duenoB;

    private $productoA;

    private $categoriaA;

    private $pedidoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restauranteA = $this->crearRestaurante('Picanteria A');
        $this->duenoA = $this->crearUsuario($this->restauranteA);
        $this->productoA = $this->crearProducto($this->restauranteA, ['nombre' => 'Rocoto']);
        $this->categoriaA = Categoria::create(['restaurante_id' => $this->restauranteA->id, 'nombre' => 'Fondos']);
        $restauranteB = $this->crearRestaurante('Chicharroneria B');
        $this->duenoB = $this->crearUsuario($restauranteB);
        // El pedido del restaurante A entra por la carta pública, como en la vida real.
        $this->pedidoId = $this->postJson("/api/carta/{$this->restauranteA->slug}/pedidos", ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Ana', 'tipo' => 'recojo', 'items' => [['producto_id' => $this->productoA->id, 'cantidad' => 1]]])->assertCreated()->json('pedido_id');
        $this->actuarComo($this->duenoB);
    }

    public function test_dueno_b_no_ve_ni_modifica_productos_de_a(): void
    {
        $this->getJson("/api/productos/{$this->productoA->id}")->assertNotFound();
        $this->patchJson("/api/productos/{$this->productoA->id}", ['precio' => 1])->assertNotFound();
        $this->deleteJson("/api/productos/{$this->productoA->id}")->assertNotFound();
        $this->getJson('/api/productos')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('productos', ['id' => $this->productoA->id, 'precio' => 18.5]);
    }

    public function test_dueno_b_no_puede_usar_categorias_de_a(): void
    {
        $this->postJson('/api/productos', ['categoria_id' => $this->categoriaA->id, 'nombre' => 'Cruce', 'precio' => 5])->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
        $this->patchJson("/api/categorias/{$this->categoriaA->id}", ['nombre' => 'Hackeada'])->assertNotFound();
        $this->deleteJson("/api/categorias/{$this->categoriaA->id}")->assertNotFound();
        $this->assertDatabaseHas('categorias', ['id' => $this->categoriaA->id, 'nombre' => 'Fondos']);
    }

    public function test_dueno_b_no_puede_mover_el_stock_de_a(): void
    {
        $this->postJson('/api/stock/movimientos', ['clave_operacion' => (string) Str::uuid(), 'producto_id' => $this->productoA->id, 'tipo' => 'entrada', 'cantidad' => 50])->assertNotFound();
        // 30 iniciales menos 1 del pedido de prueba.
        $this->assertDatabaseHas('productos', ['id' => $this->productoA->id, 'stock' => 29]);
    }

    public function test_dueno_b_no_ve_ni_cambia_pedidos_de_a(): void
    {
        $this->getJson("/api/pedidos/{$this->pedidoId}")->assertNotFound();
        $this->patchJson("/api/pedidos/{$this->pedidoId}/estado", ['estado' => 'cancelado', 'motivo' => 'Cancelación de prueba'])->assertNotFound();
        $this->getJson('/api/pedidos')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('pedidos', ['id' => $this->pedidoId, 'estado' => 'pendiente']);
    }

    public function test_dueno_b_ve_reportes_y_auditoria_en_cero(): void
    {
        $this->llevarPedidoAEntregado($this->pedidoId, $this->duenoA);
        $this->patchJson("/api/productos/{$this->productoA->id}", ['precio' => 20])->assertOk();
        // El dueño A sí ve su venta y su cambio de precio.
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '18.50');
        $this->getJson('/api/auditoria')->assertOk()->assertJsonCount(1, 'data');
        // El dueño B ve todo en cero.
        $this->actuarComo($this->duenoB);
        $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('ventas', '0.00')->assertJsonPath('pedidos_entregados', 0);
        $this->getJson('/api/auditoria')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/gastos')->assertOk()->assertJsonCount(0, 'data');
    }
}
