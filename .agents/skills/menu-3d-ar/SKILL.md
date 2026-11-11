---
name: menu-3d-ar
description: Guía integral y mejores prácticas para implementar visualización 3D y Realidad Aumentada (AR) de platos y productos tanto en Web (WebAR, Google Scene Viewer, Quick Look) como en aplicaciones móviles Flutter (model_viewer_plus, ARCore). Incluye requerimientos de modelos GLB/USDZ, configuración de servidor (CORS/HTTPS), permisos de Android/iOS y resolución de problemas comunes.
---

# 🍽️ Guía Maestra: Visualización 3D y Realidad Aumentada (AR) para Menús y Cartas

Esta skill documenta todos los requisitos arquitectónicos, de red, de modelos 3D y de código necesarios para que los comensales puedan visualizar platos y productos en 3D interactivo y proyectarlos sobre la mesa de un restaurante mediante Realidad Aumentada (AR).

---

## 1. Arquitectura de Realidad Aumentada en Móviles

Existen 3 motores principales para proyectar 3D en AR en dispositivos de usuario:

| Motor | Plataforma | Cómo funciona | Requisitos de Red |
| :--- | :--- | :--- | :--- |
| **WebXR Device API** | Android (Chrome / Edge) | Abre la cámara **dentro** del navegador web sin salir de la página. | **Estrictamente HTTPS** (o `localhost` en el mismo dispositivo). |
| **Google Scene Viewer** | Android (Cualquier app / navegador) | Lanza la aplicación externa de Google (`com.google.android.googlequicksearchbox`) que ejecuta ARCore. | **Estrictamente HTTPS con certificado válido y público**. Rechaza `http://` y `127.0.0.1`. |
| **Apple AR Quick Look** | iOS (Safari / WebViews) | Abre el visor nativo de iOS para archivos `.usdz` o `.glb` (convertido al vuelo). | Requiere HTTPS en producción. |

---

## 2. La Regla de Oro: ¿Por qué falla AR en desarrollo local?

### ⚠️ El problema de `127.0.0.1` y Scene Viewer en Android:
Cuando el usuario pulsa **"Ver en mi espacio"** dentro de una app Flutter con `model_viewer_plus`, la librería ejecuta un Intent hacia Google Scene Viewer:
```text
intent://arvr.google.com/scene-viewer/1.2?mode=ar_preferred&file=http://127.0.0.1:40439/model#Intent;package=com.google.android.googlequicksearchbox;...
```
**¿Por qué se cierra inmediatamente la pantalla y no abre la cámara?**
1. **Aislamiento de Procesos:** La app de Google (`com.google.android.googlequicksearchbox`) corre en su propio proceso independiente del celular. Cuando intenta descargar el modelo de `127.0.0.1:40439`, intenta conectarse a sí misma, donde ese puerto **no existe** (estaba abierto sólo en el sandbox privado de tu app Flutter).
2. **Rechazo de HTTP Plano:** Google Scene Viewer **no permite URLs inseguras `http://`**. Requiere una URL que empiece por `https://`.
3. **Solución Obligatoria:** El modelo 3D debe estar alojado en una URL accesible por internet con **HTTPS** (o mediante un túnel seguro como Cloudflare Tunnel o Ngrok durante desarrollo).

---

## 3. Requisitos del Dispositivo Android del Usuario

Para que un dispositivo Android pueda proyectar AR sobre la mesa:
1. **Google Play Services para RA (ARCore):**
   - Debe estar instalado desde Google Play Store (`com.google.ar.core`).
   - En teléfonos Xiaomi / Poco / Redmi (MIUI / HyperOS), verificar que no tenga permisos revocados de cámara o restricciones agresivas de batería.
2. **App de Google Actualizada:**
   - La app "Google" (`com.google.android.googlequicksearchbox`) debe estar instalada y actualizada, ya que contiene el runtime de Scene Viewer.
3. **Soporte de Hardware:**
   - El procesador y sensores (giroscopio, acelerómetro, cámara con calibración) deben estar en la lista oficial de dispositivos compatibles con ARCore de Google.

---

## 4. Requisitos y Estándares del Modelo 3D (GLB)

Para que la comida se vea realista sobre una mesa de restaurante:

### A. Escala Métrica Real (Crítico para AR)
- **1 unidad en el archivo 3D = 1 metro en el mundo real.**
- Ejemplo para platos de restaurante:
  - Pizza familiar: **0.35 a 0.40** unidades (35 a 40 cm de diámetro).
  - Hamburguesa: **0.12 a 0.15** unidades (12 a 15 cm de alto/ancho).
  - Vaso o bebida: **0.08 x 0.16** unidades (8 cm diámetro, 16 cm alto).
- *Si el modelo se exporta a escala 100, aparecerá como una pizza gigante de 100 metros en la sala del cliente.*

### B. Punto de Pivote (Origen [0, 0, 0])
- La base del plato o comida debe estar situada exactamente en **Y = 0** (el suelo virtual).
- Si el pivote está en el centro geométrico, el plato aparecerá medio enterrado debajo de la superficie de la mesa.

### C. Optimización de Peso y Polígonos
- **Formato:** `.glb` (GL Transmission Format binario autocontenido con texturas y geometría).
- **Polígonos:** Entre 10,000 y 45,000 triángulos por plato.
- **Texturas:** PBR (Base Color, Normal, Roughness) en resolución máxima de **1024x1024** o **2048x2048**.
- **Peso del archivo:** Idealmente **menos de 5 MB** (máximo absoluto 12 MB) para descarga rápida en redes móviles 4G.

---

