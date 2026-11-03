<?php

namespace Tests\Feature;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenExpiracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_asigna_fecha_de_expiracion_al_token(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Seguro',
            'slug' => 'restaurante-seguro',
        ]);

        User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueño Seguro',
            'email' => 'seguro@adbana.test',
            'password' => 'secreto123',
            'rol' => 'dueno',
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email' => 'seguro@adbana.test',
            'password' => 'secreto123',
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure(['token', 'expires_at', 'user']);

        $this->assertNotNull($res->json('expires_at'));
    }

    public function test_token_expirado_es_rechazado_con_401(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Expirado',
            'slug' => 'restaurante-expirado',
        ]);

        $user = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueño Expirado',
            'email' => 'expirado@adbana.test',
            'password' => 'secreto123',
            'rol' => 'dueno',
        ]);

        $nuevoToken = $user->createToken('api', ['*'], now()->subMinutes(5));
        $plainText = $nuevoToken->plainTextToken;

        $this->assertInstanceOf(PersonalAccessToken::class, $nuevoToken->accessToken);

        $res = $this->withHeader('Authorization', 'Bearer ' . $plainText)
            ->getJson('/api/auth/me');

        $res->assertStatus(401);
    }
}
