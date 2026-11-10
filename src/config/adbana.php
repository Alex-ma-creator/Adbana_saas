<?php

return [
    'limite_pedidos_ip' => (int) env('LIMITE_PEDIDOS_IP_POR_MINUTO', 300),
    'limite_pedidos_restaurante' => (int) env('LIMITE_PEDIDOS_RESTAURANTE_POR_MINUTO', 1200),
    // Subir solo en el entorno destinado a pruebas de carga.
    'limite_login' => (int) env('LIMITE_LOGIN_POR_MINUTO', 500),
    'limite_pedidos' => (int) env('LIMITE_PEDIDOS_POR_MINUTO', env('LIMITE_PEDIDOS', 1000)),
];