## 5. Configuración del Servidor y Backend (Laravel / Nginx / API)

El servidor que entrega los modelos 3D debe cumplir con los siguientes encabezados HTTP:

### A. Cabeceras CORS (Indispensables)
Google Scene Viewer y WebXR realizan peticiones `fetch()` cruzadas:
```http
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, HEAD, OPTIONS
Access-Control-Allow-Headers: Range, Content-Type, Authorization
```

### B. Tipo MIME Correcto
- Para `.glb`: `Content-Type: model/gltf-binary`
- Para `.gltf`: `Content-Type: model/gltf+json`
- Para `.usdz`: `Content-Type: model/vnd.usdz+zip`

### C. Soporte para Peticiones por Rangos (`Accept-Ranges`)
Permite streaming y reanudación de descarga de modelos grandes:
```http
Accept-Ranges: bytes
```

---

## 6. Implementación en Web (`<model-viewer>`)

En la carta web para navegadores móviles (Chrome / Safari):

```html
<!-- 1. Cargar el componente oficial de Google -->
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/4.0.0/model-viewer.min.js"></script>

<!-- 2. Definir el elemento con soporte AR -->
<model-viewer
    src="https://tu-dominio.com/api/carta/mi-restaurante/productos/1/modelo"
    ios-src="https://tu-dominio.com/api/carta/mi-restaurante/productos/1/modelo-usdz"
    alt="Pizza Artesanal en 3D"
    ar
    ar-modes="scene-viewer webxr quick-look"
    ar-scale="auto"
    ar-placement="floor"
    xr-environment
    camera-controls
    camera-orbit="0deg 65deg 105%"
    min-camera-orbit="auto 25deg auto"
    max-camera-orbit="auto 85deg auto"
    touch-action="pan-y"
    shadow-intensity="1.5"
    shadow-softness="0.8"
    exposure="1.0"
    loading="lazy">
    
    <!-- Botón personalizado para lanzar AR -->
    <button slot="ar-button" class="btn-ar-mesa">
        📱 Ver en mi mesa (AR)
    </button>
</model-viewer>
```

---

## 7. Implementación en Flutter (`model_viewer_plus`)

### A. Configuración de Android (`android/app/src/main/AndroidManifest.xml`)
Declarar en `<queries>` las aplicaciones externas que pueden manejar los Intents de AR:

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <!-- Permisos para cámara (si se usa WebXR) e Internet -->
    <uses-permission android:name="android.permission.INTERNET"/>
    <uses-permission android:name="android.permission.CAMERA"/>

    <!-- Queries para que Android 11+ permita lanzar Google Scene Viewer y ARCore -->
    <queries>
        <package android:name="com.google.android.googlequicksearchbox"/>
        <package android:name="com.google.ar.core"/>
        <intent>
            <action android:name="android.intent.action.VIEW"/>
            <data android:scheme="https" android:host="arvr.google.com"/>
        </intent>
    </queries>

    <application ...>
        ...
    </application>
</manifest>
```

### B. Widget en Flutter
Asegurarse de pasar siempre una URL pública y con esquema `https://` al parámetro `src`:

```dart
ModelViewer(
  src: 'https://tu-servidor-https.com/api/carta/.../modelo',
  alt: 'Plato en 3D',
  ar: true,
  arModes: const ['scene-viewer', 'webxr', 'quick-look'],
  arPlacement: ArPlacement.floor,
  arScale: ArScale.auto,
  xrEnvironment: true,
  cameraControls: true,
  cameraOrbit: '0deg 65deg 105%',
  minCameraOrbit: 'auto 25deg auto',
  maxCameraOrbit: 'auto 85deg auto',
  autoRotate: false,
  shadowIntensity: 1.5,
  shadowSoftness: 0.8,
)
```

---

## 8. Flujo de Prueba en Entorno Local (Paso a Paso)

Dado que Scene Viewer y WebXR exigen HTTPS en dispositivos reales:

1. **Levantar el backend local:**
   ```bash
   docker compose up -d
   ```
2. **Crear un túnel HTTPS temporal hacia el puerto 8080:**
   - Con Cloudflare Tunnel (gratuito, sin límite de tráfico):
     ```bash
     cloudflared tunnel --url http://localhost:8080
     ```
   - O con Ngrok:
     ```bash
     ngrok http 8080
     ```
   - Obtendrás una URL como: `https://rapidly-known-example.trycloudflare.com`.
3. **Probar en el Celular:**
   - **En el Navegador Móvil (Recomendado):** Entrar a `https://rapidly-known-example.trycloudflare.com/c/mi-restaurante`. Al pulsar "Ver en mi mesa", se abrirá Scene Viewer o WebXR con la cámara de inmediato.
   - **En la App Flutter:**
     ```bash
     flutter run -d <ID_DISPOSITIVO> --dart-define=API_URL=https://rapidly-known-example.trycloudflare.com
     ```

---

## 9. Checklist de Diagnóstico Rápido

- [ ] ¿El modelo 3D carga en el visor interactivo 3D en pantalla? (Si no carga, verificar CORS y MIME `model/gltf-binary`).
- [ ] ¿El enlace del modelo usa protocolo `https://`? (Si usa `http://` o `127.0.0.1`, Scene Viewer se cierra inmediatamente).
- [ ] ¿El celular tiene instalados "Google Play Services para RA" y la app "Google"?
- [ ] ¿El modelo está a escala métrica real (centímetros convertidos a metros, eje Y=0 en el suelo)?
- [ ] ¿El AndroidManifest incluye las `<queries>` para `com.google.android.googlequicksearchbox`?
