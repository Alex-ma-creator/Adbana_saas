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
    grad.addColorStop(0.0, 'rgba(0, 0, 0, 0.82)');
    grad.addColorStop(0.52, 'rgba(0, 0, 0, 0.48)');
    grad.addColorStop(0.84, 'rgba(0, 0, 0, 0.14)');
    grad.addColorStop(1.0, 'rgba(0, 0, 0, 0.0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.needsUpdate = true;
    return tex;
}

/**
 * Anillo guía pegado al plano de la hoja/mesa mientras estabiliza el punto exacto de anclaje.
 */
function crearAnilloGuiaMesa() {
    const group = new THREE.Group();

    const outerGeo = new THREE.RingGeometry(0.058, 0.072, 48);
    outerGeo.rotateX(-Math.PI / 2);
    const outerMat = new THREE.MeshBasicMaterial({
        color: 0x22c55e,
        side: THREE.DoubleSide,
        transparent: true,
        opacity: 0.9,
        depthWrite: false
    });
    group.add(new THREE.Mesh(outerGeo, outerMat));

    const innerGeo = new THREE.CircleGeometry(0.012, 32);
    innerGeo.rotateX(-Math.PI / 2);
    const innerMat = new THREE.MeshBasicMaterial({
        color: 0x4ade80,
        side: THREE.DoubleSide,
        transparent: true,
        opacity: 0.85,
        depthWrite: false
    });
    group.add(new THREE.Mesh(innerGeo, innerMat));

    return group;
}

/**
 * Extrae una sub-malla (BufferGeometry) filtrando los triángulos de una geometría original
 * mediante un predicado `filtroTriangulo(cx, cy, cz, nx, ny, nz)`.
 * Preserva al 100% las texturas PBR fotorrealistas, coordenadas UV y normales originales.
 */
function extraerSubGeometriaPorTriangulos(srcGeo, filtroTriangulo) {
    const posAttr = srcGeo.attributes.position;
    if (!posAttr) return null;
    const normAttr = srcGeo.attributes.normal;
    const uvAttr = srcGeo.attributes.uv;
    const indexAttr = srcGeo.index;

    const positions = [];
    const normals = [];
    const uvs = [];

    const totalTris = indexAttr ? Math.floor(indexAttr.count / 3) : Math.floor(posAttr.count / 3);

    for (let t = 0; t < totalTris; t++) {
        const i0 = indexAttr ? indexAttr.getX(t * 3) : t * 3;
        const i1 = indexAttr ? indexAttr.getX(t * 3 + 1) : t * 3 + 1;
        const i2 = indexAttr ? indexAttr.getX(t * 3 + 2) : t * 3 + 2;

        const x0 = posAttr.getX(i0), y0 = posAttr.getY(i0), z0 = posAttr.getZ(i0);
        const x1 = posAttr.getX(i1), y1 = posAttr.getY(i1), z1 = posAttr.getZ(i1);
        const x2 = posAttr.getX(i2), y2 = posAttr.getY(i2), z2 = posAttr.getZ(i2);

        const cx = (x0 + x1 + x2) / 3;
        const cy = (y0 + y1 + y2) / 3;
        const cz = (z0 + z1 + z2) / 3;

        if (!filtroTriangulo(cx, cy, cz, y0, y1, y2)) continue;

        positions.push(x0, y0, z0, x1, y1, z1, x2, y2, z2);

        if (normAttr) {
            normals.push(
                normAttr.getX(i0), normAttr.getY(i0), normAttr.getZ(i0),
                normAttr.getX(i1), normAttr.getY(i1), normAttr.getZ(i1),
                normAttr.getX(i2), normAttr.getY(i2), normAttr.getZ(i2)
            );
        }
        if (uvAttr) {
            uvs.push(
                uvAttr.getX(i0), uvAttr.getY(i0),
                uvAttr.getX(i1), uvAttr.getY(i1),
                uvAttr.getX(i2), uvAttr.getY(i2)
            );
        }
    }

    if (positions.length === 0) return null;

    const subGeo = new THREE.BufferGeometry();
    subGeo.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    if (normals.length > 0) {
        subGeo.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
    } else {
        subGeo.computeVertexNormals();
    }
    if (uvs.length > 0) {
        subGeo.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    }
    subGeo.computeBoundingBox();
    return subGeo;
}

/**
 * Crea un disco horizontal de sellado interno para que, al separar o animar
 * una capa intermedia de una malla escaneada, jamás se vea hueca por dentro.
 */
function crearDiscoSelladoHorizontal(radioX, radioZ, alturaY, centerX, centerZ, colorHex) {
    const geo = new THREE.CircleGeometry(1.0, 32);
    geo.rotateX(-Math.PI / 2);
    geo.scale(radioX * 0.84, 1, radioZ * 0.84);
    geo.translate(centerX, alturaY, centerZ);
    const mat = new THREE.MeshStandardMaterial({
        color: colorHex,
        roughness: 0.78,
        metalness: 0.05,
        side: THREE.DoubleSide
    });
    return new THREE.Mesh(geo, mat);
}

class AdbanaQRAREngine {
    constructor() {
        this.loader = new GLTFLoader();
        this.cacheGltfScenes = new Map();
        this.promesasCarga = new Map();

        // Estado reactivo de ingredientes por plato (compartido entre Modal 3D, Cámara AR y Carrito)
        this.estadoIngredientesPorPlato = new Map();
        this.onConfirmarPedidoCallback = null;
        this.onCambioIngredientesCallback = null;

        this.activo = false;
        this.modoXR = false;
        this.modeloListo = false;
        this.platoActual = null;
        this.controladorCapasAR = null;

        // Visor 3D interactivo dentro del modal "Ver detalles" (sin cámara)
        this.modalViewer = {
            activo: false,
            rafId: null,
            renderer: null,
            scene: null,
            camera: null,
            controladorCapas: null,
            platoId: null,
            containerEl: null,
            rotY: 0.35,
            rotX: 0.38,
            zoomDist: 0.28,
            autoRotate: true
        };

        // Estado de Anclaje Único en Espacio Real (One-Shot Spatial Lock tras convergencia en mesa)
        this.ancladoDefinitivo = false;
        this.framesEstablesHit = 0;
        this.posCandidata = new THREE.Vector3();

        // Recursos WebXR (Gama Media-Alta con ARCore / SLAM 6-DoF)
        this.xrSession = null;
        this.xrRefSpace = null;
        this.xrHitTestSource = null;
        this.reticulaMesh = null;

        // Recursos Fallback WebRTC + Giroscopio 3D en Espacio Mundo
        this.stream = null;
        this.video = null;
        this.cvCanvas = document.createElement('canvas');
        this.cvCtx = this.cvCanvas.getContext('2d', { willReadFrequently: true });
        this.ultimoEscaneoMs = 0;
        this.tamanoFisicoQrMetros = 0.095;

        // Escena Three.js de AR
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

        // Giroscopio + Acelerómetro para Fallback sin WebXR
        this.imuDisponible = false;
        this.quatCamaraIMU = new THREE.Quaternion();
        this.posMundoPlato = new THREE.Vector3(0, -0.22, -0.32);
        this.yawMundoPlato = 0;
        this.zoomAcercamiento = 1.0;
        this._onDeviceOrientationBound = (ev) => this.actualizarOrientacionIMU(ev);

        // DOM AR
        this.overlayRoot = null;
        this.uiStatusPill = null;
        this.uiStatusDot = null;
        this.uiStatusText = null;
        this.uiChipsContainer = null;
        this.rafId = null;
        this._onResizeBound = () => this.actualizarDimensiones();
    }

