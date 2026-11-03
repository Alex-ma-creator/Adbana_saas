<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rechaza_origen_no_autorizado_con_403(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:8080']]);

        $response = $this->withHeaders([
            'Origin' => 'https://sitio-malicioso.evil.com',
        ])->getJson('/api/health');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Origen no autorizado por política CORS.',
                'origin' => 'https://sitio-malicioso.evil.com',
            ]);
    }

    public function test_permite_origen_autorizado_y_devuelve_cabecera_cors(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:8080']]);

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:8080',
        ])->getJson('/api/health');

        $response->assertStatus(200)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8080');
    }

    public function test_bloquea_preflight_options_de_origen_no_autorizado(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:8080']]);

        $response = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://atacante.org',
        ]);

        $response->assertStatus(403);
    }
}
