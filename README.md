# Adbana SaaS

Sistema web para restaurantes: punto de venta, pedidos, carta pública, cocina, inventario y control de caja. Proyecto en desarrollo.

## Funciones implementadas

- Separación de datos por restaurante.
- Roles de dueño, cajero, mesero y cocina, con permisos personalizables por persona.
- Productos, categorías, precios, costos y movimientos de stock.
- Pedidos desde la carta pública y desde el panel.
- Cobros con registro del medio de pago y vuelto en efectivo.
- Apertura, movimientos y cierre de caja por operador.
- Resumen de ventas, gastos y ganancias.

Los pagos digitales se confirman manualmente. La constancia interna no es un comprobante electrónico SUNAT. El modo sin internet, las suscripciones y la integración automática con impresoras no están implementados. Existe una demo visual de pizza en 3D y AR con marcador, accesible desde la carta; la detección AR real queda pendiente de probar con cámara.

## Requisitos

Docker Desktop con contenedores Linux y Docker Compose. No hace falta instalar PHP en Windows para usar el entorno Docker.

## Instalación local en CMD

Desde la raíz del repositorio:

```cmd
copy .env.example .env
copy src\.env.example src\.env
```

Elegir una contraseña de desarrollo y configurar el mismo DB_PASSWORD en ambos archivos. Nunca subir los archivos .env al repositorio.

