# Adbana: demostración local de plato en AR

Prototipo independiente. Pizza externa de Innerscene, CC0 (ver models/CREDITOS.md). Sustituye por completo las geometrías anteriores. Escala AR ilustrativa. Sin conexión a ventas, usuarios o stock.

## PC con Docker encendido
http://localhost:8080/demo-ar/
Imprimir tarjeta: http://localhost:8080/demo-ar/marcador.html

## Emulador Android
1. Abrir un dispositivo virtual en Android Studio (Device Manager).
2. Configurar su cámara trasera como webcam de la PC si se probará una tarjeta física. La escena virtual predeterminada no contiene el marcador HIRO.
3. En CMD ejecutar (SDK en ubicación predeterminada):
   "%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe" devices
   "%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe" reverse tcp:8080 tcp:8080
4. En Chrome del emulador abrir http://localhost:8080/demo-ar/
5. Probar 3D, giro, tarjeta y botón AR. Permitir la cámara. Mostrar la tarjeta impresa completa a la webcam. Evitar que otra aplicación tenga ocupada la cámara.

Si hay más de un dispositivo, usar adb -s ID reverse tcp:8080 tcp:8080.
No usar http://10.0.2.2:8080 para la cámara: puede no ser un contexto seguro. El puente reverse permite usar localhost. No desactivar protecciones del navegador.

## Evidencias y límites
- Vista 3D y giro: comprobados en navegador de escritorio.
- AR: código preparado; detección real del marcador pendiente de probar con cámara/emulador/teléfono.
- Emulador: no se encontró adb en la ruta predeterminada al preparar esta demo. Puede estar instalado en otra ubicación.
- No usar el visor 3D como evidencia de AR: deben verse cámara, tarjeta y estado Marcador detectado.
- Capturar evidencia de marcador detectado, pérdida del marcador y recuperación. Probar denegación de cámara y regreso a vista 3D.
- No demuestra compatibilidad universal, precisión física, ni rendimiento masivo del POS.
- En teléfono por red local, publicar con HTTPS antes de usar cámara. localhost en el teléfono apunta al teléfono.

## Dependencias locales
A-Frame 1.6.0 (MIT): https://github.com/aframevr/aframe
AR.js 3.4.7 (MIT; componentes de terceros conservan sus avisos): https://github.com/AR-js-org/AR.js/tree/3.4.7
Marcador HIRO, patrón y parámetros de cámara tomados de data/ del mismo repositorio AR.js.
Se conservan bundles originales en vendor/. No se requiere CDN durante la presentación.
