<?php

return [
    'paths' => ['api/restaurantes', 'api/carta/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CARTA_ORIGINS', 'http://localhost:5173')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Accept-Language', 'X-Adbana-Cliente'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
