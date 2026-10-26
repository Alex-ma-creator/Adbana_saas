<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private $restaurante;
    private $dueno;
    private $cajero;
    private $producto;
    private $pedidoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = $this->crearRestaurante('Picanteria');
        $this->dueno = $this->crearUsuario($this->restaurante, 'dueno');
        $this->cajero = $this->crearUsuario($this->restaurante, 'cajero');
        $this->producto = $this->crearProducto($this->restaurante, ['precio' => 18.50, 'costo' => 9.20]);

        $this->pedidoId = $this->postJson("/api/carta/{$this->restaurante->slug}/pedidos", [
            'clave_operacion' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_nombre' => 'Ana',
            'tipo'           => 'recojo',
            'items'          => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated()->json('pedido_id');
    }

    public static function rutasSoloDueno(): array
    {
        return [
            'listar usuarios'       => ['GET', '/api/usuarios', []],
            'crear usuario'         => ['POST', '/api/usuarios', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'clave12345']],
            'editar usuario'        => ['PATCH', '/api/usuarios/1', ['activo' => false]],
            'crear categoria'       => ['POST', '/api/categorias', ['nombre' => 'X']],
            'editar categoria'      => ['PATCH', '/api/categorias/1', ['nombre' => 'X']],
            'borrar categoria'      => ['DELETE', '/api/categorias/1', []],
            'crear producto'        => ['POST', '/api/productos', ['nombre' => 'X', 'precio' => 1]],
            'editar producto'       => ['PATCH', '/api/productos/1', ['precio' => 1]],
            'borrar producto'       => ['DELETE', '/api/productos/1', []],
            'ver movimientos stock' => ['GET', '/api/stock/movimientos', []],
            'mover stock'           => ['POST', '/api/stock/movimientos', ['producto_id' => 1, 'tipo' => 'entrada', 'cantidad' => 1]],
            'listar gastos'         => ['GET', '/api/gastos', []],
            'crear gasto'           => ['POST', '/api/gastos', ['descripcion' => 'X', 'monto' => 1]],
            'ver reporte'           => ['GET', '/api/reportes/resumen', []],
            'ver auditoria'         => ['GET', '/api/auditoria', []],
        ];
    }

    #[DataProvider('rutasSoloDueno')]
    public function test_el_cajero_no_accede_a_rutas_del_dueno(string $metodo, string $ruta, array $datos): void
    {
        $this->actuarComo($this->cajero);

        $this->json($metodo, $ruta, $datos)->assertForbidden();
    }

    public function test_sin_token_no_se_accede_a_nada_protegido(): void
    {
        $this->getJson('/api/productos')->assertUnauthorized();
        $this->getJson('/api/pedidos')->assertUnauthorized();
        $this->getJson('/api/usuarios')->assertUnauthorized();
        $this->getJson('/api/reportes/resumen')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_el_cajero_puede_ver_lo_que_necesita_para_atender(): void
    {
        $this->actuarComo($this->cajero);

        $this->getJson('/api/productos')->assertOk();
        $this->getJson('/api/categorias')->assertOk();
        $this->getJson('/api/pedidos')->assertOk();
        $this->getJson('/api/stock/alertas')->assertOk();
        $this->patchJson("/api/pedidos/{$this->pedidoId}/estado", ['estado' => 'confirmado'])
            ->assertOk()
            ->assertJsonMissingPath('detalles.0.costo_unitario');
    }

    public function test_el_cajero_no_ve_costos_pero_el_dueno_si(): void
    {
        $this->actuarComo($this->cajero);

        $this->getJson('/api/productos')->assertOk()->assertJsonMissingPath('data.0.costo');
        $this->getJson("/api/productos/{$this->producto->id}")->assertOk()->assertJsonMissingPath('costo');
        $this->getJson("/api/pedidos/{$this->pedidoId}")->assertOk()->assertJsonMissingPath('detalles.0.costo_unitario');

        $this->actuarComo($this->dueno);

        $this->getJson('/api/productos')->assertOk()->assertJsonPath('data.0.costo', '9.20');
        $this->getJson("/api/pedidos/{$this->pedidoId}")->assertOk()->assertJsonPath('detalles.0.costo_unitario', '9.20');
    }

    public function test_el_dueno_solo_gestiona_cajeros_de_su_restaurante(): void
    {
        $otro = $this->crearRestaurante('Otro');
        $cajeroAjeno = $this->crearUsuario($otro, 'cajero');

        $this->actuarComo($this->dueno);

        // Solo ve a su dueño y a su cajero.
        $this->getJson('/api/usuarios')->assertOk()->assertJsonCount(2);

        $this->patchJson("/api/usuarios/{$cajeroAjeno->id}", ['activo' => false])->assertNotFound();
        // Solo se editan cajeros: tampoco puede desactivar a un dueño.
        $this->patchJson("/api/usuarios/{$this->dueno->id}", ['activo' => false])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $cajeroAjeno->id, 'activo' => true]);
        $this->assertDatabaseHas('users', ['id' => $this->dueno->id, 'activo' => true]);
    }

    public function test_el_dueno_no_puede_escalar_privilegios_al_crear_cajeros(): void
    {
        $otro = $this->crearRestaurante('Otro');

        $this->actuarComo($this->dueno);

        $this->postJson('/api/usuarios', [
            'name'           => 'Nuevo',
            'email'          => 'nuevo@prueba.com',
            'password'       => 'clave12345',
            'rol'            => 'superadmin',
            'restaurante_id' => $otro->id,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email'          => 'nuevo@prueba.com',
            'rol'            => 'cajero',
            'restaurante_id' => $this->restaurante->id,
        ]);
    }

    public function test_un_cajero_desactivado_pierde_el_acceso(): void
    {
        $this->actuarComo($this->dueno);

        $this->patchJson("/api/usuarios/{$this->cajero->id}", ['activo' => false])->assertOk();

        $this->postJson('/api/auth/login', [
            'email'    => $this->cajero->email,
            'password' => 'clave12345',
        ])->assertForbidden();

        $this->actuarComo($this->cajero->fresh());

        $this->getJson('/api/productos')->assertForbidden();
    }

    public function test_el_dueno_ve_costos_al_cambiar_el_estado(): void
    {
        $this->actuarComo($this->dueno);

        $this->patchJson("/api/pedidos/{$this->pedidoId}/estado", ['estado' => 'confirmado'])
            ->assertOk()
            ->assertJsonPath('detalles.0.costo_unitario', '9.20');
    }

    public function test_un_restaurante_desactivado_bloquea_tokens_emitidos_previamente(): void
    {
        $tokens = [];

        foreach ([$this->dueno, $this->cajero] as $usuario) {
            $tokens[] = $this->postJson('/api/auth/login', [
                'email' => $usuario->email,
                'password' => 'clave12345',
            ])->assertOk()->json('token');
        }

        $this->restaurante->update(['activo' => false]);

        foreach ($tokens as $token) {
            // Cada peticion debe autenticar nuevamente el token y cargar el restaurante.
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/productos')->assertForbidden();

            $this->app['auth']->forgetGuards();
            $this->withToken($token)
                ->patchJson("/api/pedidos/{$this->pedidoId}/estado", ['estado' => 'confirmado'])
                ->assertForbidden();

            $this->app['auth']->forgetGuards();
            $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        }

        $this->assertDatabaseHas('pedidos', ['id' => $this->pedidoId, 'estado' => 'pendiente']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
