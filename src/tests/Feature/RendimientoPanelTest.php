<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class RendimientoPanelTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    public function test_tiempos_de_diagnostico_solo_se_publican_en_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/up')->assertOk()->assertHeaderMissing('Server-Timing');
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('/up')->assertOk()->assertHeader('Server-Timing');
    }

    public function test_resumen_de_caja_agrega_entradas_y_salidas_sin_mezclarlas(): void
    {
        $u = $this->crearUsuario($this->crearRestaurante());
        $this->actuarComo($u);
        $this->postJson('/api/caja/abrir', ['saldo_inicial' => '100.00'])->assertCreated();
        foreach ([['entrada', '10.50'], ['salida', '4.20'], ['entrada', '2.30']] as [$tipo, $monto]) {
            $this->postJson('/api/caja/movimientos', ['clave_operacion' => (string) Str::uuid(), 'tipo' => $tipo, 'monto' => $monto, 'motivo' => 'Prueba de resumen'])->assertCreated();
        }
        $this->getJson('/api/caja/actual')->assertOk()->assertJsonPath('resumen.entradas', '12.80')->assertJsonPath('resumen.salidas', '4.20')->assertJsonPath('resumen.efectivo_esperado', '108.60');
    }
}
