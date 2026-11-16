<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_health_responde_200_con_metricas_de_servicios(): void
    {
        $res = $this->getJson('/api/health');

        $res->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.app.status', 'ok')
            ->assertJsonPath('services.database.status', 'ok')
            ->assertJsonPath('services.cache.status', 'ok');
    }

    public function test_comando_preflight_verifica_infraestructura_exitosamente(): void
    {
        $codigo = Artisan::call('adbana:preflight');
        $this->assertSame(0, $codigo);
    }

    public function test_vistas_de_error_personalizadas_existen_y_renderizan(): void
    {
        foreach (['403', '404', '419', '429', '500', '503'] as $codigo) {
            $html = view("errors.{$codigo}")->render();
            $this->assertStringContainsString($codigo, $html);
            $this->assertStringContainsString('ADBANA', $html);
        }
    }
}
