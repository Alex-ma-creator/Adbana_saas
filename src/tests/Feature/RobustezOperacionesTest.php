<?php

namespace Tests\Feature;

use App\Models\Pedido;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class RobustezOperacionesTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function pedido(int $producto): array
    {
        return ['clave_operacion' => (string) Str::uuid(), 'cliente_nombre' => 'Ana', 'tipo' => 'recojo', 'items' => [['producto_id' => $producto, 'cantidad' => 1]]];
    }

    public function test_pos_rechaza_clave_reutilizada_con_otro_contenido(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $p = $this->crearProducto($r);
        $this->actuarComo($u);
        $body = $this->pedido($p->id);
        $id = $this->postJson('/api/pedidos', $body)->assertCreated()->json('id');
        $this->postJson('/api/pedidos', $body)->assertCreated()->assertJsonPath('id', $id);
        $body['items'][0]['cantidad'] = 2;
        $this->postJson('/api/pedidos', $body)->assertStatus(409);
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseHas('productos', ['id' => $p->id, 'stock' => 29]);
    }

    public function test_movimiento_stock_es_idempotente_y_detecta_conflicto(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $p = $this->crearProducto($r);
        $this->actuarComo($u);
        $body = ['clave_operacion' => (string) Str::uuid(), 'producto_id' => $p->id, 'tipo' => 'entrada', 'cantidad' => 15];
        $primero = $this->postJson('/api/stock/movimientos', $body)->assertCreated()->json();
        $this->postJson('/api/stock/movimientos', $body)->assertCreated()->assertExactJson($primero);
        $this->postJson('/api/stock/movimientos', array_replace($body, ['cantidad' => 20]))->assertStatus(409);
        $this->assertDatabaseHas('productos', ['id' => $p->id, 'stock' => 45]);
        $this->assertDatabaseCount('movimientos_stock', 1);
    }

    public function test_stock_invalido_no_consume_la_clave(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $p = $this->crearProducto($r, ['stock' => 0]);
        $this->actuarComo($u);
        $body = ['clave_operacion' => (string) Str::uuid(), 'producto_id' => $p->id, 'tipo' => 'salida', 'cantidad' => 1, 'motivo' => 'Prueba'];
        $this->postJson('/api/stock/movimientos', $body)->assertUnprocessable();
        $this->assertDatabaseCount('operaciones_idempotentes', 0);
        $p->update(['stock' => 1]);
        $this->postJson('/api/stock/movimientos', $body)->assertCreated();
    }

    public function test_movimiento_caja_repetido_no_duplica_salida(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => 100])->assertCreated();
        $body = ['clave_operacion' => (string) Str::uuid(), 'tipo' => 'salida', 'monto' => '50.00', 'motivo' => 'Compra'];
        $this->postJson('/api/caja/movimientos', $body)->assertCreated()->assertJsonPath('efectivo_esperado', '50.00');
        $this->postJson('/api/caja/movimientos', $body)->assertCreated()->assertJsonPath('efectivo_esperado', '50.00');
        $this->postJson('/api/caja/movimientos', array_replace($body, ['monto' => '25.00']))->assertStatus(409);
        $this->assertDatabaseCount('movimientos_caja', 1);
    }

    public function test_movimientos_exigen_clave_uuid(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        $this->postJson('/api/caja/movimientos', ['tipo' => 'entrada', 'monto' => 5, 'motivo' => 'Fondo'])->assertUnprocessable()->assertJsonValidationErrors('clave_operacion');
        $this->postJson('/api/stock/movimientos', [])->assertUnprocessable()->assertJsonValidationErrors('clave_operacion');
    }

    public function test_precio_y_costo_rechazan_precision_adicional(): void
    {
        $r = $this->crearRestaurante();
        $this->actuarComo($this->crearUsuario($r));
        foreach (['precio', 'costo'] as $campo) {
            foreach (['5.999', 5.999] as $valor) {
                $this->postJson('/api/productos', array_replace(['nombre' => 'Cerveza', 'precio' => 6, 'costo' => 3], [$campo => $valor]))->assertUnprocessable()->assertJsonValidationErrors($campo);
            }
        }
        $id = $this->postJson('/api/productos', ['nombre' => 'Cerveza', 'precio' => '6.50', 'costo' => 3])->assertCreated()->json('id');
        $this->patchJson('/api/productos/'.$id, ['precio' => '2.123'])->assertUnprocessable();
    }

    public function test_clientes_del_mismo_wifi_tienen_cupos_distintos(): void
    {
        Cache::flush();
        $r = $this->crearRestaurante();
        $p = $this->crearProducto($r, ['stock' => 100]);
        $url = '/api/carta/'.$r->slug.'/pedidos';
        $cliente = (string) Str::uuid();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($url, $this->pedido($p->id), ['X-Adbana-Cliente' => $cliente])->assertCreated();
        }
        $this->postJson($url, $this->pedido($p->id), ['X-Adbana-Cliente' => $cliente])->assertStatus(429);
        $this->postJson($url, $this->pedido($p->id), ['X-Adbana-Cliente' => (string) Str::uuid()])->assertCreated();
    }

    public function test_tope_por_ip_frena_rotacion_de_identidades(): void
    {
        Cache::flush();
        config(['adbana.limite_pedidos_ip' => 2]);
        $r = $this->crearRestaurante();
        $p = $this->crearProducto($r);
        $url = '/api/carta/'.$r->slug.'/pedidos';
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($url, $this->pedido($p->id), ['X-Adbana-Cliente' => (string) Str::uuid()])->assertCreated();
        }
        $this->postJson($url, $this->pedido($p->id), ['X-Adbana-Cliente' => (string) Str::uuid()])->assertStatus(429);
    }

    public function test_reset_no_enumera_correos_y_revoca_tokens(): void
    {
        Cache::flush();
        Notification::fake();
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $u->createToken('antiguo');
        $response = $this->postJson('/api/auth/recuperar', ['email' => $u->email])->assertOk()->json();
        $this->postJson('/api/auth/recuperar', ['email' => 'desconocido@example.com'])->assertOk()->assertExactJson($response);
        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $body = ['email' => $u->email, 'token' => $token, 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'];
        $this->postJson('/api/auth/restablecer', $body)->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/auth/restablecer', $body)->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'clave12345'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'NuevaClave123'])->assertOk();
    }

    public function test_sesiones_solo_propias_y_caducidad(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $otro = $this->crearUsuario($r);
        $ajeno = $otro->createToken('ajeno');
        $login = $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'clave12345'])->assertOk()->json('token');
        $u->createToken('expirado', ['*'], now()->subMinute());
        $vigente = $u->createToken('otro', ['*'], now()->addHour());
        $this->withToken($login)->getJson('/api/auth/sesiones')->assertOk()->assertJsonCount(2);
        $this->withToken($login)->deleteJson('/api/auth/sesiones/'.$ajeno->accessToken->id)->assertNotFound();
        $this->withToken($login)->postJson('/api/auth/cerrar-otras')->assertOk();
        $this->assertSame(1, $u->tokens()->count());
        app('auth')->forgetGuards();
        $this->withToken($vigente->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_mesas_resumen_incluye_pedidos_fuera_de_la_primera_pagina(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $this->actuarComo($u);
        for ($i = 0; $i < 25; $i++) {
            Pedido::create(['cliente_nombre' => 'Ana', 'tipo' => 'mesa', 'mesa' => '01', 'total' => '10.00', 'estado' => 'pendiente']);
        }
        $this->getJson('/api/mesas')->assertOk()->assertJsonPath('data.0.pedidos', 25)->assertJsonPath('data.0.pendiente', '250.00');
        $this->getJson('/api/pedidos?mesa=01&page=2')->assertOk()->assertJsonCount(5, 'data');
    }
}
