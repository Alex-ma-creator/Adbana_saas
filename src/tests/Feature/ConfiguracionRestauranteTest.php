<?php

namespace Tests\Feature;

use App\Models\CuentaMesa;
use App\Models\Mesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class ConfiguracionRestauranteTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function configuracion(array $cambios = []): array
    {
        return array_merge(['modalidades' => ['mesa', 'recojo'], 'descripcion' => 'Nuestra cocina', 'direccion' => 'Arequipa', 'horario' => '12:00 a 20:00', 'telefono' => '900000000', 'publicado' => false, 'cantidad_mesas' => 3], $cambios);
    }

    public function test_configuracion_repetida_no_duplica_mesas_y_no_publica_por_defecto(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        for ($i = 0; $i < 2; $i++) {
            $this->putJson('/api/configuracion', $this->configuracion())->assertOk()->assertJsonCount(3, 'mesas')->assertJsonPath('restaurante.configuracion.modalidades', ['mesa', 'recojo']);
        }
        $this->assertDatabaseCount('mesas', 3);
        $this->getJson('/api/restaurantes')->assertOk()->assertJsonPath('total', 0);
        $this->putJson('/api/configuracion', $this->configuracion(['cantidad_mesas' => 1]))->assertOk()->assertJsonCount(3, 'mesas');
    }

    public function test_solo_dueno_configura_su_negocio_y_no_modifica_mesas_ajenas(): void
    {
        $a = $this->crearRestaurante('A');
        $b = $this->crearRestaurante('B');
        $this->actuarComo($this->crearUsuario($a));
        $mesa = $this->putJson('/api/configuracion', $this->configuracion())->json('mesas.0.id');
        $this->actuarComo($this->crearUsuario($b));
        $this->patchJson('/api/configuracion/mesas/'.$mesa, ['nombre' => 'Terraza', 'zona' => 'Exterior', 'activa' => false])->assertNotFound();
        $this->actuarComo($this->crearUsuario($a, 'mozo'));
        $this->getJson('/api/configuracion')->assertForbidden();
        $this->putJson('/api/configuracion', $this->configuracion())->assertForbidden();
    }

    public function test_publicacion_exige_datos_y_oculta_suspendidos(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $this->putJson('/api/configuracion', $this->configuracion(['publicado' => true]))->assertUnprocessable();
        $this->crearProducto($r);
        $this->putJson('/api/configuracion', $this->configuracion(['publicado' => true]))->assertOk();
        $this->getJson('/api/restaurantes')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.id');
        $r->update(['activo' => false]);
        $this->getJson('/api/restaurantes')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_no_desactiva_modalidad_o_renombra_mesa_con_cuenta_abierta(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $id = $this->putJson('/api/configuracion', $this->configuracion())->json('mesas.0.id');
        CuentaMesa::create(['mesa' => 'Mesa 01', 'mesa_key' => 'mesa 01', 'abierta' => 1]);
        $this->putJson('/api/configuracion', $this->configuracion(['modalidades' => ['recojo']]))->assertUnprocessable();
        $this->patchJson('/api/configuracion/mesas/'.$id, ['nombre' => 'Otra', 'zona' => 'Salón', 'activa' => false])->assertUnprocessable();
        $this->assertTrue(Mesa::findOrFail($id)->activa);
    }

    public function test_pedido_rechaza_modalidad_desactivada_sin_descontar_stock(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $producto = $this->crearProducto($r);
        $this->putJson('/api/configuracion', $this->configuracion(['modalidades' => ['recojo'], 'cantidad_mesas' => 0]))->assertOk();
        $this->postJson('/api/carta/'.$r->slug.'/pedidos', ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente', 'tipo' => 'mesa', 'mesa' => 'Mesa 01', 'items' => [['producto_id' => $producto->id, 'cantidad' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->assertEquals(30, $producto->fresh()->stock);
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_seguimiento_privado_no_funciona_para_otro_restaurante(): void
    {
        $r = $this->crearRestaurante();
        $otro = $this->crearRestaurante('Otro');
        $producto = $this->crearProducto($r);
        $datos = ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Cliente privado', 'tipo' => 'recojo', 'items' => [['producto_id' => $producto->id, 'cantidad' => 1]]];
        $respuesta = $this->postJson('/api/carta/'.$r->slug.'/pedidos', $datos)->assertCreated();
        $token = urlencode($respuesta->json('seguimiento_token'));
        $this->getJson('/api/carta/'.$r->slug.'/seguimiento?token='.$token)->assertOk()->assertJsonPath('estado', 'pendiente')->assertJsonMissingPath('cliente_nombre');
        $this->getJson('/api/carta/'.$otro->slug.'/seguimiento?token='.$token)->assertNotFound();
        $this->getJson('/api/carta/'.$r->slug.'/seguimiento?token=falso')->assertNotFound();
        $this->postJson('/api/carta/'.$r->slug.'/pedidos', $datos)->assertCreated()->assertJsonPath('pedido_id', $respuesta->json('pedido_id'));
        $this->assertEquals(29, $producto->fresh()->stock);
    }

    public function test_foto_y_modelo_publicos_no_aceptan_esquemas_ejecutables(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $producto = $this->crearProducto($r);
        $this->getJson('/api/carta/'.$r->slug)->assertOk();
        $this->patchJson('/api/productos/'.$producto->id, ['imagen' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->patchJson('/api/productos/'.$producto->id, ['modelo_3d' => 'https://example.com/model.html'])->assertUnprocessable();
        $this->patchJson('/api/productos/'.$producto->id, ['modelo_3d' => '/demo-ar/assets/pizza.glb', 'imagen' => 'https://example.com/plato.webp'])->assertOk();
        $this->getJson('/api/carta/'.$r->slug)->assertOk()->assertJsonPath('carta.0.productos.0.modelo_3d', '/api/carta/'.$r->slug.'/productos/'.$producto->id.'/modelo');
    }

    public function test_qr_resuelve_solo_la_mesa_del_restaurante_y_no_contamina_cache(): void
    {
        $r = $this->crearRestaurante();
        $otro = $this->crearRestaurante('Otro');
        $this->actuarComo($this->crearUsuario($r));
        $mesa = $this->putJson('/api/configuracion', $this->configuracion())->assertOk()->json('mesas.0');
        $this->getJson('/api/carta/'.$r->slug.'?mesa='.$mesa['codigo'])->assertOk()->assertJsonPath('mesa.nombre', 'Mesa 01');
        $this->getJson('/api/carta/'.$r->slug)->assertOk()->assertJsonMissingPath('mesa');
        $this->getJson('/api/carta/'.$otro->slug.'?mesa='.$mesa['codigo'])->assertNotFound();
        $this->patchJson('/api/configuracion/mesas/'.$mesa['id'], ['nombre' => 'Mesa 01', 'zona' => 'Salón', 'activa' => false])->assertOk();
        $this->getJson('/api/carta/'.$r->slug.'?mesa='.$mesa['codigo'])->assertNotFound();
    }
}