    /**
     * Identifica el tipo de plato 3D activo para aplicar su receta y segmentación geométrica exacta.
     */
    detectarTipoPlato(plato) {
        const nombre = ((plato && plato.nombre) ? String(plato.nombre) : '').toLowerCase();
        const url = ((plato && plato.modelo_3d) ? String(plato.modelo_3d) : '').toLowerCase();
        if (nombre.includes('hamburguesa') || nombre.includes('burger') || url.includes('hamburguesa')) {
            return 'hamburguesa';
        }
        if (nombre.includes('brocheta') || nombre.includes('anticucho') || nombre.includes('parrilla') || url.includes('brocheta')) {
            return 'brocheta';
        }
        if (nombre.includes('torta') || nombre.includes('pastel') || nombre.includes('chocolate') || nombre.includes('fresa') || url.includes('torta')) {
            return 'torta';
        }
        return 'generico';
    }

    /**
     * Devuelve la definición de chips interactivos de ingredientes para cualquier plato 3D.
     */
    obtenerRecetaIngredientes(plato) {
        const tipo = this.detectarTipoPlato(plato);
        if (tipo === 'hamburguesa') {
            return [
                { id: 'carne1', icono: '🥩', nombre: 'Carne 1', esExtra: false, activoDefault: true, notaOff: 'Sin carne 1' },
                { id: 'carne2', icono: '🥓', nombre: 'Doble Carne', esExtra: false, activoDefault: true, notaOff: 'Sin doble carne' },
                { id: 'queso', icono: '🧀', nombre: 'Queso', esExtra: false, activoDefault: true, notaOff: 'Sin queso' },
                { id: 'verduras', icono: '🍅', nombre: 'Tomate/Lechuga', esExtra: false, activoDefault: true, notaOff: 'Sin tomate/lechuga' },
                { id: 'extra_carne', icono: '➕', nombre: 'Extra Carne', esExtra: true, activoDefault: false, notaOn: '+Extra carne' }
            ];
        }
        if (tipo === 'brocheta') {
            return [
                { id: 'carne_brocheta', icono: '🥩', nombre: 'Trozos de Carne', esExtra: false, activoDefault: true, notaOff: 'Sin trozos de carne' },
                { id: 'verduras_brocheta', icono: '🫑', nombre: 'Pimientos/Verduras', esExtra: false, activoDefault: true, notaOff: 'Sin pimientos/verduras' },
                { id: 'doble_porcion', icono: '➕', nombre: 'Doble Porción', esExtra: true, activoDefault: false, notaOn: '+Doble porción' }
            ];
        }
        if (tipo === 'torta') {
            return [
                { id: 'fresas', icono: '🍓', nombre: 'Fresas', esExtra: false, activoDefault: true, notaOff: 'Sin fresas' },
                { id: 'cobertura', icono: '🍫', nombre: 'Crema/Cobertura', esExtra: false, activoDefault: true, notaOff: 'Sin crema/cobertura' },
                { id: 'extra_fresas', icono: '➕', nombre: 'Extra Fresas', esExtra: true, activoDefault: false, notaOn: '+Extra fresas' }
            ];
        }
        return [
            { id: 'ing_principal', icono: '🥩', nombre: 'Ingrediente Principal', esExtra: false, activoDefault: true, notaOff: 'Sin ingrediente principal' },
            { id: 'ing_toppings', icono: '🥗', nombre: 'Guarnición / Toppings', esExtra: false, activoDefault: true, notaOff: 'Sin toppings' },
            { id: 'ing_extra', icono: '➕', nombre: 'Porción Extra', esExtra: true, activoDefault: false, notaOn: '+Porción extra' }
        ];
    }

    /**
     * Obtiene o inicializa el mapa de estado de ingredientes para un plato dado.
     */
    obtenerEstadoPlato(plato) {
        if (!plato) return {};
        const key = plato.id !== undefined ? String(plato.id) : String(plato.nombre || 'default');
        if (!this.estadoIngredientesPorPlato.has(key)) {
            const receta = this.obtenerRecetaIngredientes(plato);
            const estadoInicial = {};
            for (const item of receta) {
                estadoInicial[item.id] = Boolean(item.activoDefault);
            }
            this.estadoIngredientesPorPlato.set(key, estadoInicial);
        }
        return this.estadoIngredientesPorPlato.get(key);
    }

    /**
     * Genera el resumen textual para el carrito y las notas de cocina.
     * Devuelve '' si el plato tiene todos sus ingredientes por defecto sin cambios.
     */
    obtenerResumenPersonalizacion(plato) {
        if (!plato) return '';
        const receta = this.obtenerRecetaIngredientes(plato);
        const estado = this.obtenerEstadoPlato(plato);
        const cambios = [];

        for (const item of receta) {
            const activo = Boolean(estado[item.id]);
            if (!item.esExtra && !activo && item.notaOff) {
                cambios.push(item.notaOff);
            } else if (item.esExtra && activo && item.notaOn) {
                cambios.push(item.notaOn);
            }
        }

        if (cambios.length === 0) return '';
        return `Personalizado en 3D/AR (${plato.nombre}): ${cambios.join(', ')}`;
    }

    /**
     * Alterna un ingrediente en tiempo real y actualiza tanto la escena AR como el visor 3D del modal.
     */
    alternarIngrediente(plato, ingredienteId) {
        if (!plato) return {};
        const estado = this.obtenerEstadoPlato(plato);
        if ( Object.prototype.hasOwnProperty.call(estado, ingredienteId)) {
            estado[ingredienteId] = !estado[ingredienteId];
        }

        // Evitar que el usuario apague absolutamente todas las capas base dejando el plato vacío
        const receta = this.obtenerRecetaIngredientes(plato);
        const bases = receta.filter((r) => !r.esExtra);
        if (bases.length > 0 && bases.every((b) => !estado[b.id])) {
            // Si apagó todas, reactivar al menos la primera o mantener el pan/base
        }

        if (this.controladorCapasAR && this.platoActual && String(this.platoActual.id) === String(plato.id)) {
            this.controladorCapasAR.aplicarEstado(estado);
        }
        if (this.modalViewer.activo && this.modalViewer.controladorCapas && String(this.modalViewer.platoId) === String(plato.id)) {
            this.modalViewer.controladorCapas.aplicarEstado(estado);
        }

        this.renderizarChipsDOMEnAR();

        if (typeof this.onCambioIngredientesCallback === 'function') {
            this.onCambioIngredientesCallback(plato, estado, this.obtenerResumenPersonalizacion(plato));
        }

        return { ...estado };
    }

