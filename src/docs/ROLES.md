# Roles operativos de la primera versión

- Dueño: administración, personal, productos, costos, inventario, reportes y su caja.
- Cajero: ventas y su turno de caja, sin costos ni administración.
- Mozo: consulta productos, toma pedidos, consulta mesas y confirma pedidos; no cobra ni cancela.
- Mozo con cobro (mozo_cajero): lo anterior y cobros en su propio turno. No administra personal, costos ni reportes.
- Cocina: únicamente comandas y cambios a preparando/listo. La API no envía importes, costos ni contactos.

Los roles son valores iniciales asignados por el dueño o encargado autorizado. Las excepciones individuales se gestionan en Equipo → Permisos (ver PERMISOS.md). Todos los permisos se verifican en el servidor y se limitan al restaurante de la cuenta. No se añadió un administrador del local ni un encargado de inventario independiente en esta entrega.

## Caja de cerveza

Crear un producto «Caja de cerveza», con precio por caja, costo por caja y stock 15. Vender cantidad 1 descuenta a 14. No comparte automáticamente inventario con botellas. El movimiento se registra al crear el pedido; una cancelación admitida devuelve stock. Para llevar se usa recojo, sin mesa.

## Dinero del mozo

El mozo con cobro debe abrir su propio turno. Cada pago guarda caja, pedido, medio, recibido, vuelto y fecha; la caja identifica al operador. El dinero físico lo mantiene el operador hasta rendirlo. Cerrar el turno compara lo contado con lo esperado, pero no transfiere fondos a otro cajero. La transferencia y recepción entre cajas aún no está implementada.

La vista de mesas muestra el total de pedidos abiertos. Cada pedido se cobra por separado; no se agregó cobro único de varios pedidos ni división de cuentas. Cobro y entrega son independientes: entregar requiere que el pedido esté listo y pagado.

## Pruebas

Se agregaron RolesOperativosTest: mozo sin caja, cocina sin información financiera, aislamiento entre restaurantes, creación y desactivación de personal y mozo con cobro. La suite también se ejecuta con PHP portable en una base PostgreSQL de pruebas aislada.

Se midió la carta local con 50 clientes (150 solicitudes) y 100 clientes (300 solicitudes), tres lecturas seguidas por cliente. Todas respondieron 200. No se modificaron datos ni límites. Este ensayo mide lecturas de carta, no ventas/cobros concurrentes ni capacidad de producción. Resultados: p95 1422 ms con 50; p95 2069 ms con 100. Hace falta un ensayo controlado de escritura en una base dedicada y objetivos de rendimiento antes de afirmar capacidad empresarial.

## Adaptación

Aplicación web adaptable, no una app instalada. Se puede abrir en navegadores compatibles de tablet, laptop, PC/monitor conectado y celular. Faltan pruebas de uso por dispositivo, impresión integrada y continuidad sin internet.
