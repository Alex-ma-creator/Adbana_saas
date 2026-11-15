import * as THREE from './vendor/three.module.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

/**
 * Sombra de contacto suave en Y = 0.001 m debajo del plato para asentarlo
 * visualmente sobre la superficie de la mesa (cero flotación).
 */
function crearTexturaSombraContacto() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const half = size / 2;
    const grad = ctx.createRadialGradient(half, half, size * 0.08, half, half, size * 0.48);
    grad.addColorStop(0.0, 'rgba(0, 0, 0, 0.80)');
    grad.addColorStop(0.52, 'rgba(0, 0, 0, 0.46)');
    grad.addColorStop(0.84, 'rgba(0, 0, 0, 0.14)');
    grad.addColorStop(1.0, 'rgba(0, 0, 0, 0.0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.needsUpdate = true;
    return tex;
}

/**
 * Anillo sutil en el piso/mesa mientras detecta el punto exacto de anclaje (< 0.5 s).
 */
function crearAnilloGuiaMesa() {
    const geo = new THREE.RingGeometry(0.055, 0.068, 48);
    geo.rotateX(-Math.PI / 2);
    const mat = new THREE.MeshBasicMaterial({
        color: 0x22c55e,
        side: THREE.DoubleSide,
        transparent: true,
        opacity: 0.85,
        depthWrite: false
    });
    return new THREE.Mesh(geo, mat);
}

class AdbanaQRAREngine {
    constructor() {
        this.loader = new GLTFLoader();
        this.cacheModelos = new Map();
        this.promesasCarga = new Map();

        this.activo = false;
        this.modoXR = false;
        this.modeloListo = false;

        // Estado de Anclaje Único en Espacio Real (One-Shot Spatial Lock)
        // Una vez anclado (ancladoDefinitivo = true), NO se vuelve a recalcular su posición,
        // quedando 100% inmóvil sobre la mesa para poder acercarse a 2 cm o rodearlo 360°.
        this.ancladoDefinitivo = false;
        this.framesEstablesHit = 0;
        this.tiempoInicioSesion = 0;
        this.posCandidata = new THREE.Vector3();

        // Recursos WebXR (Gama Media-Alta con ARCore / SLAM 6-DoF)
        this.xrSession = null;
        this.xrRefSpace = null;
        this.xrHitTestSource = null;
        this.reticulaMesh = null;

        // Recursos Fallback WebRTC + QR One-Shot Lock (para equipos sin WebXR)
        this.stream = null;
        this.video = null;
        this.cvCanvas = document.createElement('canvas');
        this.cvCtx = this.cvCanvas.getContext('2d', { willReadFrequently: true });
        this.ultimoEscaneoMs = 0;
        this.tamanoFisicoQrMetros = 0.095;

        // Escena Three.js
        this.renderer = null;
        this.scene = null;
        this.camera = null;
        this.pmremGenerator = null;
        this.envTexture = null;

        this.platoRoot = null;
        this.platoPivot = null;
        this.platoMesh = null;
        this.sombraMesh = null;
        this.rotacionUsuarioY = 0;

        // Sensores IMU para el fallback sin WebXR
        this.imuDisponible = false;
        this.imuBetaRad = THREE.MathUtils.degToRad(42);
        this.imuGammaRad = 0;
        this.imuAlphaRad = 0;
        this.imuAlphaAnclaje = 0;
        this.yawQrAnclado = 0;
        this.zoomAcercamiento = 1.0;
        this.posAncladaFallback = new THREE.Vector3(0, -0.02, -0.32);
        this._onDeviceOrientationBound = (ev) => this.actualizarOrientacionIMU(ev);

        // DOM (Interfaz ultra limpia: un solo indicador arriba + botón cerrar)
        this.overlayRoot = null;
        this.uiStatusPill = null;
        this.uiStatusDot = null;
        this.uiStatusText = null;
        this.rafId = null;
        this._onResizeBound = () => this.actualizarDimensiones();
    }

    /**
     * Tamaño físico predeterminado fijo (~15 cm a 17 cm).
     */
    obtenerDiametroObjetivoMetros(plato) {
        const nombre = ((plato && plato.nombre) ? String(plato.nombre) : '').toLowerCase();
        if (nombre.includes('pizza')) return 0.17; // 17 cm
        if (nombre.includes('hamburguesa') || nombre.includes('burger')) return 0.145; // 14.5 cm
        if (nombre.includes('brocheta') || nombre.includes('anticucho') || nombre.includes('parrilla')) return 0.165; // 16.5 cm
        return 0.155; // 15.5 cm
    }

