<?php

namespace Tests\Feature;

use App\Models\AdministradorPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class ConfirmacionPrivadaTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function administrador(string $email, ?string $pin = null): AdministradorPlataforma
    {
        $admin = AdministradorPlataforma::create(['name' => 'Administración de prueba', 'email' => $email, 'password' => 'ClavePruebaSegura123', 'rol' => 'superadmin', 'activo' => true]);
        if ($pin !== null) {
            $admin->forceFill(['pin_confirmacion' => $pin])->save();
        }

        return $admin->fresh();
    }

    private function entrar(AdministradorPlataforma $admin): void
    {
        $this->actingAs($admin, 'plataforma')->withSession(['plataforma_version' => $admin->sesion_version]);
    }

    public function test_pin_exclusivo_no_reemplaza_clave_de_login_ni_se_expone(): void
    {
        $admin = $this->administrador('privado@example.test', '135790246');
        $this->assertTrue(Hash::check('135790246', $admin->pin_confirmacion));
        $this->assertNotSame('135790246', $admin->getRawOriginal('pin_confirmacion'));
        $this->assertArrayNotHasKey('pin_confirmacion', $admin->toArray());
        $this->postJson('/acceso', ['email' => $admin->email, 'password' => '135790246'])->assertUnauthorized();
        $this->postJson('/acceso', ['email' => $admin->email, 'password' => 'ClavePruebaSegura123'])->assertOk()->assertJsonPath('administrador.confirmacion_con_pin', true)->assertJsonMissingPath('administrador.pin_confirmacion');
        $this->getJson('/administracion/api/me')->assertOk()->assertJsonMissingPath('pin_confirmacion');
    }

    public function test_solo_cuenta_configurada_confirma_con_pin_las_demas_con_clave(): void
    {
        $restaurante = $this->crearRestaurante();
        $privado = $this->administrador('privado@example.test', '135790246');
        $otro = $this->administrador('otro@example.test');
        $datos = ['activo' => false, 'motivo' => 'Suspensión de prueba'];
        $this->entrar($privado);
        $ruta = '/administracion/api/restaurantes/'.$restaurante->id;
        $this->patchJson($ruta, $datos + ['password_actual' => 'ClavePruebaSegura123'])->assertUnprocessable();
        $this->patchJson($ruta, $datos + ['password_actual' => '135790246'])->assertOk();
        $this->entrar($otro);
        $this->assertFalse($otro->confirmacion_con_pin);
        $this->patchJson($ruta, $datos + ['password_actual' => '135790246'])->assertUnprocessable();
        $this->patchJson($ruta, $datos + ['password_actual' => 'ClavePruebaSegura123'])->assertOk();
        $this->assertStringNotContainsString('135790246', DB::table('eventos_plataforma')->get()->toJson());
    }

    public function test_pin_tiene_limite_de_intentos_por_cuenta_y_se_recupera(): void
    {
        $restaurante = $this->crearRestaurante();
        $this->entrar($this->administrador('privado@example.test', '135790246'));
        $ruta = '/administracion/api/restaurantes/'.$restaurante->id;
        $datos = ['activo' => false, 'motivo' => 'Prueba de seguridad'];
        for ($i = 0; $i < 5; $i++) {
            $this->patchJson($ruta, $datos + ['password_actual' => '000000000'])->assertUnprocessable();
        }
        $this->patchJson($ruta, $datos + ['password_actual' => '135790246'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertTrue($restaurante->fresh()->activo);
        $this->travel(61)->seconds();
        $this->patchJson($ruta, $datos + ['password_actual' => '135790246'])->assertOk();
    }

    public function test_no_se_puede_delegar_el_pin_privado_al_crear_administradores(): void
    {
        $this->entrar($this->administrador('privado@example.test', '135790246'));
        $respuesta = $this->postJson('/administracion/api/administradores', [
            'name' => 'Nuevo administrador', 'email' => 'nuevo@example.test', 'rol' => 'administrador', 'permisos' => ['resumen' => true],
            'generar_password' => true, 'password_actual' => '135790246', 'motivo' => 'Alta de prueba',
            'pin_confirmacion' => '135790246', 'confirmacion_con_pin' => true,
        ])->assertCreated()->assertJsonPath('confirmacion_con_pin', false)->assertJsonMissingPath('pin_confirmacion');
        $this->assertNull(AdministradorPlataforma::findOrFail($respuesta->json('id'))->pin_confirmacion);
    }
}
