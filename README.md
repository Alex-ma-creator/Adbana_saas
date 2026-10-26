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

Más detalles: [Permisos](src/docs/PERMISOS.md), [rendimiento local](src/docs/RENDIMIENTO-LOCAL.md), [consistencia](src/docs/CONSISTENCIA-Y-ESCALABILIDAD.md) y [demo AR](src/public/demo-ar/README.md).