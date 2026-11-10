<?php

namespace Tests\Feature;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function mockGoogleUser(string $id, string $email, string $name = 'Usuario Test', ?string $avatar = null): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn($avatar);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redireccion_a_google_devuelve_redireccion_valida(): void
    {
        $respuesta = $this->get('/auth/google/redirect');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $respuesta->headers->get('Location'));
    }

    public function test_callback_crea_nuevo_restaurante_y_dueno_si_no_existe(): void
    {
        $this->mockGoogleUser('google-sub-1001', 'nuevo.chef@gmail.com', 'Chef Mario');

        $respuesta = $this->get('/api/auth/google/callback');

        $respuesta->assertRedirect();
        $location = $respuesta->headers->get('Location');
        $this->assertStringContainsString('/panel?token=', $location);

        $this->assertDatabaseHas('users', [
            'email' => 'nuevo.chef@gmail.com',
            'google_id' => 'google-sub-1001',
            'rol' => 'dueno',
            'activo' => true,
        ]);

        $usuario = User::where('email', 'nuevo.chef@gmail.com')->first();
        $this->assertNotNull($usuario);
        $this->assertNotNull($usuario->restaurante);
        $this->assertEquals('Restaurante Chef Mario', $usuario->restaurante->nombre);
        $this->assertEquals('restaurante-chef-mario', $usuario->restaurante->slug);
    }

    public function test_callback_asocia_google_id_a_usuario_existente_por_email(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'La Parrilla Central',
            'slug' => 'la-parrilla-central',
        ]);

        $usuario = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Carlos Parrillero',
            'email' => 'carlos@parrilla.com',
            'password' => 'secret12345',
            'rol' => 'dueno',
            'activo' => true,
        ]);

        $this->assertNull($usuario->google_id);

        $this->mockGoogleUser('google-parrilla-888', 'carlos@parrilla.com', 'Carlos Parrillero');

        $respuesta = $this->get('/api/auth/google/callback');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('/panel?token=', $respuesta->headers->get('Location'));

        $usuario->refresh();
        $this->assertSame('google-parrilla-888', $usuario->google_id);
    }

    public function test_callback_inicia_sesion_usuario_existente_con_google_id(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Sushi Bar',
            'slug' => 'sushi-bar',
        ]);

        User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Kenji Sato',
            'email' => 'kenji@sushibar.com',
            'google_id' => 'google-sushi-999',
            'password' => 'secret12345',
            'rol' => 'dueno',
            'activo' => true,
        ]);

        $this->mockGoogleUser('google-sushi-999', 'kenji@sushibar.com', 'Kenji Sato');

        $respuesta = $this->get('/api/auth/google/callback');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('/panel?token=', $respuesta->headers->get('Location'));
    }

    public function test_callback_rechaza_si_usuario_esta_desactivado(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Local Bloqueado',
            'slug' => 'local-bloqueado',
        ]);

        User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Usuario Desactivado',
            'email' => 'desactivado@prueba.com',
            'google_id' => 'google-bloqueado-1',
            'password' => 'secret12345',
            'rol' => 'dueno',
            'activo' => false,
        ]);

        $this->mockGoogleUser('google-bloqueado-1', 'desactivado@prueba.com');

        $respuesta = $this->get('/api/auth/google/callback');

        $respuesta->assertRedirect();
        $location = $respuesta->headers->get('Location');
        $this->assertStringContainsString('/panel?error=', $location);
        $this->assertStringContainsString('desactivada', urldecode($location));
    }

    public function test_callback_rechaza_si_restaurante_esta_desactivado(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Local Pausado',
            'slug' => 'local-pausado',
            'activo' => false,
        ]);

        User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueno Local Pausado',
            'email' => 'pausado@prueba.com',
            'google_id' => 'google-pausado-1',
            'password' => 'secret12345',
            'rol' => 'dueno',
            'activo' => true,
        ]);

        $this->mockGoogleUser('google-pausado-1', 'pausado@prueba.com');

        $respuesta = $this->get('/api/auth/google/callback');

        $respuesta->assertRedirect();
        $location = $respuesta->headers->get('Location');
        $this->assertStringContainsString('/panel?error=', $location);
        $this->assertStringContainsString('desactivado', urldecode($location));
    }

    public function test_callback_gestiona_cancelacion_de_google(): void
    {
        $respuesta = $this->get('/api/auth/google/callback?error=access_denied&error_description=The+user+denied+the+request');

        $respuesta->assertRedirect();
        $location = $respuesta->headers->get('Location');
        $this->assertStringContainsString('/panel?error=', $location);
    }
}