    /**
     * Unifica la URL con ?v=ar8 para compartir al 100% el caché del navegador con <model-viewer>.
     */
    normalizarUrlModelo(url) {
        if (!url) return '';
        const base = String(url).split('?')[0];
        return base + '?v=ar8';
    }

    /**
     * Pre-carga en segundo plano los modelos 3D apenas se abre la carta digital.
     */
    precargarModelos(productos) {
        if (!Array.isArray(productos)) return;
        const con3D = productos.filter((p) => p && p.modelo_3d);
        let delay = 300;
        for (const p of con3D) {
            setTimeout(() => {
                if (!this.activo) {
                    this.cargarModeloNormalizado(p.modelo_3d, p).catch(() => {});
                }
            }, delay);
            delay += 1000;
        }
    }

    /**
     * Carga el modelo .glb, lo escala a ~15 cm y apoya su base exactamente en Y = 0.0000
     * sobre el plano de la mesa (cero flotación).
     */
    async cargarModeloNormalizado(url, plato, onProgress) {
        const cleanUrl = this.normalizarUrlModelo(url);
        if (this.cacheModelos.has(cleanUrl)) {
            return this.cacheModelos.get(cleanUrl).clone(true);
        }

        if (this.promesasCarga.has(cleanUrl)) {
            const cachedGroup = await this.promesasCarga.get(cleanUrl);
            return cachedGroup.clone(true);
        }

        const promesa = new Promise((resolve, reject) => {
            this.loader.load(
                cleanUrl,
                (gltf) => {
                    const model = gltf.scene;
                    model.updateMatrixWorld(true);

                    const box = new THREE.Box3();
                    model.traverse((child) => {
                        if (child.isMesh && child.geometry) {
                            child.geometry.computeBoundingBox();
                            const meshBox = child.geometry.boundingBox.clone();
                            meshBox.applyMatrix4(child.matrixWorld);
                            box.union(meshBox);
                        }
                    });
                    if (box.isEmpty()) {
                        box.setFromObject(model);
                    }

                    const size = new THREE.Vector3();
                    const center = new THREE.Vector3();
                    box.getSize(size);
                    box.getCenter(center);

                    const maxHoriz = Math.max(size.x, size.z, 0.001);
                    const targetMeters = this.obtenerDiametroObjetivoMetros(plato);
                    const scale = targetMeters / maxHoriz;

                    const wrapper = new THREE.Group();
                    model.scale.setScalar(scale);
                    model.position.set(
                        -center.x * scale,
                        -box.min.y * scale,
                        -center.z * scale
                    );
                    wrapper.add(model);
                    this.cacheModelos.set(cleanUrl, wrapper);
                    this.promesasCarga.delete(cleanUrl);
                    resolve(wrapper);
                },
                (xhr) => {
                    if (typeof onProgress === 'function' && xhr && xhr.total > 0) {
                        onProgress(Math.min(99, Math.round((xhr.loaded / xhr.total) * 100)));
                    }
                },
                (err) => {
                    this.promesasCarga.delete(cleanUrl);
                    reject(err);
                }
            );
        });

        this.promesasCarga.set(cleanUrl, promesa);
        const res = await promesa;
        return res.clone(true);
    }

