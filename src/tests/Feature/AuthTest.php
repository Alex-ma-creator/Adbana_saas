<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private function datosRegistro(array $extra = []): array
    {
        return array_merge([
            'restaurante' => 'Picanteria Prueba',
            'name'        => 'Dueno Prueba',
            'email'       => 'dueno@prueba.com',
            'password'    => 'clave12345',
        ], $extra);
    }

    public function test_el_registro_crea_restaurante_dueno_y_token(): void
    {
        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro())
            ->assertCreated()
            ->assertJsonStructure([
                'token',
                'user'        => ['id', 'email', 'rol', 'restaurante_id'],
                'restaurante' => ['id', 'nombre', 'slug'],
            ])
            ->assertJsonPath('user.rol', 'dueno')
            ->assertJsonPath('restaurante.slug', 'picanteria-prueba');

        $this->assertDatabaseHas('users', ['email' => 'dueno@prueba.com', 'rol' => 'dueno']);
        $this->assertDatabaseCount('restaurantes', 1);
    }

    public function test_la_contrasena_se_guarda_cifrada(): void
    {
        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro())->assertCreated();

        $hash = User::where('email', 'dueno@prueba.com')->value('password');

        $this->assertNotSame('clave12345', $hash);
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(Hash::check('clave12345', $hash));
    }

    public function test_dos_restaurantes_con_el_mismo_nombre_obtienen_slugs_distintos(): void
    {
        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro())->assertCreated();

        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro(['email' => 'otro@prueba.com']))
            ->assertCreated()
            ->assertJsonPath('restaurante.slug', 'picanteria-prueba-2');
    }

    public function test_el_registro_rechaza_datos_invalidos(): void
    {
        $this->postJson('/api/auth/registro-dueno', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['restaurante', 'name', 'email', 'password']);

        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro(['password' => 'corta']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro())->assertCreated();

        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro(['restaurante' => 'Otro']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_no_se_puede_registrar_un_rol_privilegiado_desde_afuera(): void
    {
        $this->postJson('/api/auth/registro-dueno', $this->datosRegistro([
            'rol'            => 'superadmin',
            'restaurante_id' => 999,
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'dueno@prueba.com', 'rol' => 'dueno']);
        $this->assertDatabaseMissing('users', ['rol' => 'superadmin']);
    }

    public function test_login_correcto_devuelve_token_y_usuario(): void
    {
        $restaurante = $this->crearRestaurante();
        $cajero = $this->crearUsuario($restaurante, 'cajero');

        $this->postJson('/api/auth/login', ['email' => $cajero->email, 'password' => 'clave12345'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'rol']])
            ->assertJsonPath('user.rol', 'cajero');
    }

    public function test_login_incorrecto_da_401(): void
    {
        $restaurante = $this->crearRestaurante();
        $dueno = $this->crearUsuario($restaurante);

        $this->postJson('/api/auth/login', ['email' => $dueno->email, 'password' => 'incorrecta'])
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', ['email' => 'nadie@prueba.com', 'password' => 'clave12345'])
            ->assertUnauthorized();
    }

    public function test_no_inicia_sesion_un_usuario_ni_un_restaurante_desactivado(): void
    {
        $restaurante = $this->crearRestaurante();
        $cajero = $this->crearUsuario($restaurante, 'cajero');

        $cajero->update(['activo' => false]);
        $this->postJson('/api/auth/login', ['email' => $cajero->email, 'password' => 'clave12345'])
            ->assertForbidden();

        $cajero->update(['activo' => true]);
        $restaurante->update(['activo' => false]);
        $this->postJson('/api/auth/login', ['email' => $cajero->email, 'password' => 'clave12345'])
            ->assertForbidden();
    }

    public function test_me_y_logout_con_un_token_real(): void
    {
        $restaurante = $this->crearRestaurante();
        $dueno = $this->crearUsuario($restaurante);

        $token = $this->postJson('/api/auth/login', ['email' => $dueno->email, 'password' => 'clave12345'])
            ->assertOk()
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $dueno->email)
            ->assertJsonPath('restaurante.id', $restaurante->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_el_login_se_bloquea_tras_5_intentos_fallidos(): void
    {
        $restaurante = $this->crearRestaurante();
        $dueno = $this->crearUsuario($restaurante);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $dueno->email, 'password' => 'incorrecta'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => $dueno->email, 'password' => 'incorrecta'])
            ->assertStatus(429);

        // Mientras dure el bloqueo, ni la clave correcta pasa.
        $this->postJson('/api/auth/login', ['email' => $dueno->email, 'password' => 'clave12345'])
            ->assertStatus(429);
    }
}