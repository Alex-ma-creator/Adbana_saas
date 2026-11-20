<?php

return [
    'limite_pedidos_ip' => (int) env('LIMITE_PEDIDOS_IP_POR_MINUTO', 300),
    'limite_pedidos_restaurante' => (int) env('LIMITE_PEDIDOS_RESTAURANTE_POR_MINUTO', 1200),
    // Subir solo en el entorno destinado a pruebas de carga.
    'limite_login' => (int) env('LIMITE_LOGIN_POR_MINUTO', 500),
    'limite_pedidos' => (int) env('LIMITE_PEDIDOS_POR_MINUTO', env('LIMITE_PEDIDOS', 1000)),

    // Control de cuellos de botella y resiliencia (Capa 2 y 4)
    'max_db_active_tx' => (int) env('ADBANA_MAX_DB_ACTIVE_TX', 22),
    'db_semaphore_ttl_s' => (int) env('ADBANA_DB_SEMAPHORE_TTL_S', 5),
    'db_lock_timeout_ms' => (int) env('ADBANA_DB_LOCK_TIMEOUT_MS', 1800),
    'db_statement_timeout_ms' => (int) env('ADBANA_DB_STATEMENT_TIMEOUT_MS', 3500),
    'log_burst_rps_threshold' => (int) env('ADBANA_LOG_BURST_RPS', 1000),
    'log_sample_rate_high_load' => (int) env('ADBANA_LOG_SAMPLE_RATE', 50),
];
