# Diagnóstico y mejora de navegación — 2026-10-26

## Evidencia local

- PHP básico: 68 ms. OPcache activo.
- /up, sin consultas SQL: 3,39–3,71 s en la muestra autenticada.
- /api/caja/actual con turno vacío: 4,12–4,34 s; SQL: 57–73 ms, 7 consultas incluidas autenticación y permisos.
- Prueba A/B temporal de /up: revisión normal de archivos 3,07–3,98 s; revisión de timestamps desactivada solamente en esa petición 203–208 ms. La configuración normal se restauró por petición y los scripts temporales se retiraron.

Esto confirma que la comprobación de archivos es una parte importante del tiempo, coherente con el bind mount de Windows. No demuestra la capacidad máxima del servidor ni la latencia futura de producción.

## Aplicado

- Cambiar de sección no usa el bloqueo global de operaciones. El menú permanece utilizable mientras se carga una lectura.
- Indicador de carga; los controles de operación de la sección esperan los datos actualizados. Se mantienen los bloqueos del backend y las transacciones.
- Las respuestas de secciones antiguas no actualizan el contenido de otra sección. Las cargas de caja simultáneas comparten una petición en curso.
- El resumen de caja calcula entradas y salidas en una consulta agregada, en vez de dos.
- Server-Timing solo en entorno local, con tiempo total y tiempo SQL, sin mostrar consultas ni parámetros.

## Perfil rápido preparado (requiere activación desde CMD)

    cd /d C:\Users\USER\Desktop\SAAS\Adbana_saas
    iniciar-rapido.cmd

El script prepara las dependencias de Composer en un volumen Linux vendor_runtime, y después arranca el mismo proyecto usando docker-compose.fast.yml. No mueve ni elimina la base de datos. La primera descarga requiere internet y puede tardar. Para conservar este perfil, usar iniciar-rapido.cmd en los siguientes arranques; docker compose up -d sin el override vuelve al montaje normal.

El agente no pudo activar ni medir este perfil porque Docker rechazó el acceso al named pipe desde su sesión. La configuración de Compose se validó, pero falta ejecutar el arranque y repetir las mediciones sobre él. No se promete una cifra de mejora hasta comprobarlo.

No se dejó opcache.validate_timestamps=0: durante desarrollo podría ocultar cambios de PHP. Para producción conviene una imagen con código y dependencias versionadas, configuración propia y despliegue con reinicio controlado.

## Verificación

PostgreSQL: 93 pruebas, 546 assertions, todas aprobadas. Node: navegación durante carga, respuesta vieja que no cancela carga nueva, deduplicación de caja y pruebas previas de sesión/reintentos/roles aprobadas.

Mediciones completas: medicion-servidor-local.json y comparacion-revision-archivos.json en los artefactos de esta conversación. Se usó una cuenta y restaurante temporales aislados, retirados al terminar.
