<?php

namespace Tests\Feature;

use App\Models\AdministradorPlataforma;
use App\Models\Restaurante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class AccesoAdministrativoTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function administrador(array $extra = []): AdministradorPlataforma
    {
        return AdministradorPlataforma::create(array_replace(['name' => 'Administración', 'email' => 'admin@example.test', 'password' => 'ClaveAdministracion123', 'rol' => 'superadmin', 'activo' => true], $extra));
    }

    private function entrar(AdministradorPlataforma $admin): void
    {
        $this->actingAs($admin, 'plataforma')->withSession(['plataforma_version' => $admin->fresh()->sesion_version]);
    }

    public function test_entrada_comun_no_confunde_cuentas_con_el_mismo_correo(): void
    {
        $restaurante = $this->crearRestaurante();
        $usuario = $this->crearUsuario($restaurante, 'cocina');
        $admin = $this->administrador(['email' => $usuario->email, 'password' => 'clave12345']);
        $datos = ['email' => $usuario->email, 'password' => 'clave12345'];
        $this->postJson('/acceso', $datos)->assertOk()->assertJsonCount(2, 'espacios')->assertJsonMissingPath('token');
        $this->getJson('/administracion/api/me')->assertUnauthorized();
        $this->postJson('/acceso', $datos + ['espacio' => 'plataforma'])->assertOk()->assertJsonPath('destino', '/administracion')->assertJsonPath('administrador.id', $admin->id)->assertJsonMissingPath('token');
        $this->postJson('/acceso', $datos + ['espacio' => 'restaurante'])->assertOk()->assertJsonPath('user.rol', 'cocina')->assertJsonStructure(['token']);
    }

    public function test_correo_coincidente_no_compartira_contrasenas_ni_permisos(): void
    {
        $usuario = $this->crearUsuario($this->crearRestaurante(), 'cocina');
        $this->administrador(['email' => $usuario->email]);
        $this->postJson('/acceso', ['email' => $usuario->email, 'password' => 'clave12345', 'espacio' => 'plataforma'])->assertUnauthorized();
        $this->postJson('/acceso', ['email' => $usuario->email, 'password' => 'clave12345'])->assertOk()->assertJsonPath('user.id', $usuario->id)->assertJsonMissingPath('administrador');
        $this->postJson('/acceso', ['email' => $usuario->email, 'password' => 'ClaveAdministracion123'])->assertOk()->assertJsonPath('destino', '/administracion');
    }

    public function test_entrada_comun_rechaza_credenciales_invalidas_y_limita_reintentos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/acceso', ['email' => 'nadie@example.test', 'password' => 'incorrecta'])->assertUnauthorized();
        }
        $this->postJson('/acceso', ['email' => 'nadie@example.test', 'password' => 'incorrecta'])->assertStatus(429);
    }

    public function test_superadmin_genera_credencial_una_sola_vez_sin_guardarla_en_auditoria(): void
    {
        $this->entrar($this->administrador());
        $respuesta = $this->postJson('/administracion/api/administradores', [
            'name' => 'Soporte', 'email' => 'soporte@example.test', 'rol' => 'administrador', 'activo' => true,
            'permisos' => ['restaurantes' => true], 'generar_password' => true,
            'password_actual' => 'ClaveAdministracion123', 'motivo' => 'Alta de soporte',
        ])->assertCreated()->assertJsonMissingPath('password');
        $clave = $respuesta->json('password_temporal');
        $this->assertSame(20, strlen($clave));
        $this->assertTrue(Hash::check($clave, AdministradorPlataforma::findOrFail($respuesta->json('id'))->password));
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $this->getJson('/administracion/api/administradores')->assertOk()->assertJsonMissingPath('administradores.data.1.password_temporal');
        $this->assertStringNotContainsString($clave, DB::table('eventos_plataforma')->get()->toJson());
    }

    public function test_perfil_propio_cambia_clave_revoca_otras_sesiones_y_no_eleva_permisos(): void
    {
        $admin = $this->administrador(['rol' => 'administrador', 'permisos' => []]);
        $version = $admin->fresh()->sesion_version;
        $this->entrar($admin);
        $this->patchJson('/administracion/api/perfil', ['name' => 'Nuevo nombre', 'password_actual' => 'incorrecta'])->assertUnprocessable();
        $this->patchJson('/administracion/api/perfil', ['name' => 'Nuevo nombre', 'password_actual' => 'ClaveAdministracion123', 'password' => 'OtraClaveSegura123', 'password_confirmation' => 'OtraClaveSegura123', 'rol' => 'superadmin', 'permisos' => ['administradores' => true]])
            ->assertOk()->assertJsonPath('administrador.name', 'Nuevo nombre')->assertJsonPath('administrador.rol', 'administrador');
        $this->assertTrue(Hash::check('OtraClaveSegura123', $admin->fresh()->password));
        $this->getJson('/administracion/api/me')->assertOk();
        $this->withSession(['plataforma_version' => $version])->getJson('/administracion/api/me')->assertUnauthorized();
        $this->assertStringNotContainsString('OtraClaveSegura123', DB::table('eventos_plataforma')->get()->toJson());
    }

    public function test_eliminar_restaurante_retira_accesos_y_carta_conservando_historial(): void
    {
        $restaurante = $this->crearRestaurante();
        $usuario = $this->crearUsuario($restaurante);
        $token = $usuario->createToken('panel')->plainTextToken;
        $pedido = DB::table('pedidos')->insertGetId(['restaurante_id' => $restaurante->id, 'cliente_nombre' => 'Historial de prueba', 'tipo' => 'recojo', 'estado' => 'entregado', 'total' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/carta/'.$restaurante->slug)->assertOk();
        $this->entrar($this->administrador());
        $datos = ['password_actual' => 'ClaveAdministracion123', 'motivo' => 'Cierre del negocio', 'nombre_confirmacion' => $restaurante->nombre];
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, array_replace($datos, ['nombre_confirmacion' => 'Otro']))->assertUnprocessable();
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, $datos)->assertOk();
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, $datos)->assertOk();
        $this->assertSoftDeleted('restaurantes', ['id' => $restaurante->id]);
        $this->assertDatabaseHas('users', ['id' => $usuario->id]);
        $this->assertDatabaseHas('pedidos', ['id' => $pedido, 'restaurante_id' => $restaurante->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(1, DB::table('eventos_plataforma')->where('accion', 'eliminar_restaurante')->count());
        $this->getJson('/api/carta/'.$restaurante->slug)->assertNotFound();
        $this->postJson('/acceso', ['email' => $usuario->email, 'password' => 'clave12345'])->assertUnauthorized();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/administracion/api/restaurantes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/administracion/api/resumen')->assertOk()->assertJsonPath('restaurantes_total', 0);
        $this->assertNull(Restaurante::find($restaurante->id));
    }

    public function test_eliminacion_requiere_permiso_especifico_y_no_se_puede_reactivar(): void
    {
        $restaurante = $this->crearRestaurante();
        $limitado = $this->administrador(['rol' => 'administrador', 'permisos' => ['gestionar_restaurantes' => true]]);
        $this->entrar($limitado);
        $datos = ['password_actual' => 'ClaveAdministracion123', 'motivo' => 'Solicitud del negocio', 'nombre_confirmacion' => $restaurante->nombre];
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, $datos)->assertForbidden();
        $this->assertNotSoftDeleted('restaurantes', ['id' => $restaurante->id]);
        $limitado->update(['permisos' => ['eliminar_restaurantes' => true, 'gestionar_restaurantes' => true]]);
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, array_replace($datos, ['password_actual' => 'incorrecta']))->assertUnprocessable();
        $this->deleteJson('/administracion/api/restaurantes/'.$restaurante->id, $datos)->assertOk();
        $this->patchJson('/administracion/api/restaurantes/'.$restaurante->id, $datos + ['activo' => true])->assertNotFound();
    }

    public function test_el_nombre_de_un_restaurante_retirado_no_rompe_nuevos_registros(): void
    {
        $anterior = Restaurante::create(['nombre' => 'Local', 'slug' => 'local']);
        $anterior->delete();
        $this->postJson('/api/auth/registro-dueno', ['restaurante' => 'Local', 'name' => 'Nuevo dueño', 'email' => 'nuevo@example.test', 'password' => 'ClaveNueva123'])
            ->assertCreated()->assertJsonPath('restaurante.slug', 'local-2');
    }
}
