<?php

namespace App\Support;

final class HuellaOperacion
{
    public static function de(array $datos): string
    {
        unset($datos['clave_operacion']);
        $ordenar = function (array $valor) use (&$ordenar): array {
            if (! array_is_list($valor)) {
                ksort($valor);
            }
            foreach ($valor as &$item) {
                if (is_array($item)) {
                    $item = $ordenar($item);
                }
            }

            return $valor;
        };

        return hash('sha256', json_encode($ordenar($datos), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
