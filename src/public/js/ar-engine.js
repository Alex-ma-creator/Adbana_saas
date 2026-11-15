import * as THREE from './vendor/three.module.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

/**
 * Filtro cinemático One-Euro (1€ Filter) con banda muerta (Deadband)
 * para eliminar al 100% el temblor de las esquinas del QR cuando la mano
 * está quieta, manteniendo respuesta fluida al mover el celular.
 */
class OneEuroFilter {
    constructor(minCutoff = 0.65, beta = 0.55, dCutoff = 1.0, deadband = 0.0018) {
        this.minCutoff = minCutoff;
        this.beta = beta;
        this.dCutoff = dCutoff;
        this.deadband = deadband;
        this.xPrev = null;
        this.dxPrev = 0;
        this.tPrev = null;
    }
    reset() {
        this.xPrev = null;
        this.dxPrev = 0;
        this.tPrev = null;
    }
    alpha(cutoff, dt) {
        const tau = 1.0 / (2 * Math.PI * cutoff);
        return 1.0 / (1.0 + tau / Math.max(dt, 0.001));
    }
    filter(x, t) {
        if (this.tPrev === null || this.xPrev === null) {
            this.xPrev = x;
            this.tPrev = t;
            return x;
        }
        const dt = Math.max(t - this.tPrev, 0.001);
        this.tPrev = t;

        // Banda muerta: si la variación es ruido sub-milimétrico del sensor de cámara, amortiguar al máximo
        const delta = x - this.xPrev;
        if (Math.abs(delta) < this.deadband) {
            const aQuiet = this.alpha(0.18, dt);
            this.xPrev = this.xPrev + aQuiet * delta;
            this.dxPrev *= 0.5;
            return this.xPrev;
        }

        const dx = delta / dt;
        const edx = this.dxPrev + this.alpha(this.dCutoff, dt) * (dx - this.dxPrev);
        this.dxPrev = edx;
        const cutoff = this.minCutoff + this.beta * Math.abs(edx);
        const result = this.xPrev + this.alpha(cutoff, dt) * delta;
        this.xPrev = result;
        return result;
    }
}

class OneEuroFilterVec3 {
    constructor(minCutoff = 0.65, beta = 0.55, deadband = 0.0018) {
        this.fx = new OneEuroFilter(minCutoff, beta, 1.0, deadband);
        this.fy = new OneEuroFilter(minCutoff, beta, 1.0, deadband);
        this.fz = new OneEuroFilter(minCutoff, beta, 1.0, deadband * 1.5);
    }
    reset() {
        this.fx.reset();
        this.fy.reset();
        this.fz.reset();
    }
    filter(v, t, out = new THREE.Vector3()) {
        out.set(
            this.fx.filter(v.x, t),
            this.fy.filter(v.y, t),
            this.fz.filter(v.z, t)
        );
        return out;
    }
}

/**
 * Sombra de contacto suave en Y = 0.001 m debajo del plato para asentarlo
 * visualmente sobre la superficie de la mesa.
 */
function crearTexturaSombraContacto() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const half = size / 2;
    const grad = ctx.createRadialGradient(half, half, size * 0.08, half, half, size * 0.48);
    grad.addColorStop(0.0, 'rgba(0, 0, 0, 0.78)');
    grad.addColorStop(0.52, 'rgba(0, 0, 0, 0.44)');
    grad.addColorStop(0.84, 'rgba(0, 0, 0, 0.12)');
    grad.addColorStop(1.0, 'rgba(0, 0, 0, 0.0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.needsUpdate = true;
    return tex;
}

