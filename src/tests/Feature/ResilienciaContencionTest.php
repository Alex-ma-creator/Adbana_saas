<?php

namespace Tests\Feature;

use App\Logging\StructuredJsonFormatter;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use Tests\TestCase;

class ResilienciaContencionTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_expone_observabilidad_ram_concurrencia_y_errores_contenidos(): void
    {
        $res = $this->getJson('/api/health');

        $res->assertStatus(200)
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Response-Time-Ms')
            ->assertHeader('X-Bottleneck-Stage', 'none')
            ->assertHeader('X-Failure-Reason', 'NONE')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'total_ms',
                'services' => [
                    'app' => ['status'],
                    'database' => ['status', 'latency_ms'],
                    'cache' => ['status', 'driver', 'latency_ms'],
                ],
                'memoria_ram' => ['usada_mb', 'pico_mb', 'limite_php'],
                'concurrencia' => [
                    'transacciones_activas_bd',
                    'limite_transacciones_bd',
                    'saturacion_pct',
                    'estado_cuello_botella',
                ],
                'errores_contenidos' => ['total', 'por_motivo'],
            ]);
    }

    public function test_contencion_lock_timeout_postgresql_devuelve_503_json_y_cabeceras(): void
    {
        Route::get('/api/_test_lock_timeout', function () {
            $pdo = new PDOException('SQLSTATE[55P03]: Lock not available: 7 ERROR: canceling statement due to lock timeout', 0);
            $pdo->errorInfo = ['55P03', 7, 'canceling statement due to lock timeout'];
            throw new QueryException('pgsql', 'SELECT * FROM productos FOR UPDATE', [], $pdo);
        });

        $res = $this->getJson('/api/_test_lock_timeout');

        $res->assertStatus(503)
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Response-Time-Ms')
            ->assertHeader('X-Bottleneck-Stage', 'postgresql_lock_for_update')
            ->assertHeader('X-Failure-Reason', 'DB_LOCK_TIMEOUT')
            ->assertJsonPath('error', true)
            ->assertJsonPath('estado_servidor', 'error_contenido')
            ->assertJsonPath('codigo_fallo', 'DB_LOCK_TIMEOUT')
            ->assertJsonPath('cuello_de_botella', 'postgresql_lock_for_update')
            ->assertJsonPath('reintentable', true);
    }

    public function test_contencion_pool_saturado_devuelve_503_json_y_cabeceras(): void
    {
        Route::get('/api/_test_pool_saturated', function () {
            throw new PDOException('SQLSTATE[53300]: FATAL: sorry, too many clients already', 53300);
        });

        $res = $this->getJson('/api/_test_pool_saturated');

        $res->assertStatus(503)
            ->assertHeader('X-Failure-Reason', 'DB_POOL_SATURATED')
            ->assertHeader('X-Bottleneck-Stage', 'postgresql_lock_for_update')
            ->assertJsonPath('error', true)
            ->assertJsonPath('codigo_fallo', 'DB_POOL_SATURATED');
    }

    public function test_vistas_500_y_503_muestran_motivo_request_id_y_tiempo(): void
    {
        $html503 = view('errors.503', [
            'motivo_caida' => 'Cuello de botella controlado de prueba.',
            'codigo_fallo' => 'DB_CONCURRENCY_SHEDDING',
            'cuello_de_botella' => 'postgresql_lock_for_update',
            'request_id' => 'req-test-503',
            'tiempo_respuesta_ms' => 2.45,
        ])->render();

        $this->assertStringContainsString('DB_CONCURRENCY_SHEDDING', $html503);
        $this->assertStringContainsString('req-test-503', $html503);
        $this->assertStringContainsString('2.45 ms', $html503);

        $html500 = view('errors.500', [
            'motivo_caida' => 'Error interno contenido de prueba.',
            'codigo_fallo' => 'INTERNAL_SERVER_ERROR',
            'request_id' => 'req-test-500',
            'tiempo_respuesta_ms' => 4.10,
        ])->render();

        $this->assertStringContainsString('INTERNAL_SERVER_ERROR', $html500);
        $this->assertStringContainsString('req-test-500', $html500);
        $this->assertStringContainsString('4.10 ms', $html500);
    }

    public function test_formateador_json_lines_genera_linea_valida_con_campos_obligatorios(): void
    {
        $formatter = new StructuredJsonFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-11-20T18:30:00+00:00'),
            channel: 'structured',
            level: Level::Warning,
            message: 'bottleneck.detected',
            context: [
                'event' => 'bottleneck.detected',
                'request_id' => 'req-json-1',
                'method' => 'POST',
                'path' => '/api/carta/demo/pedidos',
                'status' => 503,
                'response_time_ms' => 1.85,
                'bottleneck_stage' => 'postgresql_lock_for_update',
                'failure_reason' => 'DB_CONCURRENCY_SHEDDING',
                'memory_mb' => 24.5,
            ],
        );

        $linea = $formatter->format($record);
        $this->assertStringEndsWith("\n", $linea);

        $decoded = json_decode(trim($linea), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('warning', $decoded['level']);
        $this->assertSame('bottleneck.detected', $decoded['event']);
        $this->assertSame('req-json-1', $decoded['request_id']);
        $this->assertSame('POST', $decoded['method']);
        $this->assertSame('/api/carta/demo/pedidos', $decoded['path']);
        $this->assertSame(503, $decoded['status']);
        $this->assertSame(1.85, $decoded['response_time_ms']);
        $this->assertSame('postgresql_lock_for_update', $decoded['bottleneck_stage']);
        $this->assertSame('DB_CONCURRENCY_SHEDDING', $decoded['failure_reason']);
        $this->assertSame(24.5, $decoded['memory_mb']);
    }
}