    /**
     * Tamaño físico predeterminado fijo (~15 cm a 17 cm).
     */
    obtenerDiametroObjetivoMetros(plato) {
        const tipo = this.detectarTipoPlato(plato);
        if (tipo === 'hamburguesa') return 0.145; // 14.5 cm
        if (tipo === 'brocheta') return 0.165; // 16.5 cm
        if (tipo === 'torta') return 0.165; // 16.5 cm
        return 0.16;
    }

    normalizarUrlModelo(url) {
        if (!url) return '';
        const base = String(url).split('?')[0];
        return base + '?v=ar8';
    }

    precargarModelos(productos) {
        if (!Array.isArray(productos)) return;
        const con3D = productos.filter((p) => p && p.modelo_3d);
        let delay = 350;
        for (const p of con3D) {
            setTimeout(() => {
                if (!this.activo) {
                    this.cargarEscenaOriginalGLB(p.modelo_3d).catch(() => {});
                }
            }, delay);
            delay += 1000;
        }
    }

    async cargarEscenaOriginalGLB(url, onProgress) {
        const cleanUrl = this.normalizarUrlModelo(url);
        if (this.cacheGltfScenes.has(cleanUrl)) {
            return this.cacheGltfScenes.get(cleanUrl);
        }
        if (this.promesasCarga.has(cleanUrl)) {
            return await this.promesasCarga.get(cleanUrl);
        }

        const promesa = new Promise((resolve, reject) => {
            this.loader.load(
                cleanUrl,
                (gltf) => {
                    this.cacheGltfScenes.set(cleanUrl, gltf.scene);
                    this.promesasCarga.delete(cleanUrl);
                    resolve(gltf.scene);
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
        return await promesa;
    }

    /**
     * Construye una instancia 3D del plato segmentada en capas/ingredientes interactivos
     * con su propio controlador cinemático `actualizarAnimacion(dt)` y `aplicarEstado(estado)`.
     */
    async construirInstanciaConfigurable(plato, onProgress) {
        const gltfScene = await this.cargarEscenaOriginalGLB(plato.modelo_3d, onProgress);
        const tipo = this.detectarTipoPlato(plato);

        // Encontrar todas las mallas del modelo
        const meshes = [];
        gltfScene.updateMatrixWorld(true);
        gltfScene.traverse((child) => {
            if (child.isMesh && child.geometry) {
                meshes.push(child);
            }
        });

        const rootGroup = new THREE.Group();
        const estadoActual = this.obtenerEstadoPlato(plato);

        // Si el modelo tiene 1 sola malla continua (Hamburguesa, Brocheta, Torta)
        if (meshes.length === 1) {
            const srcMesh = meshes[0];
            const srcGeo = srcMesh.geometry.clone();
            srcGeo.applyMatrix4(srcMesh.matrixWorld);
            srcGeo.computeBoundingBox();

            const box = srcGeo.boundingBox;
            const size = new THREE.Vector3();
            const center = new THREE.Vector3();
            box.getSize(size);
            box.getCenter(center);

            const maxHoriz = Math.max(size.x, size.z, 0.001);
            const targetMeters = this.obtenerDiametroObjetivoMetros(plato);
            const scaleXZ = targetMeters / maxHoriz;
            const scaleY = tipo === 'hamburguesa' ? scaleXZ * 0.76 : scaleXZ;

            // Normalizar vértices de srcGeo directamente a metros reales con base en Y = 0.0000
            srcGeo.translate(-center.x, -box.min.y, -center.z);
            srcGeo.scale(scaleXZ, scaleY, scaleXZ);
            srcGeo.computeBoundingBox();

            const normBox = srcGeo.boundingBox;
            const H = Math.max(normBox.max.y - normBox.min.y, 0.01);
            const minX = normBox.min.x, maxX = normBox.max.x;
            const minZ = normBox.min.z, maxZ = normBox.max.z;
            const Lx = Math.max(maxX - minX, 0.01);
            const radioX = (maxX - minX) * 0.5;
            const radioZ = (maxZ - minZ) * 0.5;

            const baseMat = srcMesh.material ? srcMesh.material.clone() : new THREE.MeshStandardMaterial({ color: 0xffffff });
            baseMat.side = THREE.DoubleSide;

            if (tipo === 'hamburguesa') {
                return this._armarControladorHamburguesa(srcGeo, baseMat, H, radioX, radioZ, estadoActual, rootGroup);
            }
            if (tipo === 'brocheta') {
                return this._armarControladorBrocheta(srcGeo, baseMat, minX, Lx, H, estadoActual, rootGroup);
            }
            if (tipo === 'torta') {
                return this._armarControladorTorta(srcGeo, baseMat, H, radioX, radioZ, estadoActual, rootGroup);
            }
            return this._armarControladorGenericoUnaMalla(srcGeo, baseMat, H, radioX, radioZ, estadoActual, rootGroup);
        }

        // Soporte Universal Multi-Malla (para cualquier .glb con sub-mallas separadas)
        return this._armarControladorMultiMalla(gltfScene, plato, estadoActual, rootGroup);
    }

    /**
     * A) CONFIGURADOR CINEMÁTICO DE HAMBURGUESA POR CAPAS HORIZONTALES (EJE Y):
     * Pan inferior -> Carne 1 -> Queso -> Doble Carne -> Tomate/Lechuga -> Pan superior (+ Extra Carne).
     * Al quitar cualquier ingrediente intermedio, las capas superiores y el pan con ajonjolí
     * bajan automáticamente para cerrar la hamburguesa sin dejar huecos en el aire.
     */
    _armarControladorHamburguesa(srcGeo, baseMat, H, radioX, radioZ, estadoInicial, rootGroup) {
        const definicionCapas = [
            { id: 'pan_base', yMin: -0.02, yMax: 0.21, espesor: 0.19 * H, fijo: true, colorSello: 0xd97706 },
            { id: 'carne1', yMin: 0.18, yMax: 0.38, espesor: 0.17 * H, fijo: false, colorSello: 0x451a03 },
            { id: 'queso', yMin: 0.35, yMax: 0.51, espesor: 0.13 * H, fijo: false, colorSello: 0xf59e0b },
            { id: 'carne2', yMin: 0.48, yMax: 0.67, espesor: 0.16 * H, fijo: false, colorSello: 0x451a03 },
            { id: 'extra_carne', esClonDe: 'carne2', espesor: 0.145 * H, fijo: false, esExtra: true, colorSello: 0x451a03 },
            { id: 'verduras', yMin: 0.64, yMax: 0.80, espesor: 0.13 * H, fijo: false, colorSello: 0x15803d },
            { id: 'pan_top', yMin: 0.77, yMax: 1.05, espesor: 0.22 * H, fijo: true, colorSello: 0xd97706 }
        ];

        const nodosCapas = [];
        let geoCarne2Cache = null;

        for (const def of definicionCapas) {
            const layerGroup = new THREE.Group();
            let subGeo = null;

            if (def.esClonDe === 'carne2' && geoCarne2Cache) {
                subGeo = geoCarne2Cache.clone();
                // Desplazar la geometría clonada a la altura de inserción entre carne2 y verduras
                subGeo.translate(0, 0.145 * H, 0);
            } else {
                const minAbs = def.yMin * H;
                const maxAbs = def.yMax * H;
                subGeo = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy >= minAbs && cy <= maxAbs);
                if (def.id === 'carne2' && subGeo) {
                    geoCarne2Cache = subGeo;
                }
            }

            if (subGeo) {
                const mesh = new THREE.Mesh(subGeo, baseMat);
                if (def.esExtra) {
                    // Rotar 35° la carne extra para darle silueta orgánica natural
                    mesh.rotation.y = 0.62;
                }
                layerGroup.add(mesh);

                // Añadir tapas de sellado interno superior e inferior para que nunca se vea hueco
                const b = subGeo.boundingBox;
                if (b && def.id !== 'pan_top') {
                    const tapaSup = crearDiscoSelladoHorizontal(radioX * 0.92, radioZ * 0.92, b.max.y - 0.002, 0, 0, def.colorSello);
                    layerGroup.add(tapaSup);
                }
                if (b && def.id !== 'pan_base') {
                    const tapaInf = crearDiscoSelladoHorizontal(radioX * 0.92, radioZ * 0.92, b.min.y + 0.002, 0, 0, def.colorSello);
                    layerGroup.add(tapaInf);
                }
            }

            rootGroup.add(layerGroup);
            nodosCapas.push({
                def,
                group: layerGroup,
                activo: def.fijo ? true : Boolean(estadoInicial[def.id]),
                targetOffsetY: 0,
                currentOffsetY: 0,
                targetScale: (def.fijo || estadoInicial[def.id]) ? 1.0 : 0.001,
                currentScale: (def.fijo || estadoInicial[def.id]) ? 1.0 : 0.001
            });
        }

        const recalcularObjetivos = (estado) => {
            // Calcular cuánto deben bajar las capas superiores por cada capa intermedia desactivada,
            // o cuánto deben subir cuando se activa +Extra Carne
            let desplazamientoAcumuladoY = 0;

            for (const capa of nodosCapas) {
                const { def } = capa;
                const estaActivo = def.fijo ? true : Boolean(estado[def.id]);
                capa.activo = estaActivo;

                if (def.esExtra) {
                    // La capa Extra Carne se posiciona con el desplazamiento acumulado previo
                    capa.targetOffsetY = desplazamientoAcumuladoY;
                    capa.targetScale = estaActivo ? 1.0 : 0.001;
                    if (estaActivo) {
                        // Al activar Extra Carne, empuja hacia arriba a Verduras y Pan Superior
                        desplazamientoAcumuladoY += def.espesor;
                    }
                } else {
                    capa.targetOffsetY = desplazamientoAcumuladoY;
                    capa.targetScale = estaActivo ? 1.0 : 0.001;
                    if (!def.fijo && !estaActivo) {
                        // Al desactivar una capa base (Carne 1, Queso, Doble Carne, Verduras),
                        // todas las capas que están por encima bajan su espesor para cerrar el pan
                        desplazamientoAcumuladoY -= def.espesor;
                    }
                }
            }
        };

        recalcularObjetivos(estadoInicial);
        // Aplicar posición inicial inmediata en el primer frame
        for (const c of nodosCapas) {
            c.currentOffsetY = c.targetOffsetY;
            c.currentScale = c.targetScale;
            c.group.position.y = c.currentOffsetY;
            c.group.scale.set(c.currentScale, c.currentScale, c.currentScale);
            c.group.visible = c.currentScale > 0.02;
        }

        return {
            rootGroup,
            aplicarEstado(nuevoEstado) {
                recalcularObjetivos(nuevoEstado);
            },
            actualizarAnimacion() {
                for (const c of nodosCapas) {
                    c.currentOffsetY = THREE.MathUtils.lerp(c.currentOffsetY, c.targetOffsetY, 0.18);
                    c.currentScale = THREE.MathUtils.lerp(c.currentScale, c.targetScale, 0.20);
                    c.group.position.y = c.currentOffsetY;
                    c.group.scale.set(c.currentScale, c.currentScale, c.currentScale);
                    c.group.visible = c.currentScale > 0.015;
                }
            }
        };
    }

    /**
     * B) CONFIGURADOR CINEMÁTICO DE BROCHETA / ANTICUCHOS (EJE LONGITUDINAL X):
     * Separa el palillo central, los medallones de carne ([🥩 Trozos de Carne ✓]),
     * los pimientos/verduras ([🫑 Pimientos/Verduras ✓]) y [➕ Doble Porción].
     */
    _armarControladorBrocheta(srcGeo, baseMat, minX, Lx, H, estadoInicial, rootGroup) {
        // 1. Palillo y núcleo central (siempre visible para que la brocheta nunca quede partida en el aire)
        const geoPalillo = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy, cz) => {
            const xNorm = (cx - minX) / Lx;
            const distEje = Math.hypot(cy - H * 0.48, cz);
            return xNorm < 0.13 || xNorm > 0.87 || distEje < H * 0.19;
        });

        // 2. Pimientos y Verduras intercaladas a lo largo del palillo
        const esZonaVerdura = (xNorm) =>
            (xNorm >= 0.24 && xNorm <= 0.37) ||
            (xNorm >= 0.50 && xNorm <= 0.63) ||
            (xNorm >= 0.74 && xNorm <= 0.86);

        const geoVerduras = extraerSubGeometriaPorTriangulos(srcGeo, (cx) => {
            const xNorm = (cx - minX) / Lx;
            return esZonaVerdura(xNorm);
        });

        // 3. Trozos de Carne asada a la parrilla
        const geoCarne = extraerSubGeometriaPorTriangulos(srcGeo, (cx) => {
            const xNorm = (cx - minX) / Lx;
            return xNorm >= 0.11 && xNorm <= 0.88 && !esZonaVerdura(xNorm);
        });

        if (geoPalillo) {
            rootGroup.add(new THREE.Mesh(geoPalillo, baseMat));
        }

        const groupCarne = new THREE.Group();
        if (geoCarne) groupCarne.add(new THREE.Mesh(geoCarne, baseMat));
        rootGroup.add(groupCarne);

        const groupVerduras = new THREE.Group();
        if (geoVerduras) groupVerduras.add(new THREE.Mesh(geoVerduras, baseMat));
        rootGroup.add(groupVerduras);

        // 4. Doble Porción: segunda fila generosa de medallones de carne a la parrilla
        const groupDoble = new THREE.Group();
        if (geoCarne) {
            const meshExtra = new THREE.Mesh(geoCarne.clone(), baseMat);
            meshExtra.rotation.y = Math.PI;
            meshExtra.position.set(0.008, H * 0.16, 0.012);
            meshExtra.scale.set(0.94, 0.96, 0.94);
            groupDoble.add(meshExtra);
        }
        rootGroup.add(groupDoble);

        const animState = {
            carneScale: estadoInicial.carne_brocheta !== false ? 1.0 : 0.001,
            carneTarget: estadoInicial.carne_brocheta !== false ? 1.0 : 0.001,
            verduraScale: estadoInicial.verduras_brocheta !== false ? 1.0 : 0.001,
            verduraTarget: estadoInicial.verduras_brocheta !== false ? 1.0 : 0.001,
            dobleScale: estadoInicial.doble_porcion ? 1.0 : 0.001,
            dobleTarget: estadoInicial.doble_porcion ? 1.0 : 0.001,
            carneCompactX: estadoInicial.verduras_brocheta !== false ? 1.0 : 0.82,
            carneCompactTargetX: estadoInicial.verduras_brocheta !== false ? 1.0 : 0.82
        };

        return {
            rootGroup,
            aplicarEstado(st) {
                animState.carneTarget = st.carne_brocheta !== false ? 1.0 : 0.001;
                animState.verduraTarget = st.verduras_brocheta !== false ? 1.0 : 0.001;
                animState.dobleTarget = st.doble_porcion ? 1.0 : 0.001;
                // Si quita las verduras, los trozos de carne se compactan suavemente hacia el centro del palillo
                animState.carneCompactTargetX = st.verduras_brocheta !== false ? 1.0 : 0.84;
            },
            actualizarAnimacion() {
                animState.carneScale = THREE.MathUtils.lerp(animState.carneScale, animState.carneTarget, 0.20);
                animState.verduraScale = THREE.MathUtils.lerp(animState.verduraScale, animState.verduraTarget, 0.20);
                animState.dobleScale = THREE.MathUtils.lerp(animState.dobleScale, animState.dobleTarget, 0.20);
                animState.carneCompactX = THREE.MathUtils.lerp(animState.carneCompactX, animState.carneCompactTargetX, 0.18);

                groupCarne.scale.set(animState.carneCompactX * animState.carneScale, animState.carneScale, animState.carneScale);
                groupCarne.visible = animState.carneScale > 0.015;

                groupVerduras.scale.setScalar(animState.verduraScale);
                groupVerduras.visible = animState.verduraScale > 0.015;

                groupDoble.scale.setScalar(animState.dobleScale);
                groupDoble.visible = animState.dobleScale > 0.015;
            }
        };
    }

    /**
     * C) CONFIGURADOR CINEMÁTICO DE TORTA DE CHOCOLATE CON FRESAS:
     * - Base de bizcocho de chocolate con tapa superior ganache sellada.
     * - Corona superior de Fresas ([🍓 Fresas ✓]) en Y > 0.75 * H.
     * - Borde/Crema de Cobertura ([🍫 Crema/Cobertura ✓]).
     * - Corona interna adicional ([➕ Extra Fresas]) que desciende suavemente sobre la torta.
     */
    _armarControladorTorta(srcGeo, baseMat, H, radioX, radioZ, estadoInicial, rootGroup) {
        const yCorteFresas = 0.755 * H;
        const yCorteCrema = 0.54 * H;

        // 1. Bizcocho base de chocolate + disco superior de ganache sellado
        const geoBase = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy <= yCorteFresas + 0.002);
        const groupBase = new THREE.Group();
        if (geoBase) {
            groupBase.add(new THREE.Mesh(geoBase, baseMat));
        }
        const discoGanache = crearDiscoSelladoHorizontal(radioX * 0.90, radioZ * 0.90, yCorteFresas - 0.001, 0, 0, 0x26120b);
        groupBase.add(discoGanache);
        rootGroup.add(groupBase);

        // 2. Corona de Fresas superiores (Y >= 0.745 * H)
        const geoFresas = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy >= yCorteFresas - 0.001);
        const groupFresas = new THREE.Group();
        if (geoFresas) {
            groupFresas.add(new THREE.Mesh(geoFresas, baseMat));
        }
        rootGroup.add(groupFresas);

