<?php

$origenesPorDefecto = env('CORS_ALLOWED_ORIGINS', implode(',', array_filter([
    env('APP_URL', 'http://localhost:8080'),
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://web',
])));

$origenesPermitidos = array_values(array_unique(array_filter(array_map(
    static fn (string $origen): string => rtrim(trim($origen), '/'),
    explode(',', (string) $origenesPorDefecto)
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origenesPermitidos,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'Origin', 'X-CSRF-TOKEN'],

    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 86400,

    'supports_credentials' => true,
];
