<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formateador JSON Lines estricto para observabilidad estructurada (adbana-structured.json.log).
 * Cada linea es un objeto JSON valido con campos planos listos para ingestión en CloudWatch/ELK/jq.
 */
final class StructuredJsonFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $ctx = $record->context;

        $payload = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.vP'),
            'level' => strtolower($record->level->getName()),
            'event' => (string) ($ctx['event'] ?? $record->message ?: 'http.request'),
            'request_id' => $ctx['request_id'] ?? null,
            'method' => $ctx['method'] ?? null,
            'path' => $ctx['path'] ?? null,
            'status' => isset($ctx['status']) ? (int) $ctx['status'] : null,
            'response_time_ms' => isset($ctx['response_time_ms']) ? round((float) $ctx['response_time_ms'], 2) : null,
            'bottleneck_stage' => $ctx['bottleneck_stage'] ?? 'none',
            'failure_reason' => $ctx['failure_reason'] ?? 'NONE',
            'memory_mb' => isset($ctx['memory_mb'])
                ? round((float) $ctx['memory_mb'], 2)
                : round(memory_get_usage(true) / 1048576, 2),
        ];

        foreach ($ctx as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return ($json !== false ? $json : '{"event":"log.encoding_error"}')."\n";
    }

    public function formatBatch(array $records): string
    {
        $output = '';
        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }
}
