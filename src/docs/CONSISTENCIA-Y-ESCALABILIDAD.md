# Correcciones de consistencia y rendimiento

## Pago y operación
POST /api/ventas/{id}/cobrar registra el pago y la caja, sin cambiar estado. PATCH /api/pedidos/{id}/estado con entregado exige listo y cobrado_at. El mozo puede entregar un pedido ya pagado. No se puede cancelar un pedido pagado sin devolución (devoluciones pendientes de implementar). Reintentar un cobro ya registrado devuelve el original incluso si su turno ya cerró. No se modifican retroactivamente pedidos históricos.

## Pedidos públicos
POST /api/carta/{slug}/pedidos requiere clave_operacion UUID. El cliente debe conservar clave y cuerpo exacto tras errores de conexión/5xx. Misma clave y cuerpo: mismo pedido; misma clave con otro cuerpo: HTTP 409. La tabla solicitudes_publicas tiene unicidad por restaurante+clave, bloqueo y pedido dentro de la misma transacción. Un error revierte la reserva y el stock. No borrar estas claves sin una política de retención: habilitaría reenvíos antiguos.
La carta conserva el intento en sessionStorage, incluso al recargar la pestaña. El reintento envía el cuerpo guardado, no cambios posteriores del formulario. Abrir otra pestaña o cerrar la sesión del navegador no garantiza conservar ese intento.

## Contexto del restaurante
Los modelos con BelongsToRestaurante requieren un usuario con restaurante o un contexto explícito para leer. Sin él lanzan LogicException; superadmin tampoco omite el filtro implícitamente. El contexto está registrado como scoped, con restauración mediante finally.

Ejemplo de proceso automático:

    app(RestauranteContexto::class)->ejecutar($restauranteId, fn () => Pedido::query()->get());

Una tarea puede declarar public int $restauranteId y devolver [new ParaRestaurante] en middleware(). El ID debe obtenerse de una fuente autorizada. Las consultas públicas sin scope filtran explícitamente por restaurante. DB::table y withoutGlobalScopes son accesos explícitos de bajo nivel y requieren revisión; esto no sustituye RLS en PostgreSQL.

## Reportes y consultas
Ingresos y costos se atribuyen a cobrado_at, sin exigir entrega. pedidos_cobrados cuenta pagos; pedidos_entregados cuenta los pagados en ese período que además están entregados. El día usa >= inicio y < inicio del día siguiente. Índice nuevo (restaurante_id,cobrado_at). Los filtros timestamp operativos de pedidos y auditoría también evitan whereDate. La zona es la configurada en Laravel; aún no existe zona horaria por restaurante.
Los importes de POS, carta y resumen se calculan en centavos. La búsqueda usa whereLike de Laravel.

## Panel
Login devuelve identidad, restaurante y permisos, sin un segundo /me. Se carga el contenido de la pestaña activa; polling solo con pestaña operativa visible, sin duplicar una lectura en curso. Las respuestas de pedidos y caja se descartan si cambia la sesión. Las mutaciones esperan el polling en curso antes de refrescar.

## Verificación (2026-10-26)
87 pruebas / 517 assertions: suite en SQLite aislado y PostgreSQL adbana_test, PHP 8.4 portátil. Prueba Node: carga selectiva, polling, sesión y cuerpo de reintento. Prueba PostgreSQL en base temporal exclusiva: 10 reintentos concurrentes => 1 pedido; 20 compras adicionales con 9 unidades => 9 aceptadas y 11 rechazadas. Stock final 0; 10 pedidos y 10 movimientos. Base temporal eliminada al finalizar. Es consistencia de API en procesos, no capacidad HTTP máxima ni prueba de 100 usuarios reales.

## Comandos para repetir en Docker

    docker compose exec app php artisan migrate
    docker compose exec app php artisan optimize:clear
    docker compose exec app php artisan test
    node src/tests/Frontend/panel-carta.cjs

Pruebas usan adbana_test; nunca apuntar pruebas a la base operativa. No subir .env ni secretos.
Faltan benchmark mixto sobre infraestructura destino, EXPLAIN ANALYZE con volumen representativo, monitorización, respaldo/restauración y despliegue productivo. Los cambios no certifican capacidad empresarial.
