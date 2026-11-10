<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class ReporteTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private $restaurante;
    private $dueno;
    private $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = $this->crearRestaurante('Picanteria');
        $this->dueno = $this->crearUsuario($this->restaurante);
        $this->producto = $this->crearProducto($this->restaurante, [
            'precio' => 18.50,
            'costo'  => 9.20,
            'stock'  => 100,
        ]);
    }

    private function pedir(int $cantidad): int
    {
        return $this->postJson("/api/carta/{$this->restaurante->slug}/pedidos", [
            'clave_operacion' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_nombre' => 'Ana',
            'tipo'           => 'recojo',
            'items'          => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ])->assertCreated()->json('pedido_id');
    }

    public function test_calcula_ventas_costo_ganancia_bruta_y_neta(): void
    {
        $this->llevarPedidoAEntregado($this->pedir(2), $this->dueno);

        $this->postJson('/api/gastos', [
            'descripcion' => 'Balon de gas',
            'categoria'   => 'servicios',
            'monto'       => 12.50,
        ])->assertCreated()->assertJsonPath('fecha', now()->toDateString());

        $this->assertDatabaseHas('gastos', [
            'user_id'        => $this->dueno->id,
            'restaurante_id' => $this->restaurante->id,
            'monto'          => 12.50,
        ]);

        $this->getJson('/api/reportes/resumen')
            ->assertOk()
            ->assertJsonPath('pedidos_entregados', 1)
            ->assertJsonPath('ventas', '37.00')
            ->assertJsonPath('costo_vendido', '18.40')
            ->assertJsonPath('ganancia_bruta', '18.60')
            ->assertJsonPath('gastos', '12.50')
            ->assertJsonPath('ganancia_neta', '6.10')
            ->assertJsonPath('ticket_promedio', '37.00')
            ->assertJsonPath('por_medio_pago.0.medio_pago', 'yape')
            ->assertJsonPath('top_productos.0.cantidad', 2);
    }

    public function test_solo_cuentan_los_pedidos_entregados(): void
    {
        $this->pedir(1); // queda pendiente
        $cancelado = $this->pedir(1);
        $entregado = $this->pedir(3);

        $this->actuarComo($this->dueno);
        $this->patchJson("/api/pedidos/{$cancelado}/estado", ['estado' => 'cancelado', 'motivo' => 'Cancelación de prueba'])->assertOk();
        $this->llevarPedidoAEntregado($entregado, $this->dueno);

        $this->getJson('/api/reportes/resumen')
            ->assertOk()
            ->assertJsonPath('pedidos_entregados', 1)
            ->assertJsonPath('pedidos_cancelados', 1)
            ->assertJsonPath('ventas', '55.50');
    }

    public function test_subir_precios_despues_no_cambia_las_ventas_pasadas(): void
    {
        $this->llevarPedidoAEntregado($this->pedir(2), $this->dueno);

        $this->patchJson("/api/productos/{$this->producto->id}", ['precio' => 99, 'costo' => 50])->assertOk();

        $this->getJson('/api/reportes/resumen')
            ->assertOk()
            ->assertJsonPath('ventas', '37.00')
            ->assertJsonPath('costo_vendido', '18.40')
            ->assertJsonPath('ganancia_bruta', '18.60');
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->llevarPedidoAEntregado($this->pedir(1), $this->dueno);

        $this->getJson('/api/reportes/resumen?desde=2020-01-01&hasta=2020-01-31')
            ->assertOk()
            ->assertJsonPath('ventas', '0.00');

        $hoy = now()->toDateString();

        $this->getJson("/api/reportes/resumen?desde={$hoy}&hasta={$hoy}")
            ->assertOk()
            ->assertJsonPath('ventas', '18.50');

        $this->getJson('/api/reportes/resumen?desde=2026-10-10&hasta=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hasta');
    }

    public function test_valida_los_gastos(): void
    {
        $this->actuarComo($this->dueno);

        $this->postJson('/api/gastos', ['monto' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('descripcion');

        $this->postJson('/api/gastos', ['descripcion' => 'X', 'monto' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');

        $this->postJson('/api/gastos', ['descripcion' => 'X', 'monto' => -3])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');

        $this->postJson('/api/gastos', ['descripcion' => 'X', 'monto' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
    }
}