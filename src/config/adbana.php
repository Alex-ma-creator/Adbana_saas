<?php

return [
    // Subir solo en el entorno destinado a pruebas de carga.
    'limite_login' => (int) env('LIMITE_LOGIN_POR_MINUTO', 500),
    'limite_pedidos' => (int) env('LIMITE_PEDIDOS_POR_MINUTO', env('LIMITE_PEDIDOS', 1000)),
];