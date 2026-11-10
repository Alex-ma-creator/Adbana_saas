<?php

namespace Tests\Feature;

use App\Models\AdministradorPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class AdministracionPlataformaTest extends TestCase
{
    use PreparaDatos,RefreshDatabase;

    private function admin(array $extra = []): AdministradorPlataforma
    {
        return AdministradorPlataforma::create(array_replace(['name' => 'Alex', 'email' => uniqid().'@example.test', 'password' => 'ClaveAdministracion123', 'rol' => 'superadmin', 'activo' => true], $extra));
    }

    private function entrar(AdministradorPlataforma $admin): void
    {
        $admin->refresh();
        $this->actingAs($admin, 'plataforma')->withSession(['plataforma_version' => $admin->sesion_version]);
    }

    private function datos(array $extra = []): array
    {
        return array_replace(['name' => 'Soporte', 'email' => 'soporte@example.test', 'rol' => 'administrador', 'activo' => true, 'permisos' => ['resumen' => true, 'restaurantes' => true, 'gestionar_restaurantes' => false, 'auditoria' => false], 'password' => 'SoporteClave12345', 'password_confirmation' => 'SoporteClave12345', 'password_actual' => 'ClaveAdministracion123', 'motivo' => 'Incorporación al equipo'], $extra);
    }

    public function test_no_hay_acceso_con_cuentas_de_restaurante_ni_tokens(): void
    {
        $this->getJson('/administracion/api/resumen')->assertUnauthorized();
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $token = $u->createToken('restaurante')->plainTextToken;
        $this->withToken($token)->getJson('/administracion/api/resumen')->assertUnauthorized();
        $this->actingAs($u, 'web')->getJson('/administracion/api/administradores')->assertUnauthorized();
        $this->postJson('/acceso', ['espacio' => 'plataforma', 'email' => $u->email, 'password' => 'clave12345'])->assertUnauthorized();
    }

    public function test_superadmin_crea_consulta_edita_y_retira_accesos(): void
    {
        $root = $this->admin();
        $this->entrar($root);
        $id = $this->postJson('/administracion/api/administradores', $this->datos())->assertCreated()->assertJsonMissingPath('password')->json('id');
        $this->assertTrue(Hash::check('SoporteClave12345', AdministradorPlataforma::find($id)->password));
        $this->getJson('/administracion/api/administradores')->assertOk()->assertJsonPath('administradores.total', 2);
        $this->patchJson('/administracion/api/administradores/'.$id, $this->datos(['activo' => false, 'password' => null, 'password_confirmation' => null]))->assertOk()->assertJsonPath('activo', false);
        $this->deleteJson('/administracion/api/administradores/'.$id, ['password_actual' => 'ClaveAdministracion123', 'motivo' => 'Fin del acceso autorizado'])->assertOk();
        $this->assertSoftDeleted('administradores_plataforma', ['id' => $id]);
        $this->assertDatabaseHas('eventos_plataforma', ['accion' => 'eliminar_administrador', 'recurso_id' => $id]);
        $eventos = DB::table('eventos_plataforma')->get()->toJson();
        $this->assertStringNotContainsString('SoporteClave12345', $eventos);
        $this->assertStringNotContainsString('password', $eventos);
    }

    public function test_rol_limitado_no_puede_elevarse_ni_administrar_cuentas(): void
    {
        $admin = $this->admin(['rol' => 'administrador', 'permisos' => ['resumen' => true]]);
        $this->entrar($admin);
        $this->getJson('/administracion/api/resumen')->assertOk();
        $this->getJson('/administracion/api/restaurantes')->assertForbidden();
        $this->getJson('/administracion/api/administradores')->assertForbidden();
        $this->postJson('/administracion/api/administradores', $this->datos(['rol' => 'superadmin']))->assertForbidden();
        $this->deleteJson('/administracion/api/administradores/'.$admin->id, ['password_actual' => 'ClaveAdministracion123', 'motivo' => 'Intento no autorizado'])->assertForbidden();
    }

    public function test_protege_propia_cuenta_y_ultimo_superadmin(): void
    {
        $root = $this->admin();
        $this->entrar($root);
        $this->deleteJson('/administracion/api/administradores/'.$root->id, ['password_actual' => 'ClaveAdministracion123', 'motivo' => 'Borrado accidental'])->assertStatus(409);
        $this->patchJson('/administracion/api/administradores/'.$root->id, $this->datos(['email' => $root->email, 'rol' => 'administrador']))->assertStatus(409);
        $this->assertSame(1, AdministradorPlataforma::where('rol', 'superadmin')->where('activo', true)->count());
    }

    public function test_cambios_criticos_exigen_confirmacion_y_motivo(): void
    {
        $this->entrar($this->admin());
        $this->postJson('/administracion/api/administradores', $this->datos(['password_actual' => 'incorrecta']))->assertUnprocessable();
        $this->postJson('/administracion/api/administradores', $this->datos(['motivo' => '']))->assertUnprocessable();
        $this->postJson('/administracion/api/administradores', $this->datos(['permisos' => ['administradores' => true]]))->assertUnprocessable();
        $this->assertDatabaseCount('administradores_plataforma', 1);
    }

    public function test_dashboard_usa_datos_reales_y_no_inventa_suscripciones(): void
    {
        $r = $this->crearRestaurante();
        $this->crearRestaurante('Sin pedidos');
        DB::table('pedidos')->insert(['restaurante_id' => $r->id, 'cliente_nombre' => 'Prueba', 'tipo' => 'recojo', 'estado' => 'pendiente', 'total' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $this->entrar($this->admin());
        $this->getJson('/administracion/api/resumen?dias=7')->assertOk()->assertJsonPath('restaurantes_total', 2)->assertJsonPath('restaurantes_activos', 2)->assertJsonPath('restaurantes_con_pedidos_7dias', 1)->assertJsonPath('sin_pedidos_7dias', 1)->assertJsonPath('pedidos_periodo', 1)->assertJsonCount(7, 'serie')->assertJsonPath('suscripciones.estado', 'sin_configurar');
        $this->getJson('/administracion/api/resumen?dias=99999')->assertUnprocessable();
    }

    public function test_restaurantes_paginados_y_suspension_auditada(): void
    {
        $r = $this->crearRestaurante();
        $root = $this->admin();
        $this->entrar($root);
        $this->getJson('/administracion/api/restaurantes')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.password');
        $this->patchJson('/administracion/api/restaurantes/'.$r->id, ['activo' => false, 'password_actual' => 'ClaveAdministracion123', 'motivo' => 'Suspensión de prueba'])->assertOk()->assertJsonPath('activo', false);
        $this->assertDatabaseHas('eventos_plataforma', ['accion' => 'cambiar_estado_restaurante', 'recurso_id' => $r->id]);
    }

    public function test_acceso_retirado_y_version_de_sesion_se_validan_en_servidor(): void
    {
        $admin = $this->admin(['rol' => 'administrador', 'permisos' => ['resumen' => true]]);
        $this->entrar($admin);
        $this->getJson('/administracion/api/resumen')->assertOk();
        DB::table('administradores_plataforma')->where('id', $admin->id)->increment('sesion_version');
        $this->getJson('/administracion/api/resumen')->assertUnauthorized();
    }

    public function test_login_y_logout_usan_guard_separado(): void
    {
        $admin = $this->admin();
        $this->postJson('/acceso', ['espacio' => 'plataforma', 'email' => $admin->email, 'password' => 'incorrecta'])->assertUnauthorized();
        $this->postJson('/acceso', ['espacio' => 'plataforma', 'email' => $admin->email, 'password' => 'ClaveAdministracion123'])->assertOk()->assertJsonPath('administrador.rol', 'superadmin')->assertJsonStructure(['csrf']);
        $this->getJson('/administracion/api/me')->assertOk();
        $this->postJson('/administracion/api/logout')->assertOk();
        $this->getJson('/administracion/api/me')->assertUnauthorized();
    }

    public function test_primer_superadmin_se_crea_por_consola_una_sola_vez(): void
    {
        $this->artisan('adbana:crear-superadmin')->expectsQuestion('Tu nombre', 'Alex')->expectsQuestion('Correo del superadministrador', 'alex@example.test')->expectsQuestion('Contraseña nueva (mínimo 12 caracteres)', 'ClaveAdministracion123')->expectsQuestion('Repite la contraseña', 'ClaveAdministracion123')->assertSuccessful();
        $this->assertDatabaseHas('administradores_plataforma', ['email' => 'alex@example.test', 'rol' => 'superadmin']);
        $this->artisan('adbana:crear-superadmin')->expectsQuestion('Tu nombre', 'Otro')->expectsQuestion('Correo del superadministrador', 'otro@example.test')->expectsQuestion('Contraseña nueva (mínimo 12 caracteres)', 'ClaveAdministracion123')->expectsQuestion('Repite la contraseña', 'ClaveAdministracion123')->assertFailed();
        $this->assertDatabaseCount('administradores_plataforma', 1);
    }

    public function test_shell_y_autenticacion_no_tienen_registro_publico_de_administradores(): void
    {
        $this->get('/administracion')->assertRedirect('/login?administracion=1');
        $this->postJson('/administracion/api/login', ['email' => 'nadie@example.test', 'password' => 'incorrecta'])->assertNotFound();
        $admin = $this->admin();
        $this->entrar($admin);
        $this->get('/administracion')->assertOk()->assertSee('csrf-token')->assertSee('administracion.js')->assertDontSee('platform-auth')->assertDontSee('Tu ecosistema.');
        $admin->update(['activo' => false]);
        $this->get('/administracion')->assertRedirect('/login?administracion=1');
        $this->postJson('/administracion/api/registro', $this->datos())->assertNotFound();
    }
}
