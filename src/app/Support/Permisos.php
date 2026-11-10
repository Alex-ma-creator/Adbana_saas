<?php

namespace App\Support;

final class Permisos
{
    public const CATALOGO = [
        'devoluciones' => ['nombre' => 'Autorizar devoluciones', 'descripcion' => 'Registrar devoluciones y anular ventas cobradas con motivo e historial.', 'grupo' => 'Administración'],
        'pedidos' => ['nombre' => 'Atender pedidos y mesas', 'descripcion' => 'Crear pedidos, confirmarlos y entregar los que estén listos.', 'grupo' => 'Operación'],
        'cocina' => ['nombre' => 'Gestionar cocina', 'descripcion' => 'Consultar comandas y marcar preparando o listo.', 'grupo' => 'Operación'],
        'caja' => ['nombre' => 'Cobrar y gestionar su caja', 'descripcion' => 'Cobrar pedidos y abrir, mover y cerrar su propio turno. No da acceso a cajas ajenas.', 'grupo' => 'Operación'],
        'cancelar' => ['nombre' => 'Cancelar pedidos sin cobrar', 'descripcion' => 'Cancelar pedidos indicando si vuelven al stock o son merma. No autoriza devoluciones de pagos.', 'grupo' => 'Operación'],
        'productos' => ['nombre' => 'Administrar productos y precios', 'descripcion' => 'Crear y editar productos, costos y categorías; incluye stock inicial de productos nuevos.', 'grupo' => 'Administración'],
        'stock' => ['nombre' => 'Gestionar inventario', 'descripcion' => 'Consultar existencias y registrar entradas, salidas y conteos con motivo.', 'grupo' => 'Administración'],
        'reportes' => ['nombre' => 'Ver resultados del negocio', 'descripcion' => 'Consultar ventas, costos y ganancias del restaurante.', 'grupo' => 'Administración'],
        'gastos' => ['nombre' => 'Registrar y consultar gastos', 'descripcion' => 'Registrar gastos contables. No retira dinero de una caja.', 'grupo' => 'Administración'],
        'equipo' => ['nombre' => 'Administrar el equipo', 'descripcion' => 'Gestionar empleados y delegar sus propios permisos. Solo el dueño puede conceder este acceso.', 'grupo' => 'Administración'],
    ];

    public static function base(string $rol): array
    {
        $claves = match ($rol) {
            'dueno' => array_keys(self::CATALOGO),
            'cajero' => ['pedidos', 'cocina', 'caja', 'cancelar'],
            'mozo' => ['pedidos'],
            'mozo_cajero' => ['pedidos', 'caja'],
            'cocina' => ['cocina'],
            default => [],
        };

        return array_fill_keys($claves, true) + array_fill_keys(array_keys(self::CATALOGO), false);
    }

    public static function resolver(string $rol, array $cambios = []): array
    {
        $base = self::base($rol);
        if ($rol === 'dueno') {
            return $base;
        }
        foreach ($cambios as $clave => $valor) {
            if (array_key_exists($clave, self::CATALOGO) && is_bool($valor)) {
                $base[$clave] = $valor;
            }
        }

        return $base;
    }
}
