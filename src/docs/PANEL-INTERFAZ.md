# Panel Adbana: diseño y alcance

Diseño propio inspirado en la distribución de la referencia seleccionada: lateral carbón, superficies blancas, acento rojo y llama SVG propia. No incorpora archivos ni logotipo de DashPOS.

- Dueño: inicio con ventas cobradas, pedidos del día, pedidos abiertos, ticket promedio, gráfico de siete días, productos más vendidos y pedidos recientes. Consultas filtradas por restaurante. Las cifras proceden de PostgreSQL; no hay valores de ejemplo en el frontend.
- Cajero / mesero: inicio en punto de venta, con secciones según permisos. Cocina inicia en sus comandas. El rediseño conserva los endpoints y controles existentes.
- Perfil: nombre, correo, rol e iniciales. Actualización propia mediante PATCH /api/perfil; exige contraseña actual para cambiar correo o contraseña. La contraseña nueva se confirma y las otras sesiones se revocan. No incluye subida de fotografías.
- Avisos: consulta a demanda los pedidos pendientes / comandas y las alertas de stock permitidas para el rol. No son notificaciones push, ni un historial persistente de eventos.
- Tema claro por defecto; tema oscuro opcional guardado en el navegador. Los colores se centralizan en variables CSS para una paleta configurable futura.
- Sin librerías adicionales de gráficos o animación. Gráfico SVG e íconos locales. Transiciones breves, con respeto a prefers-reduced-motion.
- Responsive comprobado en escritorio y viewport 390x844. Menú lateral plegable, tarjetas en dos columnas en móvil, tablas con scroll interno y formularios en una columna.

Verificación: 91 pruebas Laravel / 534 assertions en PostgreSQL; prueba frontend Node existente aprobada; inspección del dashboard, perfil, modo oscuro y vista móvil en navegador. Capturas obtenidas con una cuenta de prueba independiente, retirada tras la revisión. No representan las ventas reales del usuario.

Para ver cambios: abrir /panel y Ctrl+F5. No requiere nuevas migraciones para este rediseño. El cambio previo de consistencia sí tiene su propia migración.
