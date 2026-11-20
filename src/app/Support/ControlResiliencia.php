<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Núcleo de Resiliencia, Control de Cuellos de Botella y Observabilidad (Capas 2, 3 y 4).
 */
final class ControlResiliencia
{
    public const REDIS_KEY_ACTIVE_TX = 'adbana:db_active_tx';

    public const REDIS_KEY_METRICS_PREFIX = 'adbana:metrics:contained:';

    public const REDIS_KEY_RPS_PREFIX = 'adbana:metrics:rps:';

    public const REDIS_KEY_LAST_BOTTLENECK = 'adbana:metrics:last_bottleneck';

    public const CODIGOS_FALLO = [
        'DB_CONCURRENCY_SHEDDING',
        'DB_LOCK_TIMEOUT',
        'DB_POOL_SATURATED',
        'REDIS_UNAVAILABLE',
        'RATE_LIMITED',
        'NGINX_FPM_QUEUE_SATURATED',
    ];

    /** @var array<string, int> Contadores locales de respaldo cuando Redis no está activo (p.ej. tests) */
    private static array $localContainedCounts = [];

    private static int $localBurstWindow = 0;

    private static int $localBurstCount = 0;

    private static string $lastBottleneckState = 'ok';

    /**
     * Obtiene o genera un Request ID único y consistente durante todo el ciclo de la petición.
     */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('_request_id');
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $header = trim((string) $request->header('X-Request-Id', ''));
        $id = ($header !== '' && strlen($header) <= 64)
            ? $header
            : Str::uuid()->toString();

        $request->attributes->set('_request_id', $id);