    construirInterfazAR(conVideoElement = false) {
        if (this.overlayRoot && this.overlayRoot.parentNode) {
            this.overlayRoot.parentNode.removeChild(this.overlayRoot);
        }

        const root = document.createElement('div');
        root.id = 'adbana-qr-ar-modal';
        root.className = 'qr-ar-overlay' + (conVideoElement ? '' : ' is-webxr');

        if (conVideoElement) {
            const video = document.createElement('video');
            video.className = 'qr-ar-video';
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            video.setAttribute('muted', '');
            video.setAttribute('autoplay', '');
            video.playsInline = true;
            video.muted = true;
            video.autoplay = true;
            root.appendChild(video);
            this.video = video;
        }

        const topBar = document.createElement('div');
        topBar.className = 'qr-ar-topbar';

        const statusPill = document.createElement('div');
        statusPill.className = 'qr-ar-status-pill';
        const dot = document.createElement('span');
        dot.className = 'qr-ar-status-dot is-scanning';
        const statusTxt = document.createElement('span');
        statusTxt.textContent = 'Apunta al QR de la mesa';
        statusPill.appendChild(dot);
        statusPill.appendChild(statusTxt);

        // Si el usuario toca la píldora cuando ya está anclado, permite volver a anclarlo en otro punto
        statusPill.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.ancladoDefinitivo) {
                this.ancladoDefinitivo = false;
                this.framesEstablesHit = 0;
                this.tiempoInicioSesion = performance.now();
                this.actualizarIndicadorUnico('buscando');
            }
        });

        const btnCerrar = document.createElement('button');
        btnCerrar.type = 'button';
        btnCerrar.className = 'qr-ar-btn-close';
        btnCerrar.textContent = '✕ Cerrar';
        btnCerrar.addEventListener('click', (e) => {
            e.stopPropagation();
            this.finalizar();
        });

        topBar.appendChild(statusPill);
        topBar.appendChild(btnCerrar);
        root.appendChild(topBar);

        document.body.appendChild(root);

        this.overlayRoot = root;
        this.uiStatusPill = statusPill;
        this.uiStatusDot = dot;
        this.uiStatusText = statusTxt;
    }

    construirEscenaThree(plato, usarXR = false) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const scene = new THREE.Scene();
        scene.background = null;

        // near = 0.005 m (5 mm) evita que el plato desaparezca al acercar el celular a 1 cm
        const camera = new THREE.PerspectiveCamera(52, vw / vh, 0.005, 25);
        camera.position.set(0, 0, 0);

        const renderer = new THREE.WebGLRenderer({
            antialias: true,
            alpha: true,
            powerPreference: 'high-performance'
        });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(vw, vh);
        renderer.setClearColor(0x000000, 0);
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.12;
        renderer.domElement.className = 'qr-ar-webgl-canvas';

        if (usarXR) {
            renderer.xr.enabled = true;
            renderer.xr.setReferenceSpaceType('local');
            // En WebXR, el compositor XR dibuja en el baseLayer nativo; no meter el canvas dentro del domOverlay
            renderer.domElement.style.position = 'fixed';
            renderer.domElement.style.inset = '0';
            renderer.domElement.style.zIndex = '9998';
            renderer.domElement.style.pointerEvents = 'none';
            document.body.appendChild(renderer.domElement);
        } else if (this.overlayRoot) {
            this.overlayRoot.insertBefore(renderer.domElement, this.overlayRoot.querySelector('.qr-ar-topbar'));
        }

        try {
            this.pmremGenerator = new THREE.PMREMGenerator(renderer);
            this.envTexture = this.pmremGenerator.fromScene(new RoomEnvironment(), 0.04).texture;
            scene.environment = this.envTexture;
        } catch (e) {}

        const hemiLight = new THREE.HemisphereLight(0xfffbeb, 0x292524, 1.5);
        scene.add(hemiLight);

        const dirLight = new THREE.DirectionalLight(0xffffff, 1.65);
        dirLight.position.set(0.5, 2.2, 1.0);
        scene.add(dirLight);

        const platoRoot = new THREE.Group();
        platoRoot.visible = false;

        const platoPivot = new THREE.Group();
        platoPivot.position.set(0, 0, 0);
        platoRoot.add(platoPivot);

        // Sombra de contacto suave en Y = 0.001 m debajo del plato
        const diametro = this.obtenerDiametroObjetivoMetros(plato);
        const sombraGeo = new THREE.PlaneGeometry(diametro * 1.24, diametro * 1.24);
        sombraGeo.rotateX(-Math.PI / 2);
        const sombraMat = new THREE.MeshBasicMaterial({
            map: crearTexturaSombraContacto(),
            transparent: true,
            opacity: 0.74,
            depthWrite: false
        });
        const sombraMesh = new THREE.Mesh(sombraGeo, sombraMat);
        sombraMesh.position.set(0, 0.001, 0);
        platoPivot.add(sombraMesh);

        scene.add(platoRoot);

        // Anillo guía en la mesa mientras busca el punto de anclaje en los primeros milisegundos
        const reticula = crearAnilloGuiaMesa();
        reticula.visible = false;
        scene.add(reticula);

        this.scene = scene;
        this.camera = camera;
        this.renderer = renderer;
        this.platoRoot = platoRoot;
        this.platoPivot = platoPivot;
        this.sombraMesh = sombraMesh;
        this.reticulaMesh = reticula;

        this.configurarGestosGiro(this.overlayRoot);
        this.actualizarDimensiones();
    }

    configurarGestosGiro(targetEl) {
        if (!targetEl) return;
        let arrastrando = false;
        let prevX = 0;
        let distPinchInicial = 0;
        let zoomInicial = 1.0;

        targetEl.addEventListener('pointerdown', (e) => {
            if (e.target && (e.target.closest('.qr-ar-btn-close') || e.target.closest('.qr-ar-status-pill'))) return;
            arrastrando = true;
            prevX = e.clientX;
        });
        targetEl.addEventListener('pointermove', (e) => {
            if (!arrastrando || !this.ancladoDefinitivo) return;
            const dx = e.clientX - prevX;
            prevX = e.clientX;
            this.rotacionUsuarioY += dx * 0.012;
            if (this.platoPivot) {
                this.platoPivot.rotation.y = this.rotacionUsuarioY;
            }
        });
        window.addEventListener('pointerup', () => {
            arrastrando = false;
        });

        targetEl.addEventListener('touchstart', (e) => {
            if (e.touches && e.touches.length === 2) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                distPinchInicial = Math.hypot(dx, dy);
                zoomInicial = this.zoomAcercamiento;
            }
        }, { passive: true });

        targetEl.addEventListener('touchmove', (e) => {
            if (!this.modoXR && e.touches && e.touches.length === 2 && distPinchInicial > 10) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                const distActual = Math.hypot(dx, dy);
                this.zoomAcercamiento = THREE.MathUtils.clamp(zoomInicial * (distActual / distPinchInicial), 0.75, 2.6);
            }
        }, { passive: true });
    }

    actualizarDimensiones() {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;
        if (this.camera && !this.modoXR) {
            this.camera.aspect = vw / vh;
            this.camera.updateProjectionMatrix();
        }
        if (this.renderer && !this.modoXR) {
            this.renderer.setSize(vw, vh);
        }
    }

    actualizarIndicadorUnico(estado, porcentaje = 0) {
        if (!this.uiStatusDot || !this.uiStatusText) return;
        if (estado === 'cargando') {
            this.uiStatusDot.className = 'qr-ar-status-dot is-scanning';
            this.uiStatusText.textContent = porcentaje > 0 ? `Cargando plato 3D (${porcentaje}%)…` : 'Cargando plato 3D…';
        } else if (estado === 'anclado') {
            this.uiStatusDot.className = 'qr-ar-status-dot is-tracking';
            this.uiStatusText.textContent = 'Plato anclado';
        } else {
            this.uiStatusDot.className = 'qr-ar-status-dot is-scanning';
            this.uiStatusText.textContent = 'Apunta al QR de la mesa';
        }
    }

    /**
     * ============================================================================
     * MODO 1 (PRINCIPAL PARA GAMA MEDIA-ALTA): WebXR Nativo 6-DoF (ARCore SLAM)
     * ============================================================================
     * Ancla el plato UNA SOLA VEZ en coordenadas reales del espacio físico (X, Y, Z).
     * Una vez anclado, queda 100% inmóvil sobre la mesa: puedes acercar el celular
     * a 2 centímetros del plato o rodearlo 360° por todos los ángulos sin que se mueva.
     */
    async intentarIniciarWebXR(plato, promesaModelo) {
        if (!navigator.xr || typeof navigator.xr.isSessionSupported !== 'function') {
            return false;
        }

        let soportado = false;
        try {
            soportado = await navigator.xr.isSessionSupported('immersive-ar');
        } catch (e) {
            return false;
        }
        if (!soportado) return false;

        this.construirInterfazAR(false);
        this.construirEscenaThree(plato, true);

        const urlNormalizada = this.normalizarUrlModelo(plato && plato.modelo_3d);
        if (!this.cacheModelos.has(urlNormalizada)) {
            this.actualizarIndicadorUnico('cargando', 0);
        }

        let session = null;
        try {
            // PROHIBIDO pedir depth-sensing (en Xiaomi/Poco HyperOS deja la cámara en negro)
            session = await navigator.xr.requestSession('immersive-ar', {
                requiredFeatures: ['local'],
                optionalFeatures: ['hit-test', 'dom-overlay'],
                domOverlay: { root: this.overlayRoot }
            });
        } catch (errSession) {
            if (this.overlayRoot && this.overlayRoot.parentNode) {
                this.overlayRoot.parentNode.removeChild(this.overlayRoot);
            }
            this.overlayRoot = null;
            return false;
        }

        this.modoXR = true;
        this.xrSession = session;
        this.ancladoDefinitivo = false;
        this.framesEstablesHit = 0;
        this.tiempoInicioSesion = performance.now();

        session.addEventListener('end', () => {
            if (this.activo) {
                this.finalizar();
            }
        });

        try {
            if (typeof session.updateRenderState === 'function') {
                session.updateRenderState({ depthNear: 0.005, depthFar: 20.0 });
            }
        } catch (e) {}

        await this.renderer.xr.setSession(session);

        try {
            this.xrRefSpace = await session.requestReferenceSpace('local');
            const viewerSpace = await session.requestReferenceSpace('viewer');
            if (typeof session.requestHitTestSource === 'function') {
                this.xrHitTestSource = await session.requestHitTestSource({ space: viewerSpace });
            }
        } catch (e) {}

        promesaModelo.then((modeloGroup) => {
            if (!this.activo || !this.platoPivot) return;
            this.platoMesh = modeloGroup;
            this.platoPivot.add(modeloGroup);
            this.modeloListo = true;
            if (this.ancladoDefinitivo && this.platoRoot) {
                this.platoRoot.visible = true;
                this.actualizarIndicadorUnico('anclado');
            } else {
                this.actualizarIndicadorUnico('buscando');
            }
        }).catch(() => {});

        const tempMatrix = new THREE.Matrix4();
        const tempPos = new THREE.Vector3();
        const tempQuat = new THREE.Quaternion();
        const tempScale = new THREE.Vector3();
        const camWorldPos = new THREE.Vector3();
        const camForward = new THREE.Vector3();

        this.renderer.setAnimationLoop((timestamp, frame) => {
            if (!this.activo || !frame) return;

            // Parchear near = 0.005 m (5 mm) en la cámara XR para que al acercarse nunca desaparezca el plato
            const xrCam = this.renderer.xr.getCamera(this.camera);
            if (xrCam) {
                xrCam.near = 0.005;
                if (typeof xrCam.updateProjectionMatrix === 'function') xrCam.updateProjectionMatrix();
                if (Array.isArray(xrCam.cameras)) {
                    for (const subCam of xrCam.cameras) {
                        subCam.near = 0.005;
                        if (typeof subCam.updateProjectionMatrix === 'function') subCam.updateProjectionMatrix();
                    }
                }
            }

            const refSpace = this.xrRefSpace || this.renderer.xr.getReferenceSpace();
            if (!refSpace) return;

            // Si aún NO está anclado definitivamente, buscamos la superficie de la mesa/QR una sola vez
            if (!this.ancladoDefinitivo) {
                let hitExitoso = false;

                if (this.xrHitTestSource) {
                    try {
                        const hitResults = frame.getHitTestResults(this.xrHitTestSource);
                        if (hitResults && hitResults.length > 0) {
                            const hit = hitResults[0];
                            const hitPose = hit.getPose(refSpace);
                            if (hitPose) {
                                tempMatrix.fromArray(hitPose.transform.matrix);
                                tempMatrix.decompose(tempPos, tempQuat, tempScale);

                                // Verificar que sea una superficie horizontal (mesa/papel QR, normal.y > 0.70)
                                const normalY = hitPose.transform.matrix[5];
                                if (normalY > 0.70) {
                                    hitExitoso = true;
                                    if (this.reticulaMesh) {
                                        this.reticulaMesh.position.copy(tempPos);
                                        this.reticulaMesh.visible = true;
                                    }

                                    if (this.framesEstablesHit === 0) {
                                        this.posCandidata.copy(tempPos);
                                    } else {
                                        this.posCandidata.lerp(tempPos, 0.35);
                                    }
                                    this.framesEstablesHit++;

                                    // Apenas acumula 4 frames estables (~60 ms), CLAVAMOS el plato en el espacio 3D
                                    if (this.framesEstablesHit >= 4) {
                                        this.clavarPlatoEnEspacioXR(this.posCandidata, xrCam);
                                    }
                                }
                            }
                        }
                    } catch (e) {}
                }

                // Fallback instantáneo (1.1 s): si la mesa tiene mantel muy oscuro/vidrio o el usuario apunta directo al QR
                // sin que hit-test dispare aún, anclamos el plato en el plano horizontal de la mesa a 38 cm frente al lente.
                if (!hitExitoso && (performance.now() - this.tiempoInicioSesion > 1100) && this.modeloListo) {
                    const viewerPose = frame.getViewerPose(refSpace);
                    if (viewerPose && viewerPose.transform) {
                        tempMatrix.fromArray(viewerPose.transform.matrix);
                        tempMatrix.decompose(camWorldPos, tempQuat, tempScale);
                        camForward.set(0, 0, -1).applyQuaternion(tempQuat);

                        // Proyectar a 38 cm delante de la cámara sobre el plano horizontal de la mesa
                        const dist = 0.38;
                        const targetPos = new THREE.Vector3(
                            camWorldPos.x + camForward.x * dist,
                            camWorldPos.y + Math.min(camForward.y * dist, -0.14),
                            camWorldPos.z + camForward.z * dist
                        );
                        this.clavarPlatoEnEspacioXR(targetPos, xrCam);
                    }
                }
            }

            this.renderer.render(this.scene, this.camera);
        });

        return true;
    }

    /**
     * Clava el plato en las coordenadas 3D reales (posMundo) con orientación 100% horizontal
     * asentada sobre la mesa (rotation.x = 0, rotation.z = 0) y bloquea su posición para que
     * no se mueva ni un milímetro mientras el usuario lo rodea o se acerca.
     */
    clavarPlatoEnEspacioXR(posMundo, xrCam) {
        if (!this.platoRoot) return;

        this.platoRoot.position.copy(posMundo);

        // Orientar el frente del plato hacia el usuario manteniendo el plato 100% horizontal sobre la mesa
        let yawHaciaUsuario = 0;
        if (xrCam) {
            const camPos = new THREE.Vector3();
            xrCam.getWorldPosition(camPos);
            yawHaciaUsuario = Math.atan2(camPos.x - posMundo.x, camPos.z - posMundo.z);
        }
        this.platoRoot.rotation.set(0, yawHaciaUsuario, 0);

        if (this.reticulaMesh) {
            this.reticulaMesh.visible = false;
        }

        this.ancladoDefinitivo = true;
        if (this.modeloListo) {
            this.platoRoot.visible = true;
            this.actualizarIndicadorUnico('anclado');
        }
    }

    /**
     * ============================================================================
     * MODO 2 (FALLBACK UNIVERSAL): Cámara WebRTC + Anclaje Único QR (One-Shot Lock)
     * ============================================================================
     * Cuando el navegador no soporta WebXR (ej. iOS Safari o PC), detecta el QR
     * una sola vez, clava la posición del plato y APAGA el re-cálculo continuo de
     * esquinas para que nunca tiemble.
     */
    actualizarOrientacionIMU(ev) {
        if (ev.beta === null || ev.gamma === null) return;
        const degToRad = THREE.MathUtils.degToRad;
        const bRad = degToRad(THREE.MathUtils.clamp(ev.beta || 0, 2, 86));
        const gRad = degToRad(THREE.MathUtils.clamp(ev.gamma || 0, -45, 45));
        const aRad = degToRad(ev.alpha || 0);

        if (!this.imuDisponible) {
            this.imuBetaRad = bRad;
            this.imuGammaRad = gRad;
            this.imuAlphaRad = aRad;
            this.imuAlphaAnclaje = aRad;
            this.imuDisponible = true;
        } else {
            this.imuBetaRad += (bRad - this.imuBetaRad) * 0.22;
            this.imuGammaRad += (gRad - this.imuGammaRad) * 0.22;
            this.imuAlphaRad = aRad;
        }
    }

    calcularCuaternionMesaEstable(anguloEscorzoFallbackRad, yawEnMesaRad) {
        const beta = this.imuDisponible ? this.imuBetaRad : anguloEscorzoFallbackRad;
        const gamma = this.imuDisponible ? this.imuGammaRad : 0;

        const nx = -Math.sin(gamma) * Math.cos(beta);
        const ny = Math.sin(beta);
        const nz = Math.cos(gamma) * Math.cos(beta);
        const normalMesa = new THREE.Vector3(nx, ny, nz).normalize();

        const ejeArribaModelo = new THREE.Vector3(0, 1, 0);
        const quatPlanoMesa = new THREE.Quaternion().setFromUnitVectors(ejeArribaModelo, normalMesa);
        const quatGiroEnMesa = new THREE.Quaternion().setFromAxisAngle(ejeArribaModelo, yawEnMesaRad);

        return quatPlanoMesa.multiply(quatGiroEnMesa);
    }

    mapearPuntoVideoAPantalla(x, y, cw, ch, vw, vh) {
        const aspectVideo = cw / ch;
        const aspectView = vw / vh;
        const u = (x / cw) - 0.5;
        const v = (y / ch) - 0.5;
        let scaleX = 1.0;
        let scaleY = 1.0;
        if (aspectView > aspectVideo) {
            scaleY = aspectView / aspectVideo;
        } else {
            scaleX = aspectVideo / aspectView;
        }
        return {
            x: (u * scaleX + 0.5) * vw,
            y: (v * scaleY + 0.5) * vh
        };
    }

    detectarQREnFrame() {
        if (!this.video || this.video.readyState < 2 || !window.jsQR) return null;
        const vw = this.video.videoWidth;
        const vh = this.video.videoHeight;
        if (!vw || !vh) return null;

        const targetW = 720;
        const targetH = Math.max(405, Math.round((vh / vw) * targetW));
        if (this.cvCanvas.width !== targetW || this.cvCanvas.height !== targetH) {
            this.cvCanvas.width = targetW;
            this.cvCanvas.height = targetH;
        }

        this.cvCtx.drawImage(this.video, 0, 0, targetW, targetH);
        const imgData = this.cvCtx.getImageData(0, 0, targetW, targetH);
        const code = window.jsQR(imgData.data, targetW, targetH, {
            inversionAttempts: 'dontInvert'
        });

        if (!code || !code.location) return null;
        return {
            data: code.data,
            cw: targetW,
            ch: targetH,
            corners: {
                topLeft: code.location.topLeftCorner,
                topRight: code.location.topRightCorner,
                bottomRight: code.location.bottomRightCorner,
                bottomLeft: code.location.bottomLeftCorner
            }
        };
    }

    async activarPermisoIMUSiNecesario() {
        try {
            if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
                const res = await DeviceOrientationEvent.requestPermission();
                if (res === 'granted') {
                    window.addEventListener('deviceorientation', this._onDeviceOrientationBound, true);
                }
            } else if (typeof window !== 'undefined') {
                window.addEventListener('deviceorientation', this._onDeviceOrientationBound, true);
            }
        } catch (e) {}
    }

    async iniciar(plato, onFallback3D) {
        this.finalizar();
        this.activo = true;
        this.modoXR = false;
        this.modeloListo = false;
        this.ancladoDefinitivo = false;
        this.framesEstablesHit = 0;
        this.rotacionUsuarioY = 0;
        this.zoomAcercamiento = 1.0;

        // Cargar/clonar el modelo .glb inmediatamente en paralelo
        const promesaModelo = this.cargarModeloNormalizado(
            plato.modelo_3d,
            plato,
            (pct) => {
                if (!this.modeloListo) {
                    this.actualizarIndicadorUnico('cargando', pct);
                }
            }
        );

        // 1. Intentar primero WebXR nativo 6-DoF (ARCore SLAM en Android de gama media/alta)
        const xrIniciado = await this.intentarIniciarWebXR(plato, promesaModelo);
        if (xrIniciado) {
            return;
        }

        // 2. Fallback WebRTC + One-Shot QR Lock (para iOS Safari / navegadores sin WebXR)
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            this.finalizar();
            if (typeof onFallback3D === 'function') {
                onFallback3D('Tu navegador o conexión actual no permite abrir la cámara directamente. Mostrando el visor 3D interactivo.');
            }
            return;
        }

        this.activarPermisoIMUSiNecesario();
        this.construirInterfazAR(true);

        const urlNormalizada = this.normalizarUrlModelo(plato && plato.modelo_3d);
        if (!this.cacheModelos.has(urlNormalizada)) {
            this.actualizarIndicadorUnico('cargando', 0);
        }

        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            });
        } catch (errEnv) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: true
                });
            } catch (errAny) {
                this.finalizar();
                if (typeof onFallback3D === 'function') {
                    onFallback3D('No se pudo acceder a la cámara (permiso denegado o equipo sin cámara). Puedes explorar el plato en 3D interactivo girándolo y haciendo zoom.');
                }
                return;
            }
        }

        this.stream = stream;
        this.video.srcObject = stream;
        try {
            await this.video.play();
        } catch (playErr) {
            this.finalizar();
            if (typeof onFallback3D === 'function') {
                onFallback3D('No se pudo iniciar el video de la cámara. Mostrando el plato en 3D interactivo.');
            }
            return;
        }

        this.construirEscenaThree(plato, false);
        window.addEventListener('resize', this._onResizeBound);

        promesaModelo.then((modeloGroup) => {
            if (!this.activo || !this.platoPivot) return;
            this.platoMesh = modeloGroup;
            this.platoPivot.add(modeloGroup);
            this.modeloListo = true;
            if (this.ancladoDefinitivo && this.platoRoot) {
                this.platoRoot.visible = true;
                this.actualizarIndicadorUnico('anclado');
            } else {
                this.actualizarIndicadorUnico('buscando');
            }
        }).catch(() => {});

        const loopFallback = (nowMs) => {
            if (!this.activo || this.modoXR) return;
            this.rafId = requestAnimationFrame(loopFallback);

            // Anclaje de 1 solo disparo (One-Shot Lock): una vez detectado el QR durante 3 lecturas,
            // congela la posición y DEJA de leer el QR para que no vuelva a moverse ni temblar.
            if (!this.ancladoDefinitivo && nowMs - this.ultimoEscaneoMs >= 40) {
                this.ultimoEscaneoMs = nowMs;
                const det = this.detectarQREnFrame();
                if (det) {
                    const vw = window.innerWidth || 390;
                    const vh = window.innerHeight || 844;
                    const tl = this.mapearPuntoVideoAPantalla(det.corners.topLeft.x, det.corners.topLeft.y, det.cw, det.ch, vw, vh);
                    const tr = this.mapearPuntoVideoAPantalla(det.corners.topRight.x, det.corners.topRight.y, det.cw, det.ch, vw, vh);
                    const br = this.mapearPuntoVideoAPantalla(det.corners.bottomRight.x, det.corners.bottomRight.y, det.cw, det.ch, vw, vh);
                    const bl = this.mapearPuntoVideoAPantalla(det.corners.bottomLeft.x, det.corners.bottomLeft.y, det.cw, det.ch, vw, vh);

                    const cx = (tl.x + tr.x + br.x + bl.x) * 0.25;
                    const cy = (tl.y + tr.y + br.y + bl.y) * 0.25;
                    const spanPx = Math.max(
                        Math.hypot(tr.x - tl.x, tr.y - tl.y),
                        Math.hypot(br.x - bl.x, br.y - bl.y),
                        Math.hypot(bl.x - tl.x, bl.y - tl.y),
                        28
                    );

                    const fovRad = THREE.MathUtils.degToRad(this.camera.fov);
                    const fy = (vh * 0.5) / Math.tan(fovRad * 0.5);
                    const depth = THREE.MathUtils.clamp((fy * this.tamanoFisicoQrMetros) / spanPx, 0.14, 1.25);

                    const ndcX = (cx / vw) * 2 - 1;
                    const ndcY = -(cy / vh) * 2 + 1;
                    const halfH = depth * Math.tan(fovRad * 0.5);
                    const halfW = halfH * this.camera.aspect;

                    this.posAncladaFallback.set(ndcX * halfW, ndcY * halfH, -depth);

                    const edgeDx = ((tr.x - tl.x) + (br.x - bl.x)) * 0.5;
                    const edgeDy = ((tr.y - tl.y) + (br.y - bl.y)) * 0.5;
                    this.yawQrAnclado = Math.atan2(-edgeDy, edgeDx);
                    this.imuAlphaAnclaje = this.imuAlphaRad;

                    this.framesEstablesHit++;
                    if (this.framesEstablesHit >= 2) {
                        this.ancladoDefinitivo = true;
                        if (this.modeloListo && this.platoRoot) {
                            this.platoRoot.visible = true;
                            this.actualizarIndicadorUnico('anclado');
                        }
                    }
                }
            }

            if (this.ancladoDefinitivo && this.platoRoot) {
                const deltaAlpha = this.imuDisponible ? (this.imuAlphaRad - this.imuAlphaAnclaje) : 0;
                const quat = this.calcularCuaternionMesaEstable(
                    THREE.MathUtils.degToRad(42),
                    this.yawQrAnclado - deltaAlpha
                );
                const zZoom = THREE.MathUtils.clamp(this.posAncladaFallback.z / this.zoomAcercamiento, -1.4, -0.07);
                this.platoRoot.position.set(this.posAncladaFallback.x, this.posAncladaFallback.y, zZoom);
                this.platoRoot.quaternion.slerp(quat, 0.25);
                if (this.modeloListo) {
                    this.platoRoot.visible = true;
                }
            }

            if (this.renderer && this.scene && this.camera) {
                this.renderer.render(this.scene, this.camera);
            }
        };

        this.rafId = requestAnimationFrame(loopFallback);
    }

    finalizar() {
        this.activo = false;
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
        window.removeEventListener('resize', this._onResizeBound);
        window.removeEventListener('deviceorientation', this._onDeviceOrientationBound, true);

        if (this.xrHitTestSource) {
            try {
                this.xrHitTestSource.cancel();
            } catch (e) {}
            this.xrHitTestSource = null;
        }
        if (this.xrSession) {
            const s = this.xrSession;
            this.xrSession = null;
            try {
                s.end().catch(() => {});
            } catch (e) {}
        }
        if (this.stream) {
            try {
                for (const track of this.stream.getTracks()) {
                    track.stop();
                }
            } catch (e) {}
            this.stream = null;
        }
        if (this.video) {
            try {
                this.video.pause();
                this.video.srcObject = null;
            } catch (e) {}
            this.video = null;
        }
        if (this.renderer) {
            try {
                this.renderer.setAnimationLoop(null);
                if (this.renderer.domElement && this.renderer.domElement.parentNode) {
                    this.renderer.domElement.parentNode.removeChild(this.renderer.domElement);
                }
                this.renderer.dispose();
            } catch (e) {}
            this.renderer = null;
        }
        if (this.pmremGenerator) {
            try {
                this.pmremGenerator.dispose();
            } catch (e) {}
            this.pmremGenerator = null;
        }
        if (this.overlayRoot && this.overlayRoot.parentNode) {
            this.overlayRoot.parentNode.removeChild(this.overlayRoot);
        }
        this.overlayRoot = null;
        this.scene = null;
        this.camera = null;
        this.platoRoot = null;
        this.platoPivot = null;
        this.platoMesh = null;
        this.reticulaMesh = null;
        this.modoXR = false;
    }
}

window.AdbanaQRAR = new AdbanaQRAREngine();
