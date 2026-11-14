import * as THREE from './vendor/three.module.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

/**
 * Filtro cinemático One-Euro (1€ Filter) para suavizar la pose 3D
 * obtenida de las 4 esquinas del código QR eliminando el temblor de la mano
 * sin introducir latencia al mover el teléfono.
 */
class OneEuroFilter {
    constructor(minCutoff = 1.8, beta = 0.35, dCutoff = 1.0) {
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
    constructor(minCutoff = 1.8, beta = 0.35) {
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

function crearTexturaSombraContacto() {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const half = size / 2;
    const grad = ctx.createRadialGradient(half, half, size * 0.08, half, half, size * 0.48);
    grad.addColorStop(0.0, 'rgba(0, 0, 0, 0.72)');
    grad.addColorStop(0.55, 'rgba(0, 0, 0, 0.38)');
    grad.addColorStop(0.85, 'rgba(0, 0, 0, 0.10)');
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

        this.activo = false;
        this.stream = null;
        this.video = null;
        this.hudCanvas = null;
        this.hudCtx = null;
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
        this.fijadoManual = false;
        this.qrVisibleAhora = false;
        this.ultimoTiempoQR = 0;
        this.ultimoEscaneoMs = 0;

        // Tamaño físico estándar del QR impreso en la tarjeta de mesa (8.5 cm = 0.085 m)
        this.tamanoFisicoQrMetros = 0.085;

        // Pose 3D filtrada y última pose estable conservada (nunca desaparece)
        this.posObjetivo = new THREE.Vector3(0, -0.04, -0.42);
        this.posEstable = new THREE.Vector3(0, -0.04, -0.42);
        this.quatObjetivo = new THREE.Quaternion();
        this.quatEstable = new THREE.Quaternion();
        this.filtroPos = new OneEuroFilterVec3(1.8, 0.35);

        // Controles de usuario (rotación con dedo y ajuste de escala/distancia)
        this.rotacionUsuarioY = 0;
        this.factorEscalaUsuario = 1.0;

        // Referencias DOM
        this.overlayRoot = null;
        this.uiStatusDot = null;
        this.uiStatusText = null;
        this.uiBannerGuia = null;
        this.uiBtnRecentrar = null;
        this.uiBtnFijar = null;
        this.uiBtnColocarSinQr = null;
        this.uiSliderEscala = null;
        this.uiLabelEscala = null;
        this.rafId = null;
        this._onResizeBound = () => this.actualizarDimensiones();
    }

    obtenerDiametroObjetivoMetros(plato) {
        const nombre = ((plato && plato.nombre) ? String(plato.nombre) : '').toLowerCase();
        if (nombre.includes('pizza')) return 0.35; // 35 cm
        if (nombre.includes('hamburguesa') || nombre.includes('burger')) return 0.14; // 14 cm
        if (nombre.includes('brocheta') || nombre.includes('anticucho') || nombre.includes('parrilla')) return 0.25; // 25 cm
        return 0.25;
    }

    async cargarModeloNormalizado(url, plato) {
        const cleanUrl = url.includes('?') ? url : (url + '?v=ar9');
        if (this.cacheModelos.has(cleanUrl)) {
            return this.cacheModelos.get(cleanUrl).clone(true);
        }

        return new Promise((resolve, reject) => {
            this.loader.load(
                cleanUrl,
                (gltf) => {
                    const model = gltf.scene;
                    const box = new THREE.Box3().setFromObject(model);
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
                    resolve(wrapper.clone(true));
                },
                undefined,
                (err) => reject(err)
            );
        });
    }

    construirInterfazAR(plato) {
        if (this.overlayRoot && this.overlayRoot.parentNode) {
            this.overlayRoot.parentNode.removeChild(this.overlayRoot);
        }

        const root = document.createElement('div');
        root.id = 'adbana-qr-ar-modal';
        root.className = 'qr-ar-overlay';

        // 1. Video de cámara trasera a pantalla completa
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

        // 2. Canvas 2D para dibujar el marco verde sobre las 4 esquinas detectadas del QR
        const hudCanvas = document.createElement('canvas');
        hudCanvas.className = 'qr-ar-hud-canvas';
        root.appendChild(hudCanvas);

        // 3. Barra superior: Estado del anclaje QR + Botón Salir
        const topBar = document.createElement('div');
        topBar.className = 'qr-ar-topbar';

        const statusPill = document.createElement('div');
        statusPill.className = 'qr-ar-status-pill';
        const dot = document.createElement('span');
        dot.className = 'qr-ar-status-dot is-searching';
        const statusTxt = document.createElement('span');
        statusTxt.textContent = 'Buscando QR de la mesa…';
        statusPill.appendChild(dot);
        statusPill.appendChild(statusTxt);

        const btnCerrar = document.createElement('button');
        btnCerrar.type = 'button';
        btnCerrar.className = 'qr-ar-btn-close';
        btnCerrar.textContent = '✕ Cerrar AR';
        btnCerrar.addEventListener('click', () => this.finalizar());

        topBar.appendChild(statusPill);
        topBar.appendChild(btnCerrar);
        root.appendChild(topBar);

        // 4. Guía central / Retícula de escaneo QR (se oculta apenas detecta el QR en < 0.5 s)
        const guiaWrap = document.createElement('div');
        guiaWrap.className = 'qr-ar-reticle-wrap';
        guiaWrap.innerHTML = `
            <div class="qr-ar-reticle-box">
                <span class="qr-corner tl"></span>
                <span class="qr-corner tr"></span>
                <span class="qr-corner bl"></span>
                <span class="qr-corner br"></span>
                <div class="qr-scan-line"></div>
            </div>
            <div class="qr-ar-guide-banner">
                📷 <strong>Apunta al código QR de la mesa</strong><br>
                <span>El plato aparecerá anclado sobre el QR al instante.</span>
            </div>
        `;
        const btnSinQr = document.createElement('button');
        btnSinQr.type = 'button';
        btnSinQr.className = 'qr-ar-btn-instant';
        btnSinQr.textContent = '✨ No tengo el QR a mano · Colocar plato aquí';
        btnSinQr.addEventListener('click', (e) => {
            e.stopPropagation();
            this.colocarPlatoEnCentroPorDefecto();
        });
        guiaWrap.appendChild(btnSinQr);
        root.appendChild(guiaWrap);

        // 5. Panel inferior de controles (Re-centrar en QR, Fijar/Seguir QR, Escala/Distancia y Giro)
        const bottomPanel = document.createElement('div');
        bottomPanel.className = 'qr-ar-bottom-panel';

        const scaleRow = document.createElement('div');
        scaleRow.className = 'qr-ar-scale-row';
        const scaleLabel = document.createElement('span');
        scaleLabel.className = 'qr-ar-scale-label';
        scaleLabel.textContent = '🔍 Escala: 100%';
        const scaleSlider = document.createElement('input');
        scaleSlider.type = 'range';
        scaleSlider.min = '0.55';
        scaleSlider.max = '1.75';
        scaleSlider.step = '0.05';
        scaleSlider.value = '1.00';
        scaleSlider.className = 'qr-ar-scale-slider';
        scaleSlider.setAttribute('aria-label', 'Ajustar escala o distancia del plato');
        scaleSlider.addEventListener('input', (e) => {
            this.factorEscalaUsuario = parseFloat(e.target.value) || 1.0;
            scaleLabel.textContent = '🔍 Escala: ' + Math.round(this.factorEscalaUsuario * 100) + '%';
            if (this.platoPivot) {
                this.platoPivot.scale.setScalar(this.factorEscalaUsuario);
            }
        });
        scaleRow.appendChild(scaleLabel);
        scaleRow.appendChild(scaleSlider);

        const actionsRow = document.createElement('div');
        actionsRow.className = 'qr-ar-actions-row';

        const btnRecentrar = document.createElement('button');
        btnRecentrar.type = 'button';
        btnRecentrar.className = 'qr-ar-btn-action is-primary';
        btnRecentrar.textContent = '🎯 Re-centrar en QR';
        btnRecentrar.addEventListener('click', (e) => {
            e.stopPropagation();
            this.recentrarEnQR();
        });

        const btnFijar = document.createElement('button');
        btnFijar.type = 'button';
        btnFijar.className = 'qr-ar-btn-action is-secondary';
        btnFijar.textContent = '🔒 Fijar plato';
        btnFijar.addEventListener('click', (e) => {
            e.stopPropagation();
            this.alternarFijadoManual();
        });

        actionsRow.appendChild(btnRecentrar);
        actionsRow.appendChild(btnFijar);

        const hintRow = document.createElement('div');
        hintRow.className = 'qr-ar-hint-text';
        hintRow.textContent = '💡 Desliza con 1 dedo para girar el plato · Si te acercas o mueves la cámara, el plato conserva su posición.';

        bottomPanel.appendChild(scaleRow);
        bottomPanel.appendChild(actionsRow);
        bottomPanel.appendChild(hintRow);
        root.appendChild(bottomPanel);

        document.body.appendChild(root);

        this.overlayRoot = root;
        this.video = video;
        this.hudCanvas = hudCanvas;
        this.hudCtx = hudCanvas.getContext('2d');
        this.uiStatusDot = dot;
        this.uiStatusText = statusTxt;
        this.uiBannerGuia = guiaWrap;
        this.uiBtnRecentrar = btnRecentrar;
        this.uiBtnFijar = btnFijar;
        this.uiBtnColocarSinQr = btnSinQr;
        this.uiSliderEscala = scaleSlider;
        this.uiLabelEscala = scaleLabel;
    }

    construirEscenaThree(plato) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const scene = new THREE.Scene();
        scene.background = null;

        // near = 0.005 m (5 mm) evita que el plato se recorte o desaparezca al acercar el celular
        const camera = new THREE.PerspectiveCamera(52, vw / vh, 0.005, 25);
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

        // Insertar el canvas 3D justo encima del video y del canvas 2D de esquinas QR
        if (this.overlayRoot) {
            this.overlayRoot.insertBefore(renderer.domElement, this.uiBannerGuia);
        }

        try {
            this.pmremGenerator = new THREE.PMREMGenerator(renderer);
            this.envTexture = this.pmremGenerator.fromScene(new RoomEnvironment(), 0.04).texture;
            scene.environment = this.envTexture;
        } catch (e) {}

        const hemiLight = new THREE.HemisphereLight(0xfffbeb, 0x292524, 1.5);
        scene.add(hemiLight);

        const dirLight = new THREE.DirectionalLight(0xffffff, 1.6);
        dirLight.position.set(0.6, 1.8, 1.2);
        scene.add(dirLight);

        const platoRoot = new THREE.Group();
        platoRoot.visible = false;

        const platoPivot = new THREE.Group();
        platoRoot.add(platoPivot);

        const diametro = this.obtenerDiametroObjetivoMetros(plato);
        const sombraGeo = new THREE.PlaneGeometry(diametro * 1.28, diametro * 1.28);
        sombraGeo.rotateX(-Math.PI / 2);
        const sombraMat = new THREE.MeshBasicMaterial({
            map: crearTexturaSombraContacto(),
            transparent: true,
            opacity: 0.65,
            depthWrite: false
        });
        const sombraMesh = new THREE.Mesh(sombraGeo, sombraMat);
        sombraMesh.position.y = 0.001;
        platoPivot.add(sombraMesh);

        scene.add(platoRoot);

        this.scene = scene;
        this.camera = camera;
        this.renderer = renderer;
        this.platoRoot = platoRoot;
        this.platoPivot = platoPivot;
        this.sombraMesh = sombraMesh;

        this.configurarGestosTactiles(renderer.domElement);
        this.actualizarDimensiones();
    }

    configurarGestosTactiles(domElement) {
        let arrastrando = false;
        let prevX = 0;
        let distPinchInicial = 0;
        let escalaPinchInicial = 1.0;

        domElement.addEventListener('pointerdown', (e) => {
            arrastrando = true;
            prevX = e.clientX;
        });
        domElement.addEventListener('pointermove', (e) => {
            if (!arrastrando || !this.platoColocado) return;
            const dx = e.clientX - prevX;
            prevX = e.clientX;
            this.rotacionUsuarioY += dx * 0.012;
            if (this.platoMesh) {
                this.platoMesh.rotation.y = this.rotacionUsuarioY;
            }
        });
        window.addEventListener('pointerup', () => {
            arrastrando = false;
        });

        domElement.addEventListener('touchstart', (e) => {
            if (e.touches && e.touches.length === 2) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                distPinchInicial = Math.hypot(dx, dy);
                escalaPinchInicial = this.factorEscalaUsuario;
            }
        }, { passive: true });

        domElement.addEventListener('touchmove', (e) => {
            if (e.touches && e.touches.length === 2 && distPinchInicial > 10) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                const distActual = Math.hypot(dx, dy);
                const nuevaEscala = Math.min(1.75, Math.max(0.55, escalaPinchInicial * (distActual / distPinchInicial)));
                this.factorEscalaUsuario = nuevaEscala;
                if (this.platoPivot) {
                    this.platoPivot.scale.setScalar(nuevaEscala);
                }
                if (this.uiSliderEscala) {
                    this.uiSliderEscala.value = nuevaEscala.toFixed(2);
                }
                if (this.uiLabelEscala) {
                    this.uiLabelEscala.textContent = '🔍 Escala: ' + Math.round(nuevaEscala * 100) + '%';
                }
            }
        }, { passive: true });
    }

    actualizarDimensiones() {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;
        if (this.hudCanvas) {
            this.hudCanvas.width = vw;
            this.hudCanvas.height = vh;
        }
        if (this.camera) {
            this.camera.aspect = vw / vh;
            this.camera.updateProjectionMatrix();
        }
        if (this.renderer) {
            this.renderer.setSize(vw, vh);
        }
    }

    /**
     * Convierte un punto 2D (x, y) del canvas de visión artificial (cw, ch)
     * a coordenadas de píxel de pantalla (vw, vh) respetando object-fit: cover del <video>.
     */
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
     * Detecta el código QR en el frame actual del video usando jsQR local
     * y devuelve las 4 esquinas (topLeft, topRight, bottomRight, bottomLeft).
     */
    detectarQREnFrame() {
        if (!this.video || this.video.readyState < 2 || !window.jsQR) return null;
        const vw = this.video.videoWidth;
        const vh = this.video.videoHeight;
        if (!vw || !vh) return null;

        const targetW = 440;
        const targetH = Math.max(240, Math.round((vh / vw) * targetW));
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
     * Dibuja un contorno sutil sobre las 4 esquinas detectadas del QR en el HUD
     * para confirmar visualmente el tracking sin tapar el plato.
     */
    dibujarEsquinasQR(cornersScreen) {
        if (!this.hudCtx || !this.hudCanvas) return;
        this.hudCtx.clearRect(0, 0, this.hudCanvas.width, this.hudCanvas.height);
        if (!cornersScreen) return;

        const pts = [
            cornersScreen.topLeft,
            cornersScreen.topRight,
            cornersScreen.bottomRight,
            cornersScreen.bottomLeft
        ];
        const ctx = this.hudCtx;
        ctx.save();
        ctx.strokeStyle = 'rgba(74, 222, 128, 0.85)';
        ctx.fillStyle = 'rgba(34, 197, 94, 0.14)';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        ctx.lineTo(pts[1].x, pts[1].y);
        ctx.lineTo(pts[2].x, pts[2].y);
        ctx.lineTo(pts[3].x, pts[3].y);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        for (const p of pts) {
            ctx.beginPath();
            ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
            ctx.fillStyle = '#4ade80';
            ctx.fill();
        }
        ctx.restore();
    }

    /**
     * Calcula la posición 3D (X, Y, Z), escala métrica y orientación de la mesa
     * a partir de las 4 esquinas del código QR en pantalla.
     */
    actualizarPoseDesdeEsquinasQR(det, timestampSec) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const tl = this.mapearPuntoVideoAPantalla(det.corners.topLeft.x, det.corners.topLeft.y, det.cw, det.ch, vw, vh);
        const tr = this.mapearPuntoVideoAPantalla(det.corners.topRight.x, det.corners.topRight.y, det.cw, det.ch, vw, vh);
        const br = this.mapearPuntoVideoAPantalla(det.corners.bottomRight.x, det.corners.bottomRight.y, det.cw, det.ch, vw, vh);
        const bl = this.mapearPuntoVideoAPantalla(det.corners.bottomLeft.x, det.corners.bottomLeft.y, det.cw, det.ch, vw, vh);

        this.dibujarEsquinasQR({ topLeft: tl, topRight: tr, bottomRight: br, bottomLeft: bl });

        // Centro de las 4 esquinas en píxeles de pantalla
        const cx = (tl.x + tr.x + br.x + bl.x) * 0.25;
        const cy = (tl.y + tr.y + br.y + bl.y) * 0.25;

        // Longitudes de los 4 lados del QR en pantalla
        const topLen = Math.hypot(tr.x - tl.x, tr.y - tl.y);
        const botLen = Math.hypot(br.x - bl.x, br.y - bl.y);
        const leftLen = Math.hypot(bl.x - tl.x, bl.y - tl.y);
        const rightLen = Math.hypot(br.x - tr.x, br.y - tr.y);

        const avgHorizPx = (topLen + botLen) * 0.5;
        const avgVertPx = (leftLen + rightLen) * 0.5;
        const apparentSpanPx = Math.max(avgHorizPx, avgVertPx, 18);

        // Distancia métrica real (profundidad Z) usando la focal vertical de Three.js y el tamaño real del QR (8.5 cm)
        const fovRad = THREE.MathUtils.degToRad(this.camera.fov);
        const fy = (vh * 0.5) / Math.tan(fovRad * 0.5);
        const depth = THREE.MathUtils.clamp((fy * this.tamanoFisicoQrMetros) / apparentSpanPx, 0.16, 1.55);

        // Coordenadas NDC [-1, +1] con un ligero offset superior (+0.024 m) para no tapar por completo las esquinas inferiores del QR
        const ndcX = (cx / vw) * 2 - 1;
        const ndcY = -(cy / vh) * 2 + 1;

        const halfH = depth * Math.tan(fovRad * 0.5);
        const halfW = halfH * this.camera.aspect;

        const worldX = ndcX * halfW;
        const worldY = ndcY * halfH + 0.022;
        const worldZ = -depth;

        this.posObjetivo.set(worldX, worldY, worldZ);
        this.filtroPos.filter(this.posObjetivo, timestampSec, this.posEstable);

        // Estimación de inclinación (pitch) y giro (yaw) del plano de la mesa a partir del escorzo de las 4 esquinas
        const ratioEscorzo = THREE.MathUtils.clamp(Math.min(avgHorizPx, avgVertPx) / Math.max(avgHorizPx, avgVertPx, 1), 0.35, 1.0);
        const anguloInclinacionMesa = Math.acos(ratioEscorzo); // 0 = vista cenital, ~0.8 rad = 45° hacia la mesa
        const pitchCamara = THREE.MathUtils.clamp(0.58 - anguloInclinacionMesa * 0.45, 0.18, 0.68);

        const edgeDx = ((tr.x - tl.x) + (br.x - bl.x)) * 0.5;
        const edgeDy = ((tr.y - tl.y) + (br.y - bl.y)) * 0.5;
        const yawQr = Math.atan2(edgeDy, edgeDx);

        const euler = new THREE.Euler(pitchCamara, -yawQr * 0.5, 0, 'YXZ');
        this.quatObjetivo.setFromEuler(euler);
        if (!this.platoColocado) {
            this.quatEstable.copy(this.quatObjetivo);
        } else {
            this.quatEstable.slerp(this.quatObjetivo, 0.22);
        }

        // Aplicar pose al plato y asegurar visibilidad permanente
        if (this.platoRoot) {
            this.platoRoot.position.copy(this.posEstable);
            this.platoRoot.quaternion.copy(this.quatEstable);
            this.platoRoot.visible = true;
        }

        if (!this.platoColocado) {
            this.platoColocado = true;
            if (this.uiBannerGuia) {
                this.uiBannerGuia.style.display = 'none';
            }
        }

        this.qrVisibleAhora = true;
        this.ultimoTiempoQR = performance.now();
        this.actualizarBadgeEstado('tracking');
    }

    colocarPlatoEnCentroPorDefecto() {
        this.posEstable.set(0, -0.05, -0.44);
        const euler = new THREE.Euler(0.42, 0, 0, 'YXZ');
        this.quatEstable.setFromEuler(euler);
        this.filtroPos.reset();

        if (this.platoRoot) {
            this.platoRoot.position.copy(this.posEstable);
            this.platoRoot.quaternion.copy(this.quatEstable);
            this.platoRoot.visible = true;
        }
        this.platoColocado = true;
        if (this.uiBannerGuia) {
            this.uiBannerGuia.style.display = 'none';
        }
        this.actualizarBadgeEstado('locked');
    }

    recentrarEnQR() {
        this.fijadoManual = false;
        this.filtroPos.reset();
        if (this.uiBtnFijar) {
            this.uiBtnFijar.textContent = '🔒 Fijar plato';
            this.uiBtnFijar.classList.remove('is-active');
        }
        // Si el QR no está en encuadre en este instante, centra el plato frente al usuario mientras busca el QR
        if (!this.qrVisibleAhora) {
            this.posEstable.set(0, -0.04, -0.42);
            const euler = new THREE.Euler(0.42, 0, 0, 'YXZ');
            this.quatEstable.setFromEuler(euler);
            if (this.platoRoot) {
                this.platoRoot.position.copy(this.posEstable);
                this.platoRoot.quaternion.copy(this.quatEstable);
                this.platoRoot.visible = true;
            }
            this.platoColocado = true;
            if (this.uiBannerGuia) {
                this.uiBannerGuia.style.display = 'none';
            }
            this.actualizarBadgeEstado('searching-keep');
        }
    }

    alternarFijadoManual() {
        if (!this.platoColocado) {
            this.colocarPlatoEnCentroPorDefecto();
        }
        this.fijadoManual = !this.fijadoManual;
        if (this.uiBtnFijar) {
            this.uiBtnFijar.textContent = this.fijadoManual ? '🔓 Seguir QR' : '🔒 Fijar plato';
            this.uiBtnFijar.classList.toggle('is-active', this.fijadoManual);
        }
        if (this.fijadoManual) {
            this.dibujarEsquinasQR(null);
            this.actualizarBadgeEstado('locked-manual');
        } else {
            this.actualizarBadgeEstado(this.qrVisibleAhora ? 'tracking' : 'locked');
        }
    }

    actualizarBadgeEstado(modo) {
        if (!this.uiStatusDot || !this.uiStatusText) return;
        this.uiStatusDot.className = 'qr-ar-status-dot';
        if (modo === 'tracking') {
            this.uiStatusDot.classList.add('is-tracking');
            this.uiStatusText.textContent = '✅ Anclado al QR de la mesa';
        } else if (modo === 'locked-manual') {
            this.uiStatusDot.classList.add('is-locked');
            this.uiStatusText.textContent = '🔒 Plato fijo (Modo inspección libre)';
        } else if (modo === 'locked') {
            this.uiStatusDot.classList.add('is-locked');
            this.uiStatusText.textContent = '📌 Plato fijo en mesa (Apunta al QR para re-anclar)';
        } else if (modo === 'searching-keep') {
            this.uiStatusDot.classList.add('is-searching');
            this.uiStatusText.textContent = '🎯 Enfoca el QR para re-anclar en tu mesa';
        } else {
            this.uiStatusDot.classList.add('is-searching');
            this.uiStatusText.textContent = '📷 Apunta al QR de la mesa…';
        }
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
        this.fijadoManual = false;
        this.qrVisibleAhora = false;
        this.rotacionUsuarioY = 0;
        this.factorEscalaUsuario = 1.0;
        this.filtroPos.reset();

        this.construirInterfazAR(plato);

        // Solicitar cámara trasera con fallback universal a cualquier cámara disponible
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

        // Cargar el modelo .glb del plato en la escena
        this.cargarModeloNormalizado(plato.modelo_3d, plato).then((modeloGroup) => {
            if (!this.activo || !this.platoPivot) return;
            this.platoMesh = modeloGroup;
            this.platoPivot.add(modeloGroup);
        }).catch(() => {});

        const loop = (nowMs) => {
            if (!this.activo) return;
            this.rafId = requestAnimationFrame(loop);

            // Escanear QR cada ~40 ms (25 fps de visión artificial, 60 fps de renderizado 3D)
            if (!this.fijadoManual && nowMs - this.ultimoEscaneoMs >= 40) {
                this.ultimoEscaneoMs = nowMs;
                const det = this.detectarQREnFrame();
                if (det) {
                    this.actualizarPoseDesdeEsquinasQR(det, nowMs * 0.001);
                } else {
                    if (this.qrVisibleAhora && nowMs - this.ultimoTiempoQR > 380) {
                        this.qrVisibleAhora = false;
                        this.dibujarEsquinasQR(null);
                        if (this.platoColocado) {
                            // CRÍTICO: Si el usuario acerca mucho el celular o el QR sale del encuadre,
                            // el plato NO desaparece: conserva su última pose estable.
                            this.platoRoot.visible = true;
                            this.actualizarBadgeEstado('locked');
                        }
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