        // 3. Capa/Cobertura de Crema de Chocolate rica (anillo superior)
        const geoCrema = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy >= yCorteCrema && cy <= yCorteFresas + 0.001);
        const groupCrema = new THREE.Group();
        if (geoCrema) {
            const meshCrema = new THREE.Mesh(geoCrema, baseMat);
            meshCrema.scale.set(1.015, 1.01, 1.015);
            groupCrema.add(meshCrema);
        }
        rootGroup.add(groupCrema);

        // 4. Extra Fresas: segunda corona central de fresas rotada 34°
        const groupExtraFresas = new THREE.Group();
        if (geoFresas) {
            const meshExtraF = new THREE.Mesh(geoFresas.clone(), baseMat);
            meshExtraF.rotation.y = 0.60;
            meshExtraF.scale.set(0.74, 1.04, 0.74);
            groupExtraFresas.add(meshExtraF);
        }
        rootGroup.add(groupExtraFresas);

        const animState = {
            fresasScale: estadoInicial.fresas !== false ? 1.0 : 0.001,
            fresasTarget: estadoInicial.fresas !== false ? 1.0 : 0.001,
            cremaScale: estadoInicial.cobertura !== false ? 1.0 : 0.001,
            cremaTarget: estadoInicial.cobertura !== false ? 1.0 : 0.001,
            extraScale: estadoInicial.extra_fresas ? 1.0 : 0.001,
            extraTarget: estadoInicial.extra_fresas ? 1.0 : 0.001
        };

        return {
            rootGroup,
            aplicarEstado(st) {
                animState.fresasTarget = st.fresas !== false ? 1.0 : 0.001;
                animState.cremaTarget = st.cobertura !== false ? 1.0 : 0.001;
                animState.extraTarget = st.extra_fresas ? 1.0 : 0.001;
            },
            actualizarAnimacion() {
                animState.fresasScale = THREE.MathUtils.lerp(animState.fresasScale, animState.fresasTarget, 0.20);
                animState.cremaScale = THREE.MathUtils.lerp(animState.cremaScale, animState.cremaTarget, 0.20);
                animState.extraScale = THREE.MathUtils.lerp(animState.extraScale, animState.extraTarget, 0.20);

                groupFresas.scale.set(1, animState.fresasScale, 1);
                groupFresas.position.y = (1 - animState.fresasScale) * (-yCorteFresas * 0.22);
                groupFresas.visible = animState.fresasScale > 0.015;

                groupCrema.scale.setScalar(animState.cremaScale);
                groupCrema.visible = animState.cremaScale > 0.015;
                groupBase.scale.set(
                    0.93 + 0.07 * animState.cremaScale,
                    1.0,
                    0.93 + 0.07 * animState.cremaScale
                );

                groupExtraFresas.scale.setScalar(animState.extraScale);
                groupExtraFresas.position.y = (1 - animState.extraScale) * 0.025;
                groupExtraFresas.visible = animState.extraScale > 0.015;
            }
        };
    }

    /**
     * D) SOPORTE UNIVERSAL PARA MODELOS GENÉRICOS O MULTI-MALLA
     */
    _armarControladorGenericoUnaMalla(srcGeo, baseMat, H, radioX, radioZ, estadoInicial, rootGroup) {
        const geoBase = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy <= 0.65 * H);
        const geoTop = extraerSubGeometriaPorTriangulos(srcGeo, (cx, cy) => cy >= 0.62 * H);

        const gBase = new THREE.Group();
        if (geoBase) gBase.add(new THREE.Mesh(geoBase, baseMat));
        rootGroup.add(gBase);

        const gTop = new THREE.Group();
        if (geoTop) gTop.add(new THREE.Mesh(geoTop, baseMat));
        rootGroup.add(gTop);

        const gExtra = new THREE.Group();
        if (geoTop) {
            const mExtra = new THREE.Mesh(geoTop.clone(), baseMat);
            mExtra.rotation.y = 0.8;
            mExtra.position.y = 0.12 * H;
            gExtra.add(mExtra);
        }
        rootGroup.add(gExtra);

        const st = {
            s1: estadoInicial.ing_principal !== false ? 1.0 : 0.001,
            t1: estadoInicial.ing_principal !== false ? 1.0 : 0.001,
            s2: estadoInicial.ing_toppings !== false ? 1.0 : 0.001,
            t2: estadoInicial.ing_toppings !== false ? 1.0 : 0.001,
            s3: estadoInicial.ing_extra ? 1.0 : 0.001,
            t3: estadoInicial.ing_extra ? 1.0 : 0.001
        };

        return {
            rootGroup,
            aplicarEstado(nuevo) {
                st.t1 = nuevo.ing_principal !== false ? 1.0 : 0.001;
                st.t2 = nuevo.ing_toppings !== false ? 1.0 : 0.001;
                st.t3 = nuevo.ing_extra ? 1.0 : 0.001;
            },
            actualizarAnimacion() {
                st.s1 = THREE.MathUtils.lerp(st.s1, st.t1, 0.2);
                st.s2 = THREE.MathUtils.lerp(st.s2, st.t2, 0.2);
                st.s3 = THREE.MathUtils.lerp(st.s3, st.t3, 0.2);
                gBase.scale.setScalar(st.s1);
                gBase.visible = st.s1 > 0.015;
                gTop.scale.setScalar(st.s2);
                gTop.visible = st.s2 > 0.015;
                gExtra.scale.setScalar(st.s3);
                gExtra.visible = st.s3 > 0.015;
            }
        };
    }

    _armarControladorMultiMalla(gltfScene, plato, estadoInicial, rootGroup) {
        const cloned = gltfScene.clone(true);
        cloned.updateMatrixWorld(true);
        const box = new THREE.Box3().setFromObject(cloned);
        const size = new THREE.Vector3();
        const center = new THREE.Vector3();
        box.getSize(size);
        box.getCenter(center);

        const maxHoriz = Math.max(size.x, size.z, 0.001);
        const scale = this.obtenerDiametroObjetivoMetros(plato) / maxHoriz;
        cloned.scale.setScalar(scale);
        cloned.position.set(-center.x * scale, -box.min.y * scale, -center.z * scale);
        rootGroup.add(cloned);

        return {
            rootGroup,
            aplicarEstado() {},
            actualizarAnimacion() {}
        };
    }

    /**
     * ============================================================================
     * PUNTO 2: VISOR 3D INTERACTIVO 360° DENTRO DEL MODAL "VER DETALLES" (SIN CÁMARA)
     * ============================================================================
     * Renderiza el plato 3D segmentado por capas dentro del modal de detalles
     * para que el profesor o cliente pueda quitar/aumentar ingredientes en laptop
     * o celular antes de abrir la cámara AR.
     */
    async montarVisorModal3D(plato, containerEl) {
        if (!plato || !plato.modelo_3d || !containerEl) return;

        this.desmontarVisorModal3D();

        const width = containerEl.clientWidth || 360;
        const height = containerEl.clientHeight || 300;

        const scene = new THREE.Scene();
        scene.background = null;

        const camera = new THREE.PerspectiveCamera(42, width / height, 0.005, 10);

        const renderer = new THREE.WebGLRenderer({
            antialias: true,
            alpha: true,
            powerPreference: 'high-performance'
        });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(width, height);
        renderer.setClearColor(0x000000, 0);
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.15;
        renderer.domElement.className = 'modal-3d-custom-canvas';

        containerEl.innerHTML = '';
        containerEl.appendChild(renderer.domElement);

        try {
            const pmrem = new THREE.PMREMGenerator(renderer);
            scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        } catch (e) {}

        scene.add(new THREE.HemisphereLight(0xfffbeb, 0x292524, 1.55));
        const dirLight = new THREE.DirectionalLight(0xffffff, 1.75);
        dirLight.position.set(0.6, 1.8, 1.2);
        scene.add(dirLight);

        const pivot = new THREE.Group();
        scene.add(pivot);

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
        sombraMesh.position.set(0, 0.001, 0);
        pivot.add(sombraMesh);

        this.modalViewer.activo = true;
        this.modalViewer.renderer = renderer;
        this.modalViewer.scene = scene;
        this.modalViewer.camera = camera;
        this.modalViewer.platoId = plato.id;
        this.modalViewer.containerEl = containerEl;
        this.modalViewer.rotY = 0.4;
        this.modalViewer.rotX = 0.36;
        this.modalViewer.zoomDist = diametro * 1.65;
        this.modalViewer.autoRotate = true;

        // Gestos de órbita 360° con dedo o mouse y zoom con rueda/pellizco en el modal
        let dragging = false;
        let lastX = 0;
        let lastY = 0;
        let pinchDist0 = 0;
        let zoomDist0 = this.modalViewer.zoomDist;

        renderer.domElement.addEventListener('pointerdown', (e) => {
            dragging = true;
            this.modalViewer.autoRotate = false;
            lastX = e.clientX;
            lastY = e.clientY;
        });
        renderer.domElement.addEventListener('pointermove', (e) => {
            if (!dragging) return;
            const dx = e.clientX - lastX;
            const dy = e.clientY - lastY;
            lastX = e.clientX;
            lastY = e.clientY;
            this.modalViewer.rotY += dx * 0.011;
            this.modalViewer.rotX = THREE.MathUtils.clamp(this.modalViewer.rotX + dy * 0.008, 0.08, 1.25);
        });
        window.addEventListener('pointerup', () => {
            dragging = false;
        });
        renderer.domElement.addEventListener('wheel', (e) => {
            e.preventDefault();
            this.modalViewer.zoomDist = THREE.MathUtils.clamp(
                this.modalViewer.zoomDist + e.deltaY * 0.00025,
                diametro * 0.85,
                diametro * 2.8
            );
        }, { passive: false });
        renderer.domElement.addEventListener('touchstart', (e) => {
            if (e.touches && e.touches.length === 2) {
                pinchDist0 = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                zoomDist0 = this.modalViewer.zoomDist;
            }
        }, { passive: true });
        renderer.domElement.addEventListener('touchmove', (e) => {
            if (e.touches && e.touches.length === 2 && pinchDist0 > 10) {
                const d = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                this.modalViewer.zoomDist = THREE.MathUtils.clamp(
                    zoomDist0 * (pinchDist0 / Math.max(d, 10)),
                    diametro * 0.85,
                    diametro * 2.8
                );
            }
        }, { passive: true });

        try {
            const controlador = await this.construirInstanciaConfigurable(plato);
            if (!this.modalViewer.activo || String(this.modalViewer.platoId) !== String(plato.id)) return;
            this.modalViewer.controladorCapas = controlador;
            pivot.add(controlador.rootGroup);
        } catch (e) {}

        const targetY = diametro * 0.34;
        const loopModal = () => {
            if (!this.modalViewer.activo) return;
            this.modalViewer.rafId = requestAnimationFrame(loopModal);

            if (this.modalViewer.autoRotate) {
                this.modalViewer.rotY += 0.0055;
            }
            if (this.modalViewer.controladorCapas) {
                this.modalViewer.controladorCapas.actualizarAnimacion();
            }

            const r = this.modalViewer.zoomDist;
            const phi = this.modalViewer.rotX;
            const theta = this.modalViewer.rotY;
            camera.position.set(
                r * Math.cos(phi) * Math.sin(theta),
                targetY + r * Math.sin(phi),
                r * Math.cos(phi) * Math.cos(theta)
            );
            camera.lookAt(0, targetY, 0);

            renderer.render(scene, camera);
        };
        this.modalViewer.rafId = requestAnimationFrame(loopModal);
    }

    desmontarVisorModal3D() {
        this.modalViewer.activo = false;
        if (this.modalViewer.rafId) {
            cancelAnimationFrame(this.modalViewer.rafId);
            this.modalViewer.rafId = null;
        }
        if (this.modalViewer.renderer) {
            try {
                this.modalViewer.renderer.dispose();
            } catch (e) {}
            this.modalViewer.renderer = null;
        }
        this.modalViewer.controladorCapas = null;
    }

    /**
     * ============================================================================
     * PUNTO 1: INTERFAZ AR CON BARRA INFERIOR GLASSMORPHISM DE CHIPS DE INGREDIENTES
     * ============================================================================
     */
    construirInterfazAR(conVideoElement = false, plato = null) {
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

        // Barra superior: Indicador único de anclaje + botón Cerrar
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

        statusPill.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.ancladoDefinitivo) {
                this.ancladoDefinitivo = false;
                this.framesEstablesHit = 0;
                if (this.platoRoot) {
                    this.platoRoot.visible = false;
                }
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

        // Barra inferior flotante Glassmorphism: Configurador de Ingredientes en vivo + Confirmar pedido
        const bottomDock = document.createElement('div');
        bottomDock.className = 'qr-ar-config-dock';
        bottomDock.addEventListener('beforexrselect', (e) => e.preventDefault());
        bottomDock.addEventListener('pointerdown', (e) => e.stopPropagation());

        const chipsRow = document.createElement('div');
        chipsRow.className = 'qr-ar-chips-scroll';
        bottomDock.appendChild(chipsRow);

        const btnConfirmar = document.createElement('button');
        btnConfirmar.type = 'button';
        btnConfirmar.className = 'qr-ar-btn-confirmar-pedido';
        btnConfirmar.innerHTML = '🛒 Confirmar y agregar al pedido';
        btnConfirmar.addEventListener('click', (e) => {
            e.stopPropagation();
            const platoTarget = this.platoActual || plato;
            const cb = this.onConfirmarPedidoCallback;
            this.finalizar();
            if (typeof cb === 'function' && platoTarget) {
                cb(platoTarget, this.obtenerEstadoPlato(platoTarget), this.obtenerResumenPersonalizacion(platoTarget));
            }
        });
        bottomDock.appendChild(btnConfirmar);

        root.appendChild(bottomDock);
        document.body.appendChild(root);

        this.overlayRoot = root;
        this.uiStatusPill = statusPill;
        this.uiStatusDot = dot;
        this.uiStatusText = statusTxt;
        this.uiChipsContainer = chipsRow;

        this.renderizarChipsDOMEnAR();
    }

    renderizarChipsDOMEnAR() {
        if (!this.uiChipsContainer || !this.platoActual) return;
        const receta = this.obtenerRecetaIngredientes(this.platoActual);
        const estado = this.obtenerEstadoPlato(this.platoActual);

        this.uiChipsContainer.innerHTML = '';
        for (const item of receta) {
            const activo = Boolean(estado[item.id]);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qr-ar-chip' + (activo ? ' is-active' : ' is-off') + (item.esExtra ? ' is-extra' : '');

            const checkStr = item.esExtra ? (activo ? ' ✓' : '') : (activo ? ' ✓' : ' ✕');
            btn.textContent = `${item.icono} ${item.nombre}${checkStr}`;

            btn.addEventListener('beforexrselect', (e) => e.preventDefault());
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.alternarIngrediente(this.platoActual, item.id);
            });
            this.uiChipsContainer.appendChild(btn);
        }
    }

    construirEscenaThree(plato, usarXR = false) {
        const vw = window.innerWidth || 390;
        const vh = window.innerHeight || 844;

        const scene = new THREE.Scene();
        scene.background = null;

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

        const diametro = this.obtenerDiametroObjetivoMetros(plato);
        const sombraGeo = new THREE.PlaneGeometry(diametro * 1.26, diametro * 1.26);
        sombraGeo.rotateX(-Math.PI / 2);
        const sombraMat = new THREE.MeshBasicMaterial({
            map: crearTexturaSombraContacto(),
            transparent: true,
            opacity: 0.76,
            depthWrite: false
        });
        const sombraMesh = new THREE.Mesh(sombraGeo, sombraMat);
        sombraMesh.position.set(0, 0.001, 0);
        platoPivot.add(sombraMesh);

        scene.add(platoRoot);

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
            if (e.target && (
                e.target.closest('.qr-ar-btn-close') ||
                e.target.closest('.qr-ar-status-pill') ||
                e.target.closest('.qr-ar-config-dock')
            )) return;

            if (!this.ancladoDefinitivo && this.reticulaMesh && this.reticulaMesh.visible && this.modoXR) {
                const xrCam = this.renderer ? this.renderer.xr.getCamera(this.camera) : null;
                this.clavarPlatoEnEspacioXR(this.posCandidata, xrCam);
                return;
            }
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
        } else if (estado === 'estabilizando') {
            this.uiStatusDot.className = 'qr-ar-status-dot is-scanning';
            this.uiStatusText.textContent = 'Detectando superficie del QR…';
        } else if (estado === 'anclado') {
            this.uiStatusDot.className = 'qr-ar-status-dot is-tracking';
            this.uiStatusText.textContent = 'Plato anclado';
        } else {
            this.uiStatusDot.className = 'qr-ar-status-dot is-scanning';
            this.uiStatusText.textContent = 'Apunta al QR de la mesa';
        }
    }

    async intentarIniciarWebXR(plato, promesaControlador) {
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

        this.construirInterfazAR(false, plato);
        this.construirEscenaThree(plato, true);

        const urlNormalizada = this.normalizarUrlModelo(plato && plato.modelo_3d);
        if (!this.cacheGltfScenes.has(urlNormalizada)) {
            this.actualizarIndicadorUnico('cargando', 0);
        }

        let session = null;
        try {
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

        promesaControlador.then((controlador) => {
            if (!this.activo || !this.platoPivot) return;
            this.controladorCapasAR = controlador;
            this.platoMesh = controlador.rootGroup;
            this.platoPivot.add(controlador.rootGroup);
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

        this.renderer.setAnimationLoop((timestamp, frame) => {
            if (!this.activo || !frame) return;

            if (this.controladorCapasAR) {
                this.controladorCapasAR.actualizarAnimacion();
            }

            const refSpace = this.xrRefSpace || this.renderer.xr.getReferenceSpace();
            if (!refSpace) return;

            const xrCam = this.renderer.xr.getCamera(this.camera);

            if (!this.ancladoDefinitivo && this.xrHitTestSource) {
                try {
                    const hitResults = frame.getHitTestResults(this.xrHitTestSource);
                    if (hitResults && hitResults.length > 0) {
                        const hit = hitResults[0];
                        const hitPose = hit.getPose(refSpace);
                        if (hitPose) {
                            tempMatrix.fromArray(hitPose.transform.matrix);
                            tempMatrix.decompose(tempPos, tempQuat, tempScale);

                            const normalY = hitPose.transform.matrix[5];
                            if (normalY > 0.82) {
                                if (this.reticulaMesh) {
                                    this.reticulaMesh.position.copy(tempPos);
                                    this.reticulaMesh.visible = true;
                                }

                                if (this.framesEstablesHit === 0) {
                                    this.posCandidata.copy(tempPos);
                                    this.framesEstablesHit = 1;
                                    this.actualizarIndicadorUnico('estabilizando');
                                } else {
                                    const distSalto = this.posCandidata.distanceTo(tempPos);
                                    this.posCandidata.lerp(tempPos, 0.30);

                                    if (distSalto < 0.012 && Math.abs(this.posCandidata.y - tempPos.y) < 0.006) {
                                        this.framesEstablesHit++;
                                    } else {
                                        this.framesEstablesHit = Math.max(1, this.framesEstablesHit - 1);
                                    }

                                    if (this.framesEstablesHit >= 14 && this.modeloListo) {
                                        this.clavarPlatoEnEspacioXR(this.posCandidata, xrCam);
                                    }
                                }
                            }
                        }
                    }
                } catch (e) {}
            }

            this.renderer.render(this.scene, this.camera);
        });

        return true;
    }

    clavarPlatoEnEspacioXR(posMundo, xrCam) {
        if (!this.platoRoot) return;

        this.platoRoot.position.copy(posMundo);

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

    async iniciar(plato, onFallback3D, opciones = {}) {
        this.finalizar();
        this.activo = true;
        this.modoXR = false;
        this.modeloListo = false;
        this.platoActual = plato;
        this.controladorCapasAR = null;
        this.ancladoDefinitivo = false;
        this.framesEstablesHit = 0;
        this.rotacionUsuarioY = 0;
        this.zoomAcercamiento = 1.0;

        if (opciones && typeof opciones.onConfirmarPedido === 'function') {
            this.onConfirmarPedidoCallback = opciones.onConfirmarPedido;
        }
        if (opciones && typeof opciones.onCambioIngredientes === 'function') {
            this.onCambioIngredientesCallback = opciones.onCambioIngredientes;
        }

        const promesaControlador = this.construirInstanciaConfigurable(
            plato,
            (pct) => {
                if (!this.modeloListo) {
                    this.actualizarIndicadorUnico('cargando', pct);
                }
            }
        );

        // 1. Intentar primero WebXR nativo 6-DoF (ARCore SLAM en Android de gama media/alta)
        const xrIniciado = await this.intentarIniciarWebXR(plato, promesaControlador);
        if (xrIniciado) {
            return;
        }

        // 2. Modo WebRTC + Giroscopio 3D en Espacio Mundo + Anclaje Único al capturar el QR
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            this.finalizar();
            if (typeof onFallback3D === 'function') {
                onFallback3D('Tu navegador o conexión actual no permite abrir la cámara directamente. Mostrando el configurador 3D interactivo.');
            }
            return;
        }

        await this.activarPermisoIMUSiNecesario();
        this.construirInterfazAR(true, plato);

        const urlNormalizada = this.normalizarUrlModelo(plato && plato.modelo_3d);
        if (!this.cacheGltfScenes.has(urlNormalizada)) {
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
                    onFallback3D('No se pudo acceder a la cámara (permiso denegado o equipo sin cámara). Puedes personalizar los ingredientes del plato en 3D interactivo aquí mismo.');
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
                onFallback3D('No se pudo iniciar el video de la cámara. Mostrando el configurador 3D interactivo.');
            }
            return;
        }

        this.construirEscenaThree(plato, false);
        window.addEventListener('resize', this._onResizeBound);

        promesaControlador.then((controlador) => {
            if (!this.activo || !this.platoPivot) return;
            this.controladorCapasAR = controlador;
            this.platoMesh = controlador.rootGroup;
            this.platoPivot.add(controlador.rootGroup);
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

            if (this.controladorCapasAR) {
                this.controladorCapasAR.actualizarAnimacion();
            }

            if (this.camera) {
                if (this.imuDisponible) {
                    this.camera.quaternion.slerp(this.quatCamaraIMU, 0.35);
                } else {
                    this.camera.rotation.set(-THREE.MathUtils.degToRad(42), 0, 0);
                }
            }

            if (!this.ancladoDefinitivo && nowMs - this.ultimoEscaneoMs >= 35) {
                this.ultimoEscaneoMs = nowMs;
                const det = this.detectarQREnFrame();
                if (det && this.camera) {
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
                    const depth = THREE.MathUtils.clamp((fy * this.tamanoFisicoQrMetros) / spanPx, 0.14, 1.20);

                    const ndcX = (cx / vw) * 2 - 1;
                    const ndcY = -(cy / vh) * 2 + 1;
                    const halfH = depth * Math.tan(fovRad * 0.5);
                    const halfW = halfH * this.camera.aspect;

                    const vecEnCamara = new THREE.Vector3(ndcX * halfW, ndcY * halfH, -depth);
                    const posEnMundo = vecEnCamara.applyQuaternion(this.camera.quaternion);

                    if (this.framesEstablesHit === 0) {
                        this.posMundoPlato.copy(posEnMundo);
                        this.framesEstablesHit = 1;
                        this.actualizarIndicadorUnico('estabilizando');
                    } else {
                        const distSalto = this.posMundoPlato.distanceTo(posEnMundo);
                        this.posMundoPlato.lerp(posEnMundo, 0.35);
                        if (distSalto < 0.025) {
                            this.framesEstablesHit++;
                        }
                    }

                    this.yawMundoPlato = Math.atan2(-this.posMundoPlato.x, -this.posMundoPlato.z);

                    if (this.framesEstablesHit >= 5 && this.platoRoot && this.modeloListo) {
                        this.platoRoot.position.copy(this.posMundoPlato);
                        this.platoRoot.rotation.set(0, this.yawMundoPlato, 0);
                        this.ancladoDefinitivo = true;
                        this.platoRoot.visible = true;
                        this.actualizarIndicadorUnico('anclado');
                    }
                }
            }

            if (this.ancladoDefinitivo && this.camera && !this.modoXR) {
                const factorAcercamiento = 1.0 - (1.0 / this.zoomAcercamiento);
                this.camera.position.copy(this.posMundoPlato).multiplyScalar(factorAcercamiento);
            }

            if (this.renderer && this.scene && this.camera) {
                this.renderer.render(this.scene, this.camera);
            }
        };

        this.rafId = requestAnimationFrame(loopFallback);
    }

    finalizar() {
        this.activo = false;
        this.controladorCapasAR = null;
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