```cmd
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Abrir http://localhost:8080/panel para crear el restaurante o ingresar. La carta pública queda en http://localhost:8080/c/SLUG-DEL-RESTAURANTE. El dueño carga productos y crea personal desde el panel.

Los estilos y Alpine.js se sirven desde public; el panel actual no requiere compilar Vite para probarlo.

## Pruebas

Crear una base PostgreSQL dedicada llamada adbana_test si aún no existe:

```cmd
docker compose exec db psql -U adbana -d postgres -c "CREATE DATABASE adbana_test;"
docker compose exec app php artisan test
docker compose restart app
```

El comando CREATE DATABASE se ejecuta solo una vez. La suite usa RefreshDatabase sobre adbana_test; no apuntar las pruebas a una base con datos reales.

El script k6/carga.js crea datos de prueba. Usarlo únicamente en un entorno dedicado; sus resultados no certifican capacidad de producción.

## Estructura

- src/: aplicación Laravel, API, vistas y pruebas.
- src/docs/: alcance funcional y roles.
- k6/: ensayo de carga.
- Dockerfile y docker-compose.yml: servicios locales.

## Colaboración

Crear una rama por cambio y proponer una revisión antes de integrarla a main. Cada colaborador configura sus propios .env. No compartir contraseñas a través de commits.

Este repositorio no incluye datos de restaurantes, registros, dependencias instaladas ni claves privadas. Antes de un despliegue comercial se requieren revisión de seguridad, HTTPS, copias de respaldo, recuperación y pruebas de concurrencia de ventas y cobros.

## Cambios recientes

- Panel renovado con perfil, resumen de ventas y tipografía local.
- Equipo con permisos por persona, auditoría y protección de turnos abiertos.
- Cobro y entrega separados; reintentos de pedidos públicos protegidos contra duplicación.
- Contexto explícito de restaurante para tareas sin sesión.
- Inicio rápido para Windows: ejecutar `iniciar-rapido.cmd` después de la instalación inicial. Guarda las dependencias PHP en un volumen de Docker para evitar la lentitud del montaje Windows.
- Después de actualizar un proyecto existente: `docker compose exec app php artisan migrate` y `docker compose exec app php artisan view:clear`.
- Demo desde **Ver carta → Probar 3D y realidad aumentada**, con retorno a la carta. Sus bibliotecas y modelos están incluidos para la presentación sin CDN.

### Evidencia de pruebas

Suite verificada: **100 pruebas / 594 verificaciones** sobre PostgreSQL de prueba, más la regresión JavaScript `node src/tests/Frontend/panel-carta.cjs`.

Prueba local k6 de lectura de carta durante 30 segundos, con pausa de 1 segundo por usuario:

| Usuarios virtuales | Peticiones | Promedio | p95 | Errores |
| --- | --- | --- | --- | --- |
| 50 | 1450 | 63,27 ms | 102,81 ms | 0 % |
| 100 | 2800 | 80,08 ms | 122,60 ms | 0 % |

Resúmenes originales en `k6/resultado-50.json` y `k6/resultado-100.json`. Son lecturas de un endpoint en el entorno local: no acreditan cobros concurrentes, capacidad cloud ni una prueba prolongada de estrés.

Más detalles: [Documentación completa (25%)](src/docs/DOCUMENTACION-PROYECTO-25.md), [Diagrama de base de datos](src/docs/DIAGRAMA-BD.md), [Permisos](src/docs/PERMISOS.md), [rendimiento local](src/docs/RENDIMIENTO-LOCAL.md), [consistencia](src/docs/CONSISTENCIA-Y-ESCALABILIDAD.md) y [demo AR](src/public/demo-ar/README.md).
## Actualización de consistencia y acceso

Después de actualizar, ejecutar `iniciar-rapido.cmd` y, con el mismo perfil, `docker compose -f docker-compose.yml -f docker-compose.fast.yml exec app php artisan migrate`. La migración añade huella del POS y registro transaccional de movimientos. Las claves POS anteriores sin huella responden 409 al reintentarlas; revisar el pedido existente antes de crear otro.

Los movimientos de stock y caja ahora exigen `clave_operacion` UUID. El panel conserva solicitudes pendientes en sessionStorage hasta confirmarlas. Tras una respuesta perdida, reintentar sin cambiar los datos. No borrar el almacenamiento o cambiar de pestaña para iniciar otro movimiento sin comprobar primero el historial.

La carta envía `X-Adbana-Cliente` con una identidad local. Los límites combinan cliente/IP/restaurante. No constituye identificación verificada ni sustituye los límites generales. Ajustar con mediciones; para clientes antiguos sin cabecera se usa IP.

Recuperación: `/api/auth/recuperar` y `/api/auth/restablecer`, con enlace de un solo uso que vence según `config/auth.php`. En desarrollo MAIL_MAILER=log guarda el enlace en logs: no compartirlos. Para envío real configurar SMTP y APP_URL. Las sesiones expiran a las 12 horas por defecto (SANCTUM_EXPIRATION en minutos); Perfil permite consultarlas y revocarlas. Cambiar contraseña mediante recuperación revoca todas. El scheduler limpia tokens expirados y el login limita a diez sesiones por cuenta.

El panel pagina pedidos y cocina, con búsqueda en el servidor. Mesas obtiene totales agregados de todos los pedidos abiertos y abre sus detalles paginados. No se limita el total a la primera página.

## Preparación de producción

`compose.production.yml` es independiente del entorno local y no incluye respaldos. Requiere dominio/DNS, puertos 80/443 disponibles y configuración SMTP. Copiar `.env.production.example` a `.env.production`, generar una APP_KEY propia y elegir DB_PASSWORD. El gateway Caddy solicita certificados al apuntar el dominio al servidor. HTTPS no queda desplegado por crear estos archivos.

Configurar GATEWAY_IP y APP_NETWORK_SUBNET en una subred libre; la aplicación confía únicamente en ese gateway para recuperar la IP del cliente. Revisar esta configuración si se añade otro proxy. PostgreSQL, Redis y Nginx no publican puertos. El Docker Compose local conserva PostgreSQL y Adminer solo en 127.0.0.1.

Preparación: `docker compose --env-file .env.production -f compose.production.yml build`. Iniciar BD/Redis y ejecutar las migraciones mediante un contenedor app de una sola ejecución antes de servir tráfico: `docker compose --env-file .env.production -f compose.production.yml run --rm app php artisan migrate --force`. Después iniciar los servicios con `up -d`. No ejecutar migraciones automáticamente en cada réplica.

El perfil incorpora worker y scheduler. Los trabajos deben establecer contexto de restaurante y diseñarse para reintentos. El tiempo de reintento Redis (180 s) supera el timeout del worker (90 s). El volumen storage conserva archivos; es persistencia, no respaldo. Las imágenes públicas deben publicarse mediante enlaces/almacenamiento configurados antes de habilitar carga de archivos. Validar construcción, certificados, correo y recursos en el host destino. No se garantiza disponibilidad sin esa validación.

Pruebas: suite PHPUnit sobre PostgreSQL de prueba y scripts `node src/tests/Frontend/panel-carta.cjs`, `node src/tests/Frontend/demo-idiomas.cjs` y `node src/tests/Frontend/robustez.cjs`. Nunca usar los datos operativos como base de pruebas.

## Administración de Adbana

El panel general se abre en `/administracion`. Sus cuentas pertenecen a `administradores_plataforma`, separadas de los usuarios de restaurantes. No existe registro público de administradores.

Después de ejecutar las migraciones, crear el primer acceso con `docker compose -f docker-compose.yml -f docker-compose.fast.yml exec app php artisan adbana:crear-superadmin`. El comando solicita nombre, correo y contraseña oculta de al menos 12 caracteres. Solo funciona si no existe ninguna cuenta de plataforma, incluso retirada. Los siguientes accesos se crean desde Administradores. No hay contraseñas predeterminadas ni promociones automáticas de dueños.

El superadministrador puede crear, editar, desactivar y retirar accesos. Un administrador limitado recibe permisos de dashboard, consulta de restaurantes, activación/suspensión y auditoría. Solo superadministradores gestionan administradores. Cambios críticos requieren contraseña de confirmación y motivo; se auditan sin guardar contraseñas. No se permite cambiar el propio acceso ni retirar el último superadministrador. La retirada es lógica para preservar el historial y corta el acceso al validar la siguiente petición. Los cambios de cuenta invalidan las sesiones previas mediante versión de sesión.

El panel usa cookies de sesión y protección CSRF, no los tokens del POS. La configuración de producción activa cookies Secure y HttpOnly. El dashboard muestra actividad real del entorno con periodo de 7 o 30 días, restaurantes activos, pedidos, altas y equipo. Suscripciones e incidencias se señalan como sin configurar; no se inventan ingresos ni cifras de disponibilidad. La autenticación de segundo factor y un módulo comercial de suscripciones no forman parte de esta versión.
