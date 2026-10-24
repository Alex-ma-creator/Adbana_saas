<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErroresTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_token_responde_401_en_json_uniforme(): void
    {
        $this->getJson('/api/productos')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'No autenticado. Inicia sesión para continuar.']);

        // Sin la cabecera Accept (como desde un navegador) también responde JSON.
        $this->get('/api/productos')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No autenticado. Inicia sesión para continuar.');
    }

    public function test_una_ruta_inexistente_de_la_api_responde_404_en_json(): void
    {
        $this->getJson('/api/no-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Recurso no encontrado.']);

        $this->get('/api')->assertNotFound()->assertExactJson(['message' => 'Recurso no encontrado.']);

        $this->get('/api/no-existe')
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_un_metodo_no_permitido_responde_405(): void
    {
        $this->deleteJson('/api/carta/algo')
            ->assertStatus(405)
            ->assertHeader('Allow')
            ->assertJsonPath('message', 'Método no permitido para esta ruta.');
    }

    public function test_el_limite_de_peticiones_responde_429_con_mensaje_claro(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'a@prueba.com', 'password' => 'x'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'a@prueba.com', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiadas peticiones. Espera un momento e intenta de nuevo.')
            ->assertHeader('Retry-After');
    }

    public function test_los_errores_de_validacion_traen_message_y_errors(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }

    public function test_un_error_interno_no_filtra_detalles(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/_falla', fn () => throw new \RuntimeException('secreto interno'));

        $this->get('/api/_falla')->assertStatus(500)
            ->assertExactJson(['message' => 'Error interno del servidor.']);

        $this->getJson('/api/_falla')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Error interno del servidor.']);
    }

    public function test_el_inicio_muestra_en_construccion_y_el_panel_muestra_acceso(): void
    {
        $this->get('/')->assertOk()->assertSee('Estamos construyendo');
        $this->get('/panel')->assertOk()->assertSee('Ingresar');
    }

    public function test_una_pagina_web_inexistente_muestra_la_vista_404(): void
    {
        $this->get('/esto-no-existe')
            ->assertNotFound()
            ->assertSee('No encontramos esta página');
    }
}
