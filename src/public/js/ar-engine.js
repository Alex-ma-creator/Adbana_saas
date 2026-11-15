import * as THREE from './vendor/three.module.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

/**
 * Filtro cinemático One-Euro (1€ Filter) para suavizar la pose 6-DoF
 * eliminando el temblor de la mano sin introducir latencia al rodear el plato.
 */
class OneEuroFilter {
    constructor(minCutoff = 1.6, beta = 0.45, dCutoff = 1.0) {
        this.minCutoff = minCutoff;
        this.beta = beta;
        this.dCutoff = dCutoff;
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
        const dx = (x - this.xPrev) / dt;
        const edx = this.dxPrev + this.alpha(this.dCutoff, dt) * (dx - this.dxPrev);
        this.dxPrev = edx;
        const cutoff = this.minCutoff + this.beta * Math.abs(edx);
        const result = this.xPrev + this.alpha(cutoff, dt) * (x - this.xPrev);
        this.xPrev = result;
        return result;
    }
}

class OneEuroFilterVec3 {
    constructor(minCutoff = 1.6, beta = 0.45) {
        this.fx = new OneEuroFilter(minCutoff, beta);
        this.fy = new OneEuroFilter(minCutoff, beta);
        this.fz = new OneEuroFilter(minCutoff, beta);
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
 * Textura de sombra de contacto suave en Y = 0.001 para asentar visualmente
 * la base del plato sobre la superficie de la mesa.
 */
function crearTexturaSombraContacto() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const half = size / 2;
    const grad = ctx.createRadialGradient(half, half, size * 0.06, half, half, size * 0.48);
    grad.addColorStop(0.0, 'rgba(0, 0, 0, 0.78)');
    grad.addColorStop(0.50, 'rgba(0, 0, 0, 0.45)');
    grad.addColorStop(0.82, 'rgba(0, 0, 0, 0.14)');
    grad.addColorStop(1.0, 'rgba(0, 0, 0, 0.0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.needsUpdate = true;
    return tex;
}

/**
 * Resuelve un sistema lineal A * x = b de dimensión n x n mediante
 * eliminación gaussiana con pivoteo parcial.
 */
function resolverSistemaLineal(A, b) {
    const n = b.length;
    const M = new Array(n);
    for (let i = 0; i < n; i++) {
        M[i] = A[i].slice();
        M[i].push(b[i]);
    }

    for (let col = 0; col < n; col++) {
        let maxRow = col;
        let maxVal = Math.abs(M[col][col]);
        for (let row = col + 1; row < n; row++) {
            const val = Math.abs(M[row][col]);
            if (val > maxVal) {
                maxVal = val;
                maxRow = row;
            }
        }
        if (maxVal < 1e-11) return null;
        if (maxRow !== col) {
            const tmp = M[col];
            M[col] = M[maxRow];
            M[maxRow] = tmp;
        }

        const pivot = M[col][col];
        for (let j = col; j <= n; j++) {
            M[col][j] /= pivot;
        }
        for (let row = 0; row < n; row++) {
            if (row === col) continue;
            const factor = M[row][col];
            if (Math.abs(factor) < 1e-14) continue;
            for (let j = col; j <= n; j++) {
                M[row][j] -= factor * M[col][j];
            }
        }
    }

    const x = new Array(n);
    for (let i = 0; i < n; i++) {
        x[i] = M[i][n];
    }
    return x;
}

/**
 * Ortogonalización polar en SO(3) (iteración de Newton-Schulz R_{k+1} = 0.5 * (R_k + R_k^{-T}))
 * que proyecta una matriz 3x3 aproximada sobre la matriz de rotación ortonormal más cercana
 * (equivalente a U * V^T de la descomposición SVD).
 */
function ortogonalizarMatrizRotacion3x3(M) {
    let R = [
        [M[0][0], M[0][1], M[0][2]],
        [M[1][0], M[1][1], M[1][2]],
        [M[2][0], M[2][1], M[2][2]]
    ];

    for (let iter = 0; iter < 6; iter++) {
        const c00 = R[1][1] * R[2][2] - R[1][2] * R[2][1];
        const c01 = -(R[1][0] * R[2][2] - R[1][2] * R[2][0]);
        const c02 = R[1][0] * R[2][1] - R[1][1] * R[2][0];

        const c10 = -(R[0][1] * R[2][2] - R[0][2] * R[2][1]);
        const c11 = R[0][0] * R[2][2] - R[0][2] * R[2][0];
        const c12 = -(R[0][0] * R[2][1] - R[0][1] * R[2][0]);

        const c20 = R[0][1] * R[1][2] - R[0][2] * R[1][1];
        const c21 = -(R[0][0] * R[1][2] - R[0][2] * R[1][0]);
        const c22 = R[0][0] * R[1][1] - R[0][1] * R[1][0];

        const det = R[0][0] * c00 + R[0][1] * c01 + R[0][2] * c02;
        if (Math.abs(det) < 1e-9) break;
        const invDet = 1.0 / det;

        R = [
            [0.5 * (R[0][0] + c00 * invDet), 0.5 * (R[0][1] + c01 * invDet), 0.5 * (R[0][2] + c02 * invDet)],
            [0.5 * (R[1][0] + c10 * invDet), 0.5 * (R[1][1] + c11 * invDet), 0.5 * (R[1][2] + c12 * invDet)],
            [0.5 * (R[2][0] + c20 * invDet), 0.5 * (R[2][1] + c21 * invDet), 0.5 * (R[2][2] + c22 * invDet)]
        ];
    }

    // Garantizar det(R) = +1 en SO(3)
    const detFinal =
        R[0][0] * (R[1][1] * R[2][2] - R[1][2] * R[2][1]) -
        R[0][1] * (R[1][0] * R[2][2] - R[1][2] * R[2][0]) +
        R[0][2] * (R[1][0] * R[2][1] - R[1][1] * R[2][0]);
    if (detFinal < 0) {
        R[0][1] = -R[0][1];
        R[1][1] = -R[1][1];
        R[2][1] = -R[2][1];
    }
    return R;
}

class AdbanaQRAREngine {
    constructor() {
        this.loader = new GLTFLoader();
        this.cacheModelos = new Map();

        this.activo = false;
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

        // Estado de tracking 6-DoF por QR
        this.platoColocado = false;
        this.qrVisibleAhora = false;
        this.ultimoTiempoQR = 0;
        this.ultimoEscaneoMs = 0;

        // Tamaño físico real de la tarjeta QR de mesa: 10 cm (0.10 m)
        this.tamanoFisicoQrMetros = 0.10;

        // Pose 6-DoF en espacio mundo (permite rodear el plato con sensores IMU + homografía QR)
        this.posMundoObjetivo = new THREE.Vector3(0, -0.08, -0.35);
        this.posMundoEstable = new THREE.Vector3(0, -0.08, -0.35);
        this.quatMundoObjetivo = new THREE.Quaternion();
        this.quatMundoEstable = new THREE.Quaternion();
        this.filtroPos = new OneEuroFilterVec3(1.6, 0.45);

        // Fusión con sensores IMU (DeviceOrientationEvent) para mantener el anclaje 3D al rodear la mesa
        this.imuDisponible = false;
        this.quatCamaraIMU = new THREE.Quaternion();
        this._onDeviceOrientationBound = (ev) => this.actualizarOrientacionIMU(ev);

        // Referencias DOM (Interfaz ultra limpia: un solo indicador + botón cerrar)
        this.overlayRoot = null;
        this.uiStatusPill = null;
        this.uiStatusDot = null;
        this.uiStatusText = null;
        this.rafId = null;
        this._onResizeBound = () => this.actualizarDimensiones();
    }

    /**
     * Tamaño físico predeterminado fijo (~15 cm a 18 cm) calibrado respecto al QR de 10 cm.
     * Sin controles ni sliders de escala editables por el usuario.
     */
    obtenerDiametroObjetivoMetros(plato) {
        const nombre = ((plato && plato.nombre) ? String(plato.nombre) : '').toLowerCase();
        if (nombre.includes('pizza')) return 0.17; // 17 cm
        if (nombre.includes('hamburguesa') || nombre.includes('burger')) return 0.145; // 14.5 cm
        if (nombre.includes('brocheta') || nombre.includes('anticucho') || nombre.includes('parrilla')) return 0.165; // 16.5 cm
        return 0.16; // ~16 cm predeterminado
    }

    /**
     * Carga el modelo .glb, lo normaliza a ~15-17 cm y apoya su base EXACTAMENTE en Y = 0.0000
     * sobre el plano del código QR/mesa (cero flotación).
     */
    async cargarModeloNormalizado(url, plato) {
        const cleanUrl = url.includes('?') ? url : (url + '?v=ar10');
        if (this.cacheModelos.has(cleanUrl)) {
            return this.cacheModelos.get(cleanUrl).clone(true);
        }

        return new Promise((resolve, reject) => {
            this.loader.load(
                cleanUrl,
                (gltf) => {
                    const model = gltf.scene;
                    model.updateMatrixWorld(true);

                    // Calcular bounding box exacto sobre todas las mallas visibles
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
                    // Base apoyada exactamente en Y = 0.0000 del plano de la mesa (cero flotación)
                    model.position.set(
                        -center.x * scale,
                        -box.min.y * scale,
                        -center.z * scale
                    );
                    wrapper.add(model);
                    this.cacheModelos.set(cleanUrl, wrapper);
                    resolve(wrapper.clone(true));
                },
                undefined,
                (err) => reject(err)
            );
        });
    }

    /**
     * Interfaz ultra limpia:
     * - Video de cámara a pantalla completa
     * - UN solo indicador discreto arriba ("Apunta al QR de la mesa" ➔ "Plato anclado")
     * - Botón "✕ Cerrar" en la esquina superior derecha
     */
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

        // near = 0.005 m (5 mm) para poder acercar el teléfono al plato sin que se recorte
        const camera = new THREE.PerspectiveCamera(54, vw / vh, 0.005, 20);
        camera.position.set(0, 0, 0);
        camera.quaternion.identity();

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
        renderer.toneMappingExposure = 1.1;
        renderer.domElement.className = 'qr-ar-webgl-canvas';

        if (this.overlayRoot) {
            this.overlayRoot.insertBefore(renderer.domElement, this.overlayRoot.querySelector('.qr-ar-topbar'));
        }

        try {
            this.pmremGenerator = new THREE.PMREMGenerator(renderer);
            this.envTexture = this.pmremGenerator.fromScene(new RoomEnvironment(), 0.04).texture;
            scene.environment = this.envTexture;
        } catch (e) {}

        const hemiLight = new THREE.HemisphereLight(0xfffbeb, 0x292524, 1.45);
        scene.add(hemiLight);

        const dirLight = new THREE.DirectionalLight(0xffffff, 1.65);
        dirLight.position.set(0.4, 2.0, 0.8);
        scene.add(dirLight);

        // Nodo raíz anclado al plano físico Y = 0 del código QR sobre la mesa
        const platoRoot = new THREE.Group();
        platoRoot.visible = false;

        // platoPivot descansa exactamente en Y = 0 sobre el plano del QR/mesa
        const platoPivot = new THREE.Group();
        platoPivot.position.set(0, 0, 0);
        platoRoot.add(platoPivot);

        // Sombra de contacto suave en Y = 0.001 m debajo del plato
        const diametro = this.obtenerDiametroObjetivoMetros(plato);
        const sombraGeo = new THREE.PlaneGeometry(diametro * 1.22, diametro * 1.22);
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

        this.actualizarDimensiones();
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
     * Fusión con giroscopio/acelerómetro (DeviceOrientationEvent) para mantener la
     * orientación 3D de la cámara cuando el usuario rodea el plato y el QR sale del encuadre.
     */
    actualizarOrientacionIMU(ev) {
        if (ev.alpha === null || ev.beta === null || ev.gamma === null) return;
        const degToRad = THREE.MathUtils.degToRad;
        const alpha = degToRad(ev.alpha || 0);
        const beta = degToRad(ev.beta || 0);
        const gamma = degToRad(ev.gamma || 0);
        const orientDeg = (window.screen && window.screen.orientation && typeof window.screen.orientation.angle === 'number')
            ? window.screen.orientation.angle
            : (typeof window.orientation === 'number' ? window.orientation : 0);
        const orient = degToRad(orientDeg || 0);

        const zee = new THREE.Vector3(0, 0, 1);
        const euler = new THREE.Euler(beta, alpha, -gamma, 'YXZ');
        const q0 = new THREE.Quaternion().setFromEuler(euler);
        const q1 = new THREE.Quaternion(-Math.sqrt(0.5), 0, 0, Math.sqrt(0.5));
        const q2 = new THREE.Quaternion().setFromAxisAngle(zee, -orient);

        this.quatCamaraIMU.copy(q0).multiply(q1).multiply(q2);
        this.imuDisponible = true;
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

        const targetW = 480;
        const targetH = Math.max(270, Math.round((vh / vw) * targetW));
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

    /**
     * Estimación de Pose 6-DoF exacta (Traslación X, Y, Z y Rotación Pitch, Yaw, Roll)
     * mediante Descomposición de Homografía Plana + Ortogonalización Polar SO(3) + Refinamiento PnP.
     *
     * Sistema local del QR en la mesa (mano derecha):
     * - Origen (0, 0, 0) en el centro del QR sobre la mesa.
     * - Plano de la mesa: Y = 0.
     * - +X hacia la derecha del QR, +Z hacia el borde inferior del QR, +Y normal hacia arriba (perpendicular a la mesa).
     */
    estimarPose6DoFDesdeEsquinas(ptsScreen, vw, vh) {
        const fovRad = THREE.MathUtils.degToRad(this.camera.fov);
        const fy = (vh * 0.5) / Math.tan(fovRad * 0.5);
        const fx = fy;
        const cx = vw * 0.5;
        const cy = vh * 0.5;

        const s = this.tamanoFisicoQrMetros * 0.5; // 0.05 m (mitad de 10 cm)
        // Puntos 3D de las 4 esquinas del QR sobre el plano de la mesa Y = 0: (X_i, 0, Z_i)
        const modelPts = [
            { X: -s, Z: -s }, // topLeft
            { X:  s, Z: -s }, // topRight
            { X:  s, Z:  s }, // bottomRight
            { X: -s, Z:  s }  // bottomLeft
        ];

        // Coordenadas normalizadas en cámara CV (+X derecha, +Y abajo, +Z hacia adelante)
        const normPts = ptsScreen.map((p) => ({
            x: (p.x - cx) / fx,
            y: (p.y - cy) / fy
        }));

        // Construir sistema DLT 8x8 para hallar la homografía H tal que [x_i, y_i, 1]^T ~ H * [X_i, Z_i, 1]^T
        const A = [];
        const b = [];
        for (let i = 0; i < 4; i++) {
            const X = modelPts[i].X;
            const Z = modelPts[i].Z;
            const x = normPts[i].x;
            const y = normPts[i].y;

            A.push([X, Z, 1, 0, 0, 0, -x * X, -x * Z]);
            b.push(x);
            A.push([0, 0, 0, X, Z, 1, -y * X, -y * Z]);
            b.push(y);
        }

        const h = resolverSistemaLineal(A, b);
        if (!h) return null;

        let H = [
            [h[0], h[1], h[2]],
            [h[3], h[4], h[5]],
            [h[6], h[7], 1.0]
        ];

        // Asegurar que el centro del QR esté frente a la cámara (t_z > 0 en convención CV)
        if (H[2][2] < 0) {
            for (let r = 0; r < 3; r++) {
                for (let c = 0; c < 3; c++) {
                    H[r][c] = -H[r][c];
                }
            }
        }

        // Columnas h1 (eje +X de la mesa), h2 (eje +Z de la mesa), h3 (traslación t)
        const h1 = [H[0][0], H[1][0], H[2][0]];
        const h2 = [H[0][1], H[1][1], H[2][1]];
        const h3 = [H[0][2], H[1][2], H[2][2]];

        const norm1 = Math.hypot(h1[0], h1[1], h1[2]);
        const norm2 = Math.hypot(h2[0], h2[1], h2[2]);
        if (norm1 < 1e-6 || norm2 < 1e-6) return null;

        const scale = 1.0 / Math.sqrt(norm1 * norm2);

        const r1 = [h1[0] * scale, h1[1] * scale, h1[2] * scale];
        const r3 = [h2[0] * scale, h2[1] * scale, h2[2] * scale];
        const tCv = [h3[0] * scale, h3[1] * scale, h3[2] * scale];

        // En nuestro sistema de mesa (mano derecha), la normal hacia arriba +Y es r2 = r3 x r1
        const r2 = [
            r3[1] * r1[2] - r3[2] * r1[1],
            r3[2] * r1[0] - r3[0] * r1[2],
            r3[0] * r1[1] - r3[1] * r1[0]
        ];

        // Ortogonalización Polar exacta en SO(3)
        let Rcv = ortogonalizarMatrizRotacion3x3([
            [r1[0], r2[0], r3[0]],
            [r1[1], r2[1], r3[1]],
            [r1[2], r2[2], r3[2]]
        ]);

        // Si la normal de la mesa (+Y local) apunta alejándose de la cámara (mirando la mesa por debajo),
        // corregir el signo planar para que el plato siempre se apoye sobre la cara superior de la mesa.
        if (Rcv[2][1] > 0.15) {
            Rcv[0][1] = -Rcv[0][1];
            Rcv[1][1] = -Rcv[1][1];
            Rcv[2][1] = -Rcv[2][1];
            Rcv[0][2] = -Rcv[0][2];
            Rcv[1][2] = -Rcv[1][2];
            Rcv[2][2] = -Rcv[2][2];
        }

        // Refinamiento Gauss-Newton PnP (3 iteraciones) sobre la traslación 3D para clavar las 4 esquinas
        for (let iter = 0; iter < 3; iter++) {
            let ata00 = 0, ata01 = 0, ata02 = 0;
            let ata11 = 0, ata12 = 0, ata22 = 0;
            let atb0 = 0, atb1 = 0, atb2 = 0;

            for (let i = 0; i < 4; i++) {
                const X = modelPts[i].X;
                const Z = modelPts[i].Z;
                const px = Rcv[0][0] * X + Rcv[0][2] * Z + tCv[0];
                const py = Rcv[1][0] * X + Rcv[1][2] * Z + tCv[1];
                const pz = Math.max(Rcv[2][0] * X + Rcv[2][2] * Z + tCv[2], 0.04);
                const invZ = 1.0 / pz;
                const uProj = px * invZ;
                const vProj = py * invZ;

                const ex = normPts[i].x - uProj;
                const ey = normPts[i].y - vProj;

                // Jacobiano respecto a (tx, ty, tz)
                const jx0 = invZ, jx2 = -uProj * invZ;
                const jy1 = invZ, jy2 = -vProj * invZ;

                ata00 += jx0 * jx0;
                ata02 += jx0 * jx2;
                ata11 += jy1 * jy1;
                ata12 += jy1 * jy2;
                ata22 += jx2 * jx2 + jy2 * jy2;

                atb0 += jx0 * ex;
                atb1 += jy1 * ey;
                atb2 += jx2 * ex + jy2 * ey;
            }

            const deltaT = resolverSistemaLineal(
                [
                    [ata00, ata01, ata02],
                    [ata01, ata11, ata12],
                    [ata02, ata12, ata22]
                ],
                [atb0, atb1, atb2]
            );
            if (deltaT) {
                tCv[0] += deltaT[0];
                tCv[1] += deltaT[1];
                tCv[2] = THREE.MathUtils.clamp(tCv[2] + deltaT[2], 0.08, 2.2);
            }
        }

        // Convertir de convención CV (+X der, +Y abajo, +Z adelante) a cámara Three.js (+X der, +Y arriba, -Z adelante)
        // aplicando F = diag(1, -1, -1)
        const posCam = new THREE.Vector3(tCv[0], -tCv[1], -tCv[2]);
        const matCam = new THREE.Matrix4().set(
             Rcv[0][0],  Rcv[0][1],  Rcv[0][2], 0,
            -Rcv[1][0], -Rcv[1][1], -Rcv[1][2], 0,
            -Rcv[2][0], -Rcv[2][1], -Rcv[2][2], 0,
             0,          0,          0,         1
        );
        const quatCam = new THREE.Quaternion().setFromRotationMatrix(matCam);

        return { posCam, quatCam };
    }

    actualizarPoseDesdeEsquinasQR(det, timestampSec) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const tl = this.mapearPuntoVideoAPantalla(det.corners.topLeft.x, det.corners.topLeft.y, det.cw, det.ch, vw, vh);
        const tr = this.mapearPuntoVideoAPantalla(det.corners.topRight.x, det.corners.topRight.y, det.cw, det.ch, vw, vh);
        const br = this.mapearPuntoVideoAPantalla(det.corners.bottomRight.x, det.corners.bottomRight.y, det.cw, det.ch, vw, vh);
        const bl = this.mapearPuntoVideoAPantalla(det.corners.bottomLeft.x, det.corners.bottomLeft.y, det.cw, det.ch, vw, vh);

        const pose6DoF = this.estimarPose6DoFDesdeEsquinas([tl, tr, br, bl], vw, vh);
        if (!pose6DoF) return;

        // Actualizar orientación de la cámara (desde IMU si está activa, o identidad)
        if (this.imuDisponible) {
            this.camera.quaternion.copy(this.quatCamaraIMU);
        } else {
            this.camera.quaternion.identity();
        }

        // Transformar la pose del QR del espacio de la cámara al espacio 3D del mundo (mesa)
        this.posMundoObjetivo.copy(pose6DoF.posCam).applyQuaternion(this.camera.quaternion).add(this.camera.position);
        this.quatMundoObjetivo.copy(this.camera.quaternion).multiply(pose6DoF.quatCam);

        // Suavizado cinemático sin latencia (One-Euro Filter en X,Y,Z + Slerp en rotación 3D)
        this.filtroPos.filter(this.posMundoObjetivo, timestampSec, this.posMundoEstable);

        if (!this.platoColocado) {
            this.quatMundoEstable.copy(this.quatMundoObjetivo);
        } else {
            this.quatMundoEstable.slerp(this.quatMundoObjetivo, 0.28);
        }

        if (this.platoRoot) {
            this.platoRoot.position.copy(this.posMundoEstable);
            this.platoRoot.quaternion.copy(this.quatMundoEstable);
            this.platoRoot.visible = true;
        }

        this.platoColocado = true;
        this.qrVisibleAhora = true;
        this.ultimoTiempoQR = performance.now();
        this.actualizarIndicadorUnico(true);
    }

    /**
     * Único indicador de estado en pantalla:
     * - "Apunta al QR de la mesa" (buscando)
     * - "Plato anclado" (anclado sobre la mesa)
     */
    actualizarIndicadorUnico(anclado) {
        if (!this.uiStatusDot || !this.uiStatusText) return;
        if (anclado) {
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
        this.platoColocado = false;
        this.qrVisibleAhora = false;
        this.imuDisponible = false;
        this.quatCamaraIMU.identity();
        this.filtroPos.reset();

        this.activarPermisoIMUSiNecesario();
        this.construirInterfazAR();

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

        this.cargarModeloNormalizado(plato.modelo_3d, plato).then((modeloGroup) => {
            if (!this.activo || !this.platoPivot) return;
            this.platoMesh = modeloGroup;
            this.platoPivot.add(modeloGroup);
        }).catch(() => {});

        const loop = (nowMs) => {
            if (!this.activo) return;
            this.rafId = requestAnimationFrame(loop);

            // Actualizar orientación IMU de la cámara en cada frame (60 fps) para rodear el plato
            if (this.camera && this.imuDisponible) {
                this.camera.quaternion.slerp(this.quatCamaraIMU, 0.35);
            }

            // Detectar QR y estimar pose 6-DoF cada ~33 ms (~30 fps)
            if (nowMs - this.ultimoEscaneoMs >= 33) {
                this.ultimoEscaneoMs = nowMs;
                const det = this.detectarQREnFrame();
                if (det) {
                    this.actualizarPoseDesdeEsquinasQR(det, nowMs * 0.001);
                } else if (this.qrVisibleAhora && nowMs - this.ultimoTiempoQR > 400) {
                    this.qrVisibleAhora = false;
                    // El plato permanece anclado en el espacio 3D de la mesa (platoRoot.visible = true)
                    if (this.platoColocado && this.platoRoot) {
                        this.platoRoot.visible = true;
                        this.actualizarIndicadorUnico(true);
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
