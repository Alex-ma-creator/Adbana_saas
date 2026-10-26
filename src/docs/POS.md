# Primera operación de Adbana

## Activar en desarrollo (CMD, desde la raíz del proyecto)

```cmd
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan test
```

La migración agrega tablas y campos; no usar migrate:fresh sobre datos que quieras conservar.

Abrir http://localhost:8080/panel. Ingresar con una cuenta existente o usar Crear mi restaurante. Cada restaurante nuevo comienza sin productos. El dueño crea categorías, productos y cajeros.

## Recorrido de comprobación

1. En Productos y stock crear una cerveza con costo 5, precio 8 y stock 20.
2. En Mi caja abrir con fondo inicial 100.
3. En Punto de venta seleccionar una cerveza, registrar y cobrar, elegir efectivo e ingresar 10.
4. Debe quedar total 8, vuelto 2, stock 19 y efectivo esperado 108.
5. Puede imprimirse una constancia interna. No es comprobante SUNAT.
6. Enviar otro pedido a cocina, avanzar confirmado/preparando/listo y cobrarlo al entregar.
7. Cancelar un pedido sin cobrar devuelve stock. Los pedidos entregados no se cancelan desde esta versión.
8. Verificar la separación entre efectivo y pagos digitales. Estos últimos se confirman manualmente; no hay conexión con bancos ni pasarela.
9. Registrar entradas y salidas de efectivo y cerrar con lo contado. La diferencia queda guardada.
10. Un cajero puede operar ventas/caja; solo el dueño administra productos, equipo, gastos y reportes.

## Alcance y límites

- Caja por operador. Los gastos contables y los movimientos de efectivo se registran por separado para evitar asumir que todo gasto se paga de caja.
- Mesas agrupadas por la identificación escrita en el pedido. Todavía no hay plano de salón, reservas, división de cuenta ni unión de pedidos.
- Cobro y entrega son independientes. Puede cobrarse un pedido antes de prepararlo; la entrega exige estado listo y pago registrado. Los pedidos pagados no se cancelan directamente: falta implementar devoluciones monetarias.
- Cocina se actualiza cada 15 segundos; no usa WebSockets.
- Las ventas anteriores a esta migración conservan sus datos y no se asignan retroactivamente a cajas nuevas.
- El reporte de ventas usa cobrado_at y rangos diarios semiabiertos. Incluye pedidos pagados aunque todavía estén en cocina. No recalcula fechas de cobro de pedidos históricos que no las tengan.
- Stock por producto terminado. No hay recetas ni consumo de ingredientes.
- No incluye facturación SUNAT, devoluciones monetarias, pagos divididos, integración con impresora térmica, modo offline o múltiples sucursales.
- Para producción faltan pruebas reales de concurrencia, respaldo y recuperación, HTTPS, revisión de sesiones y observabilidad. Esta implementación no certifica capacidad empresarial.

## Verificaciones realizadas por el agente

Sintaxis JavaScript y comprobaciones simuladas de carrito, stock, total, vuelto, venta y cobro. Se agregaron pruebas Laravel para caja, aislamiento de restaurantes, reintentos, importes y permisos. La ejecución de Laravel y la migración quedaron pendientes por falta de acceso de la sesión al motor Docker.
