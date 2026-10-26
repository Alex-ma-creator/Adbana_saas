<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Support\RestauranteContexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class ConsistenciaOperativaTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    public function test_reintento_publico_devuelve_el_mismo_pedido_incluso_sin_stock(): void
    {
        $r = $this->crearRestaurante();
        $p = $this->crearProducto($r, ['stock' => 1]);
        $body = ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo',
            'items' => [['producto_id' => $p->id, 'cantidad' => 1]]];
        $url = "/api/carta/{$r->slug}/pedidos";
        $id = $this->postJson($url, $body)->assertCreated()->json('pedido_id');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('pedido_id', $id);
        $this->postJson($url, array_replace($body, ['cliente_nombre' => 'Otro']))->assertStatus(409);
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertDatabaseHas('productos', ['id' => $p->id, 'stock' => 0]);
    }

    public function test_la_misma_clave_publica_se_aisla_por_restaurante(): void
    {
        $clave = (string) Str::uuid();
        foreach (['Uno', 'Dos'] as $nombre) {
            $r = $this->crearRestaurante($nombre);
            $p = $this->crearProducto($r);
            $this->postJson("/api/carta/{$r->slug}/pedidos", [
                'clave_operacion' => $clave, 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo',
                'items' => [['producto_id' => $p->id, 'cantidad' => 1]],
            ])->assertCreated();
        }
        $this->assertDatabaseCount('pedidos', 2);
    }

    public function test_sin_contexto_no_se_pueden_leer_pedidos_y_el_contexto_se_limpia_tras_error(): void
    {
        $r = $this->crearRestaurante();
        $otro = $this->crearRestaurante('Otro');
        foreach ([$r, $otro] as $restaurante) {
            Pedido::create(['restaurante_id' => $restaurante->id, 'cliente_nombre' => 'Cliente', 'tipo' => 'recojo', 'total' => 0]);
        }
        Auth::forgetGuards();
        $contexto = app(RestauranteContexto::class);
        $this->assertSame(1, $contexto->ejecutar($r->id, fn () => Pedido::count()));
        try {
            $contexto->ejecutar($otro->id, function () { throw new LogicException('prueba'); });
        } catch (LogicException $e) {
            $this->assertSame('prueba', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        Pedido::count();
    }

    public function test_cobrar_no_retira_de_cocina_y_no_permite_cancelar_sin_devolucion(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $p = $this->crearProducto($r);
        $this->actuarComo($u);
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 0])->assertCreated();
        $id = $this->postJson('/api/pedidos', ['clave_operacion' => (string) Str::uuid(),
            'cliente_nombre' => 'Cliente', 'tipo' => 'recojo', 'items' => [['producto_id' => $p->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        foreach (['confirmado', 'preparando'] as $estado) {
            $this->patchJson("/api/pedidos/$id/estado", ['estado' => $estado])->assertOk();
        }
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertOk()->assertJsonPath('estado', 'preparando');
        $this->getJson('/api/cocina')->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/pedidos/$id/estado", ['estado' => 'cancelado'])->assertUnprocessable();
        $this->postJson('/api/caja/cerrar', ['efectivo_contado' => 0])->assertOk();
        $this->postJson("/api/ventas/$id/cobrar", ['medio_pago' => 'yape'])->assertOk();
        $this->assertDatabaseHas('productos', ['id' => $p->id, 'stock' => 29]);
    }

    public function test_reporte_usa_fecha_de_cobro_y_excluye_el_limite_del_dia_siguiente(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $this->actuarComo($u);
        foreach (['2026-10-26 00:00:00', '2026-10-26 23:59:59', '2026-10-27 00:00:00'] as $fecha) {
            Pedido::create(['cliente_nombre' => 'Cliente', 'tipo' => 'recojo', 'estado' => 'preparando',
                'total' => '10.00', 'medio_pago' => 'yape', 'created_at' => '2026-10-25 12:00:00', 'cobrado_at' => $fecha])->forceFill(['created_at' => '2026-10-25 12:00:00'])->save();
        }
        $this->getJson('/api/reportes/resumen?desde=2026-10-26&hasta=2026-10-26')->assertOk()
            ->assertJsonPath('ventas', '20.00')->assertJsonPath('pedidos_cobrados', 2)->assertJsonPath('pedidos_entregados', 0);
    }
}