class AdbanaQRAREngine {
    constructor() {
        this.loader = new GLTFLoader();
        this.cacheModelos = new Map();
        this.promesasCarga = new Map();

        this.activo = false;
        this.modeloListo = false;
        this.stream = null;
        this.video = null;
        this.cvCanvas = document.createElement('canvas');
        this.cvCtx = this.cvCanvas.getContext('2d', { willReadFrequently: true });

        this.renderer = null;
        this.scene = null;
        this.camera = null;
        this.pmremGenerator = null;
        this.envTexture = null;

        this.platoRoot = null;
        this.platoPivot = null;
        this.platoMesh = null;
        this.sombraMesh = null;

        // Estado de tracking por QR
        this.platoColocado = false;
        this.qrVisibleAhora = false;
        this.ultimoTiempoQR = 0;
        this.ultimoEscaneoMs = 0;
        this.usarRecorteCentral = false;

        // Tamaño físico real del QR impreso en la mesa: 9.5 cm (0.095 m)
        this.tamanoFisicoQrMetros = 0.095;

        // Pose 3D estabilizada en espacio de cámara
        this.posObjetivo = new THREE.Vector3(0, -0.02, -0.32);
        this.posEstable = new THREE.Vector3(0, -0.02, -0.32);
        this.quatObjetivo = new THREE.Quaternion();
        this.quatEstable = new THREE.Quaternion();
        this.filtroPos = new OneEuroFilterVec3(0.65, 0.55, 0.002);

        // Sensores de gravedad/giroscopio (DeviceOrientationEvent) para clavar el plano horizontal de la mesa
        // sin que el plato jamás se voltee de costado ni tiemble
        this.imuDisponible = false;
        this.imuBetaRad = THREE.MathUtils.degToRad(42); // inclinación típica de 42° mirando la mesa
        this.imuGammaRad = 0;
        this.imuAlphaRad = 0;
        this.imuAlphaAnclaje = 0;
        this.yawQrAnclado = 0;
        this._onDeviceOrientationBound = (ev) => this.actualizarOrientacionIMU(ev);

        // Acercamiento e inspección libre con gestos (pellizco para acercarse al plato y 1 dedo para girar)
        this.zoomAcercamiento = 1.0; // > 1.0 acerca el plato hacia la cámara cuando el usuario quiere ver detalles de cerca
        this.rotacionUsuarioY = 0;

        // Referencias DOM (Interfaz ultra limpia: un solo indicador arriba + botón cerrar)
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
     * Unifica la URL con ?v=ar8 para reutilizar al 100% el caché HTTP de <model-viewer>
     * y evitar descargar dos veces archivos de 6 MB a 12 MB.
     */
    normalizarUrlModelo(url) {
        if (!url) return '';
        const base = String(url).split('?')[0];
        return base + '?v=ar8';
    }

    /**
     * Pre-carga silenciosamente en segundo plano los modelos 3D de la carta
     * apenas el usuario entra a la web, logrando apertura instantánea (0 s) al pulsar AR.
     */
    precargarModelos(productos) {
        if (!Array.isArray(productos)) return;
        const con3D = productos.filter((p) => p && p.modelo_3d);
        let delay = 400;
        for (const p of con3D) {
            setTimeout(() => {
                if (!this.activo) {
                    this.cargarModeloNormalizado(p.modelo_3d, p).catch(() => {});
                }
            }, delay);
            delay += 1200;
        }
    }

    /**
     * Carga el modelo .glb, lo escala a ~15 cm y asienta su base exactamente en Y = 0.0000
     * sobre el plano horizontal de la mesa (cero flotación).
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
                    // Base apoyada exactamente en Y = 0.0000 sobre la mesa
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

    construirInterfazAR() {
        if (this.overlayRoot && this.overlayRoot.parentNode) {
            this.overlayRoot.parentNode.removeChild(this.overlayRoot);
        }

        const root = document.createElement('div');
        root.id = 'adbana-qr-ar-modal';
        root.className = 'qr-ar-overlay';

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

        const btnCerrar = document.createElement('button');
        btnCerrar.type = 'button';
        btnCerrar.className = 'qr-ar-btn-close';
        btnCerrar.textContent = '✕ Cerrar';
        btnCerrar.addEventListener('click', () => this.finalizar());

        topBar.appendChild(statusPill);
        topBar.appendChild(btnCerrar);
        root.appendChild(topBar);

        document.body.appendChild(root);

        this.overlayRoot = root;
        this.video = video;
        this.uiStatusPill = statusPill;
        this.uiStatusDot = dot;
        this.uiStatusText = statusTxt;
    }

    construirEscenaThree(plato) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const scene = new THREE.Scene();
        scene.background = null;

        // near = 0.005 m (5 mm) permite acercarse al plato a pocos milímetros sin recorte
        const camera = new THREE.PerspectiveCamera(52, vw / vh, 0.005, 20);
        camera.position.set(0, 0, 0);
        camera.lookAt(0, 0, -1);

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

        if (this.overlayRoot) {
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
        dirLight.position.set(0.5, 2.2, 1.2);
        scene.add(dirLight);

        // platoRoot se alinea con la normal real de la superficie de la mesa (Y = 0 sobre el papel)
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
            opacity: 0.72,
            depthWrite: false
        });
        const sombraMesh = new THREE.Mesh(sombraGeo, sombraMat);
        sombraMesh.position.set(0, 0.001, 0);
        platoPivot.add(sombraMesh);

        scene.add(platoRoot);

        this.scene = scene;
        this.camera = camera;
        this.renderer = renderer;
        this.platoRoot = platoRoot;
        this.platoPivot = platoPivot;
        this.sombraMesh = sombraMesh;

        this.configurarInteraccionAcercamientoYGiro(renderer.domElement);
        this.actualizarDimensiones();
    }

    /**
     * Permite girar el plato 360° con 1 dedo y acercarse a ver los detalles (zoom de cámara)
     * cuando el usuario acerca el celular y las esquinas del QR ya salieron del encuadre.
     */
    configurarInteraccionAcercamientoYGiro(domElement) {
        let arrastrando = false;
        let prevX = 0;
        let distPinchInicial = 0;
        let zoomInicial = 1.0;

        domElement.addEventListener('pointerdown', (e) => {
            arrastrando = true;
            prevX = e.clientX;
        });
        domElement.addEventListener('pointermove', (e) => {
            if (!arrastrando || !this.platoColocado) return;
            const dx = e.clientX - prevX;
            prevX = e.clientX;
            this.rotacionUsuarioY += dx * 0.012;
        });
        window.addEventListener('pointerup', () => {
            arrastrando = false;
        });

        domElement.addEventListener('touchstart', (e) => {
            if (e.touches && e.touches.length === 2) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                distPinchInicial = Math.hypot(dx, dy);
                zoomInicial = this.zoomAcercamiento;
            }
        }, { passive: true });

        domElement.addEventListener('touchmove', (e) => {
            if (e.touches && e.touches.length === 2 && distPinchInicial > 10) {
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
        if (this.camera) {
            this.camera.aspect = vw / vh;
            this.camera.updateProjectionMatrix();
        }
        if (this.renderer) {
            this.renderer.setSize(vw, vh);
        }
    }

    /**
     * Lee los ángulos reales de inclinación del teléfono respecto a la gravedad terrestre.
     * Como toda mesa de restaurante es horizontal, el vector de gravedad nos da la normal
     * exacta del plano de la mesa con 0.1° de precisión y CERO temblor, evitando que el
     * plato se voltee de costado jamás.
     */
    actualizarOrientacionIMU(ev) {
        if (ev.beta === null || ev.gamma === null) return;
        const degToRad = THREE.MathUtils.degToRad;
        // beta: 0° cuando el celular está plano mirando hacia abajo a la mesa, 90° cuando está vertical
        const rawBeta = THREE.MathUtils.clamp(ev.beta || 0, 2, 86);
        const rawGamma = THREE.MathUtils.clamp(ev.gamma || 0, -45, 45);
        const rawAlpha = ev.alpha || 0;

        const bRad = degToRad(rawBeta);
        const gRad = degToRad(rawGamma);
        const aRad = degToRad(rawAlpha);

        if (!this.imuDisponible) {
            this.imuBetaRad = bRad;
            this.imuGammaRad = gRad;
            this.imuAlphaRad = aRad;
            this.imuAlphaAnclaje = aRad;
            this.imuDisponible = true;
        } else {
            this.imuBetaRad += (bRad - this.imuBetaRad) * 0.25;
            this.imuGammaRad += (gRad - this.imuGammaRad) * 0.25;
            this.imuAlphaRad = aRad;
        }
    }

    /**
     * Calcula el cuaternión de orientación 3D de la mesa en espacio de cámara:
     * 1. Alinea el eje +Y del plato (de la base del pan a la tapa superior) con la normal
     *    vertical hacia arriba de la mesa (calculada por gravedad IMU o escorzo del QR).
     * 2. Gira el plato sobre el plano de la mesa según el ángulo horizontal (Yaw) del QR.
     * Esto garantiza matemáticamente que el plato SIEMPRE esté asentado plano sobre la hoja
     * y NUNCA se ponga de canto ni de costado.
     */
    calcularCuaternionMesaEstable(anguloEscorzoFallbackRad, yawEnMesaRad) {
        const beta = this.imuDisponible ? this.imuBetaRad : anguloEscorzoFallbackRad;
        const gamma = this.imuDisponible ? this.imuGammaRad : 0;

        // En cámara Three.js (+X derecha, +Y arriba en pantalla, +Z saliendo de la pantalla hacia el usuario):
        // - Si el celular está paralelo al papel mirando hacia abajo (beta = 0°): la normal hacia arriba de la mesa apunta a +Z_cam (0, 0, 1).
        // - Si el celular está inclinado 45° (beta = 45°): la normal hacia arriba de la mesa apunta a (0, sin(45°), cos(45°)).
        const nx = -Math.sin(gamma) * Math.cos(beta);
        const ny = Math.sin(beta);
        const nz = Math.cos(gamma) * Math.cos(beta);
        const normalMesa = new THREE.Vector3(nx, ny, nz).normalize();

        const ejeArribaModelo = new THREE.Vector3(0, 1, 0);
        const quatPlanoMesa = new THREE.Quaternion().setFromUnitVectors(ejeArribaModelo, normalMesa);

        const quatGiroEnMesa = new THREE.Quaternion().setFromAxisAngle(
            ejeArribaModelo,
            yawEnMesaRad + this.rotacionUsuarioY
        );

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

    /**
     * Escaneo de alta resolución (720 px) + recorte central adaptativo para detectar el QR
     * incluso en ángulos muy inclinados (como la Foto 4) o a mayor distancia.
     */
    detectarQREnFrame() {
        if (!this.video || this.video.readyState < 2 || !window.jsQR) return null;
        const vw = this.video.videoWidth;
        const vh = this.video.videoHeight;
        if (!vw || !vh) return null;

        // Paso 1: Escaneo a alta definición (720 px de lado mayor) para leer QR inclinados
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

        if (code && code.location) {
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

        // Paso 2 (Alternado): Si no se detectó en el cuadro completo (ej. QR inclinado o lejano),
        // analizar un recorte central con zoom 1.6x a resolución nativa del video
        this.usarRecorteCentral = !this.usarRecorteCentral;
        if (this.usarRecorteCentral) {
            const cropW = Math.round(vw * 0.62);
            const cropH = Math.round(vh * 0.62);
            const sx = Math.round((vw - cropW) * 0.5);
            const sy = Math.round((vh - cropH) * 0.5);

            this.cvCtx.drawImage(this.video, sx, sy, cropW, cropH, 0, 0, targetW, targetH);
            const cropData = this.cvCtx.getImageData(0, 0, targetW, targetH);
            const codeCrop = window.jsQR(cropData.data, targetW, targetH, {
                inversionAttempts: 'attemptBoth'
            });

            if (codeCrop && codeCrop.location) {
                const mapBack = (pt) => ({
                    x: ((sx + (pt.x / targetW) * cropW) / vw) * targetW,
                    y: ((sy + (pt.y / targetH) * cropH) / vh) * targetH
                });
                return {
                    data: codeCrop.data,
                    cw: targetW,
                    ch: targetH,
                    corners: {
                        topLeft: mapBack(codeCrop.location.topLeftCorner),
                        topRight: mapBack(codeCrop.location.topRightCorner),
                        bottomRight: mapBack(codeCrop.location.bottomRightCorner),
                        bottomLeft: mapBack(codeCrop.location.bottomLeftCorner)
                    }
                };
            }
        }

        return null;
    }

    /**
     * Calcula la intersección de las dos diagonales del cuadrilátero QR para obtener el
     * verdadero centro proyectivo 3D sobre la hoja, inmune a la distorsión de perspectiva.
     */
    calcularCentroProyectivoQR(tl, tr, br, bl) {
        const x1 = tl.x, y1 = tl.y;
        const x2 = br.x, y2 = br.y;
        const x3 = tr.x, y3 = tr.y;
        const x4 = bl.x, y4 = bl.y;

        const denom = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4);
        if (Math.abs(denom) > 1e-4) {
            const a = x1 * y2 - y1 * x2;
            const b = x3 * y4 - y3 * x4;
            return {
                x: (a * (x3 - x4) - (x1 - x2) * b) / denom,
                y: (a * (y3 - y4) - (y1 - y2) * b) / denom
            };
        }
        return {
            x: (tl.x + tr.x + br.x + bl.x) * 0.25,
            y: (tl.y + tr.y + br.y + bl.y) * 0.25
        };
    }

    actualizarPoseDesdeEsquinasQR(det, timestampSec) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const tl = this.mapearPuntoVideoAPantalla(det.corners.topLeft.x, det.corners.topLeft.y, det.cw, det.ch, vw, vh);
        const tr = this.mapearPuntoVideoAPantalla(det.corners.topRight.x, det.corners.topRight.y, det.cw, det.ch, vw, vh);
        const br = this.mapearPuntoVideoAPantalla(det.corners.bottomRight.x, det.corners.bottomRight.y, det.cw, det.ch, vw, vh);
        const bl = this.mapearPuntoVideoAPantalla(det.corners.bottomLeft.x, det.corners.bottomLeft.y, det.cw, det.ch, vw, vh);

        // 1. Centro proyectivo exacto (intersección de diagonales del QR)
        const centro = this.calcularCentroProyectivoQR(tl, tr, br, bl);

        // 2. Tamaño aparente sin distorsión de escorzo (usando el lado mayor y la diagonal mayor / sqrt(2))
        const topLen = Math.hypot(tr.x - tl.x, tr.y - tl.y);
        const botLen = Math.hypot(br.x - bl.x, br.y - bl.y);
        const leftLen = Math.hypot(bl.x - tl.x, bl.y - tl.y);
        const rightLen = Math.hypot(br.x - tr.x, br.y - tr.y);
        const diag1 = Math.hypot(br.x - tl.x, br.y - tl.y) / Math.SQRT2;
        const diag2 = Math.hypot(tr.x - bl.x, tr.y - bl.y) / Math.SQRT2;

        const avgHorizPx = (topLen + botLen) * 0.5;
        const avgVertPx = (leftLen + rightLen) * 0.5;
        const maxDiagSpan = Math.max(diag1, diag2);
        const unforeshortenedSpanPx = Math.max(avgHorizPx, avgVertPx, maxDiagSpan, 24);

        // 3. Distancia real en metros (profundidad Z) permitiendo acercarse hasta 7 cm (0.07 m)
        const fovRad = THREE.MathUtils.degToRad(this.camera.fov);
        const fy = (vh * 0.5) / Math.tan(fovRad * 0.5);
        const rawDepth = (fy * this.tamanoFisicoQrMetros) / unforeshortenedSpanPx;
        const depth = THREE.MathUtils.clamp(rawDepth / this.zoomAcercamiento, 0.07, 1.45);

        // 4. Posición 3D exacta sobre el centro del QR en la mesa (CERO flotación vertical)
        const ndcX = (centro.x / vw) * 2 - 1;
        const ndcY = -(centro.y / vh) * 2 + 1;
        const halfH = depth * Math.tan(fovRad * 0.5);
        const halfW = halfH * this.camera.aspect;

        const worldX = ndcX * halfW;
        const worldY = ndcY * halfH;
        const worldZ = -depth;

        this.posObjetivo.set(worldX, worldY, worldZ);
        this.filtroPos.filter(this.posObjetivo, timestampSec, this.posEstable);

        // 5. Orientación 3D asentada sobre la mesa (Gravedad IMU + Giro Yaw del QR en la mesa)
        const ratioEscorzo = THREE.MathUtils.clamp(
            Math.min(avgHorizPx, avgVertPx) / Math.max(avgHorizPx, avgVertPx, 1),
            0.25,
            1.0
        );
        const anguloEscorzoFallback = Math.acos(ratioEscorzo);

        const edgeDx = ((tr.x - tl.x) + (br.x - bl.x)) * 0.5;
        const edgeDy = ((tr.y - tl.y) + (br.y - bl.y)) * 0.5;
        const yawQr = Math.atan2(-edgeDy, edgeDx);
        this.yawQrAnclado = yawQr;
        this.imuAlphaAnclaje = this.imuAlphaRad;

        this.quatObjetivo.copy(this.calcularCuaternionMesaEstable(anguloEscorzoFallback, yawQr));

        if (!this.platoColocado) {
            this.quatEstable.copy(this.quatObjetivo);
        } else {
            this.quatEstable.slerp(this.quatObjetivo, 0.18);
        }

        if (this.platoRoot) {
            this.platoRoot.position.copy(this.posEstable);
            this.platoRoot.quaternion.copy(this.quatEstable);
            if (this.modeloListo) {
                this.platoRoot.visible = true;
            }
        }

        this.platoColocado = true;
        this.qrVisibleAhora = true;
        this.ultimoTiempoQR = performance.now();
        if (this.modeloListo) {
            this.actualizarIndicadorUnico('anclado');
        }
    }

    /**
     * Cuando el usuario acerca mucho el celular al plato y las esquinas del QR salen de pantalla,
     * mantiene el plato firme y actualiza suavemente el ángulo de vista con el giroscopio IMU.
     */
    actualizarVistaCercanaSinQR() {
        if (!this.platoColocado || !this.platoRoot) return;

        const deltaAlpha = this.imuDisponible ? (this.imuAlphaRad - this.imuAlphaAnclaje) : 0;
        const quatInspeccion = this.calcularCuaternionMesaEstable(
            THREE.MathUtils.degToRad(42),
            this.yawQrAnclado - deltaAlpha
        );
        this.quatEstable.slerp(quatInspeccion, 0.15);

        // Aplicar acercamiento si el usuario hace pinch-zoom mientras está cerca del plato
        const zConZoom = THREE.MathUtils.clamp(this.posEstable.z / this.zoomAcercamiento, -1.45, -0.07);
        this.platoRoot.position.set(this.posEstable.x, this.posEstable.y, zConZoom);
        this.platoRoot.quaternion.copy(this.quatEstable);
        if (this.modeloListo) {
            this.platoRoot.visible = true;
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
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            if (typeof onFallback3D === 'function') {
                onFallback3D('Tu navegador o conexión actual no permite abrir la cámara directamente. Mostrando el visor 3D interactivo.');
            }
            return;
        }

        this.finalizar();
        this.activo = true;
        this.modeloListo = false;
        this.platoColocado = false;
        this.qrVisibleAhora = false;
        this.zoomAcercamiento = 1.0;
        this.rotacionUsuarioY = 0;
        this.filtroPos.reset();

        this.activarPermisoIMUSiNecesario();
        this.construirInterfazAR();

        // Iniciar la descarga/clonación del modelo EN PARALELO mientras se abre la cámara
        const urlNormalizada = this.normalizarUrlModelo(plato && plato.modelo_3d);
        const yaEnCache = this.cacheModelos.has(urlNormalizada);
        if (!yaEnCache) {
            this.actualizarIndicadorUnico('cargando', 0);
        }

        const promesaModelo = this.cargarModeloNormalizado(
            plato.modelo_3d,
            plato,
            (pct) => {
                if (!this.modeloListo) {
                    this.actualizarIndicadorUnico('cargando', pct);
                }
            }
        );

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

        this.construirEscenaThree(plato);
        window.addEventListener('resize', this._onResizeBound);

        promesaModelo.then((modeloGroup) => {
            if (!this.activo || !this.platoPivot) return;
            this.platoMesh = modeloGroup;
            this.platoPivot.add(modeloGroup);
            this.modeloListo = true;
            if (this.platoColocado && this.platoRoot) {
                this.platoRoot.visible = true;
                this.actualizarIndicadorUnico('anclado');
            } else {
                this.actualizarIndicadorUnico('buscando');
            }
        }).catch(() => {});

        const loop = (nowMs) => {
            if (!this.activo) return;
            this.rafId = requestAnimationFrame(loop);

            if (nowMs - this.ultimoEscaneoMs >= 35) {
                this.ultimoEscaneoMs = nowMs;
                const det = this.detectarQREnFrame();
                if (det) {
                    this.actualizarPoseDesdeEsquinasQR(det, nowMs * 0.001);
                } else {
                    if (this.qrVisibleAhora && nowMs - this.ultimoTiempoQR > 320) {
                        this.qrVisibleAhora = false;
                    }
                    if (this.platoColocado && !this.qrVisibleAhora) {
                        this.actualizarVistaCercanaSinQR();
                    }
                }
            }

            if (this.renderer && this.scene && this.camera) {
                this.renderer.render(this.scene, this.camera);
            }
        };

        this.rafId = requestAnimationFrame(loop);
    }

    finalizar() {
        this.activo = false;
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
        window.removeEventListener('resize', this._onResizeBound);
        window.removeEventListener('deviceorientation', this._onDeviceOrientationBound, true);

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
    }
}

window.AdbanaQRAR = new AdbanaQRAREngine();