        return $id;
    }

    /**
     * Calcula los milisegundos transcurridos desde el inicio de la petición.
     */
    public static function elapsedMs(Request $request): float
    {
        $inicio = $request->attributes->get('_req_start');
        if (! is_float($inicio)) {
            $inicio = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
        }

        return round(max(0.01, (microtime(true) - $inicio) * 1000), 2);
    }

    /**
     * Adquiere atómicamente una ranura en el semáforo de transacciones activas hacia PostgreSQL RDS.
     * Usa script Lua en Redis (1 RTT < 0.5 ms, TTL de seguridad 5s) para evitar drift bajo avalanchas.
     *
     * @return array{adquirido: bool, activas: int, limite: int, redis_activo: bool}
     */
    public static function adquirirRanuraDb(): array
    {
        $limite = max(1, (int) config('adbana.max_db_active_tx', 22));
        $ttl = max(2, (int) config('adbana.db_semaphore_ttl_s', 5));

        if (! self::redisHabilitado()) {
            return ['adquirido' => true, 'activas' => 1, 'limite' => $limite, 'redis_activo' => false];
        }

        $lua = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
if current > tonumber(ARGV[1]) then
    redis.call('DECR', KEYS[1])
    return -current
end
return current
LUA;

        $resultado = (int) Redis::connection()->eval($lua, 1, self::REDIS_KEY_ACTIVE_TX, $limite, $ttl);

        if ($resultado < 0) {
            return [
                'adquirido' => false,
                'activas' => max($limite, abs($resultado) - 1),
                'limite' => $limite,
                'redis_activo' => true,
            ];
        }

        return [
            'adquirido' => true,
            'activas' => $resultado,
            'limite' => $limite,
            'redis_activo' => true,
        ];
    }

    /**
     * Libera atómicamente la ranura adquirida en Redis (invocado siempre en bloque finally).
     */
    public static function liberarRanuraDb(): void
    {
        if (! self::redisHabilitado()) {
            return;
        }

        $ttl = max(2, (int) config('adbana.db_semaphore_ttl_s', 5));
        $lua = <<<'LUA'
local current = redis.call('DECR', KEYS[1])
if current < 0 then
    redis.call('SET', KEYS[1], 0, 'EX', tonumber(ARGV[1]))
    return 0
end
return current
LUA;

        try {
            Redis::connection()->eval($lua, 1, self::REDIS_KEY_ACTIVE_TX, $ttl);
        } catch (Throwable) {
            // El TTL de 5s garantiza la auto-recuperación del semáforo si Redis falla al liberar.
        }
    }

    /**
     * Obtiene el número actual de transacciones activas en PostgreSQL registradas en Redis.
     */
    public static function transaccionesActivasDb(): int
    {
        if (! self::redisHabilitado()) {
            return 0;
        }

        try {
            return max(0, (int) Redis::connection()->get(self::REDIS_KEY_ACTIVE_TX));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Incrementa el contador de errores contenidos por código de fallo para observabilidad en /api/health.
     */
    public static function incrementarErrorContenido(string $codigoFallo, string $cuelloBotella): void
    {
        self::$localContainedCounts[$codigoFallo] = (self::$localContainedCounts[$codigoFallo] ?? 0) + 1;

        if (! self::redisHabilitado()) {
            return;
        }

        try {
            $redis = Redis::connection();
            $redis->incr(self::REDIS_KEY_METRICS_PREFIX.$codigoFallo);
            $redis->setex(
                self::REDIS_KEY_LAST_BOTTLENECK,
                300,
                json_encode([
                    'codigo_fallo' => $codigoFallo,
                    'cuello_de_botella' => $cuelloBotella,
                    'timestamp' => now()->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // Nunca bloquear la respuesta fail-fast por un fallo secundario de métricas.
        }
    }

    /**
     * Lee el resumen de errores contenidos y último cuello de botella detectado para /api/health.
     *
     * @return array{total: int, por_motivo: array<string, int>, ultimo_evento: ?array<string, mixed>}
     */
    public static function resumenErroresContenidos(): array
    {
        $porMotivo = [];
        $total = 0;
        $ultimoEvento = null;

        if (self::redisHabilitado()) {
            try {
                $redis = Redis::connection();
                foreach (self::CODIGOS_FALLO as $codigo) {
                    $valor = max(0, (int) $redis->get(self::REDIS_KEY_METRICS_PREFIX.$codigo));
                    $porMotivo[$codigo] = $valor;
                    $total += $valor;
                }
                $raw = $redis->get(self::REDIS_KEY_LAST_BOTTLENECK);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $ultimoEvento = $decoded;
                    }
                }

                return [
                    'total' => $total,
                    'por_motivo' => $porMotivo,
                    'ultimo_evento' => $ultimoEvento,
                ];
            } catch (Throwable) {
                // Fallback a contadores locales si Redis está caído.
            }
        }

        foreach (self::CODIGOS_FALLO as $codigo) {
            $valor = self::$localContainedCounts[$codigo] ?? 0;
            $porMotivo[$codigo] = $valor;
            $total += $valor;
        }

        return [
            'total' => $total,
            'por_motivo' => $porMotivo,
            'ultimo_evento' => $ultimoEvento,
        ];
    }

    /**
     * Construye el contrato JSON unificado en español y las cabeceras de diagnóstico (< 15 ms).
     *
     * @param array<string, string|int> $extraHeaders
     */
    public static function respuestaContenida(
        Request $request,
        int $status,
        string $codigoFallo,
        string $motivoCaida,
        string $cuelloBotella,
        bool $reintentable = true,
        int $retryAfterMs = 250,
        ?string $message = null,
        array $extraHeaders = [],
    ): JsonResponse {
        $requestId = self::requestId($request);
        $elapsedMs = self::elapsedMs($request);

        $request->attributes->set('_bottleneck_stage', $cuelloBotella);
        $request->attributes->set('_failure_reason', $codigoFallo);
        $request->attributes->set('_contained_error', true);
        $request->attributes->set('_motivo_caida', $motivoCaida);

        self::incrementarErrorContenido($codigoFallo, $cuelloBotella);

        $payload = [
            'error' => true,
            'estado_servidor' => 'error_contenido',
            'codigo_fallo' => $codigoFallo,
            'motivo_caida' => $motivoCaida,
            'cuello_de_botella' => $cuelloBotella,
            'tiempo_respuesta_ms' => $elapsedMs,
            'reintentable' => $reintentable,
            'retry_after_ms' => $retryAfterMs,
            'request_id' => $requestId,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $headers = array_merge($extraHeaders, [
            'X-Request-Id' => $requestId,
            'X-Response-Time-Ms' => number_format($elapsedMs, 2, '.', ''),
            'X-Bottleneck-Stage' => $cuelloBotella,
            'X-Failure-Reason' => $codigoFallo,
        ]);

        if ($reintentable && ! isset($headers['Retry-After'])) {
            $headers['Retry-After'] = (string) max(1, (int) ceil($retryAfterMs / 1000));
        }

        return response()->json($payload, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Registra un evento en el canal JSON Lines (adbana-structured.json.log) con muestreo inteligente
     * bajo ráfagas > 1,000 req/s para proteger el I/O de disco y la RAM.
     *
     * @param array<string, mixed> $context
     */
    public static function registrarLogEstructurado(string $level, string $event, array $context): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $rps = self::estimarRpsActual();
        $umbralRafaga = max(100, (int) config('adbana.log_burst_rps_threshold', 1000));
        $sampleRate = max(2, (int) config('adbana.log_sample_rate_high_load', 50));

        $failureReason = (string) ($context['failure_reason'] ?? 'NONE');
        $estadoActual = $failureReason === 'NONE' ? 'ok' : $failureReason;
        $cambioEstado = $estadoActual !== self::$lastBottleneckState;
        self::$lastBottleneckState = $estadoActual;

        $esErrorCritico = in_array($level, ['error', 'critical', 'alert', 'emergency'], true);

        if ($rps > $umbralRafaga && ! $cambioEstado && ! $esErrorCritico) {
            // Muestreo 1 de cada N en ráfagas masivas para éxitos repetidos y shedding sostenido
            if ((self::$localBurstCount % $sampleRate) !== 0) {
                return;
            }
            $context['sampled'] = true;
            $context['sample_rate'] = $sampleRate;
            $context['estimated_rps'] = $rps;
        }

        try {
            $context['event'] = $event;
            Log::channel('structured')->log($level, $event, $context);
        } catch (Throwable) {
            // Nunca degradar la latencia ni romper una petición por fallo de escritura de log.
        }
    }

    public static function redisHabilitado(): bool
    {
        return ! app()->environment('testing') && config('cache.default') === 'redis';
    }

    private static function estimarRpsActual(): int
    {
        $segundoActual = time();
        if (self::$localBurstWindow !== $segundoActual) {
            self::$localBurstWindow = $segundoActual;
            self::$localBurstCount = 0;
        }
        self::$localBurstCount++;

        // En PHP-FPM multi-worker (26 workers), multiplicar el contador local por ~20 ofrece
        // una estimación O(1) sin añadir latencia de red a Redis en cada log.
        return self::$localBurstCount * 20;
    }
}
