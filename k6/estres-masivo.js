import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = (__ENV.BASE_URL || 'https://3.143.255.64.nip.io/api').replace(/\/$/, '');

const TOTAL_PETICIONES = Number(__ENV.TOTAL_PETICIONES || 5000);
const VUS = Number(__ENV.VUS || 100);
const DURACION = __ENV.DURACION || __ENV.TIEMPO || (__ENV.MAX_DURATION || '30s');
const MODO = (__ENV.MODO || __ENV.ESCENARIO || 'iteraciones').toLowerCase();

// Porcentaje o cantidad de pedidos que van a la base de datos
const REGISTRAR_EN_BD = __ENV.REGISTRAR_EN_BD !== undefined
  ? Number(__ENV.REGISTRAR_EN_BD)
  : (__ENV.ESCRITURAS_BD !== undefined ? Number(__ENV.ESCRITURAS_BD) : TOTAL_PETICIONES);

const HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
const PARAMS = { headers: HEADERS, timeout: '120s' };

http.setResponseCallback(http.expectedStatuses(200, 201, 429));

const peticionesExitosas = new Counter('peticiones_exitosas');
const limitadas = new Counter('peticiones_limitadas');
const pedidosRegistradosBd = new Counter('pedidos_registrados_bd');

function buildScenarios() {
  if (MODO === 'tiempo' || MODO === 'duracion' || MODO === 'constant-vus') {
    return {
      estres_tiempo: {
        executor: 'constant-vus',
        vus: VUS,
        duration: DURACION,
        gracefulStop: '10s',
      },
    };
  }

  if (MODO === 'rampa' || MODO === 'stages' || MODO === 'escalonado') {
    return {
      estres_rampa: {
        executor: 'ramping-vus',
        startVUs: Math.min(10, VUS),
        stages: [
          { duration: '10s', target: VUS }, // Fase 1: Calentamiento / Rampa de subida
          { duration: DURACION, target: VUS }, // Fase 2: Pico sostenido de estrés
          { duration: '10s', target: 0 },   // Fase 3: Rampa de enfriamiento / Descenso
        ],
        gracefulRampDown: '10s',
      },
    };
  }

  // Por defecto: shared-iterations con tiempo máximo asignado
  return {
    estres_masivo: {
      executor: 'shared-iterations',
      vus: VUS,
      iterations: TOTAL_PETICIONES,
      maxDuration: DURACION,
      gracefulStop: '10s',
    },
  };
}

export const options = {
  discardResponseBodies: true,
  noConnectionReuse: false,
  setupTimeout: '5m',
  scenarios: buildScenarios(),
  thresholds: {
    checks: ['rate>0.70'],
    http_req_duration: ['p(95)<15000'],
    ...(REGISTRAR_EN_BD > 0 ? { 'http_req_duration{flujo:pedido}': ['p(95)<15000'] } : {}),
  },
};

export function setup() {
  const marca = Date.now();
  const email = `masivo_${TOTAL_PETICIONES}_${marca}@adbana.local`;

  const reg = http.post(
    `${BASE}/auth/registro-dueno`,
    JSON.stringify({
      restaurante: `Benchmark ${TOTAL_PETICIONES} (${VUS} VUs ${DURACION})`,
      name: `Auditor K6 (${DURACION})`,
      email,
      password: 'ClaveSegura123',
    }),
    { headers: HEADERS, responseType: 'text', timeout: '120s' },
  );

  if (reg.status !== 201) {
    throw new Error(`Fallo en setup registro-dueno: ${reg.status} ${reg.body}`);
  }

  const datosReg = reg.json();
  const token = datosReg.token;
  const slug = datosReg.restaurante.slug;
  const auth = { ...HEADERS, Authorization: `Bearer ${token}` };

  const cat = http.post(
    `${BASE}/categorias`,
    JSON.stringify({ nombre: `Especiales ${TOTAL_PETICIONES}`, orden: 1 }),
    { headers: auth, responseType: 'text', timeout: '120s' },
  );
  const categoriaId = cat.status === 201 ? cat.json('id') : null;

  // Stock suficiente para soportar millones de compras sin agotarse
  const stockSuficiente = Math.max(TOTAL_PETICIONES * 2, 10000000);
  const productosIds = [];

  // Crear un catálogo de 50 platos para distribuir los locks de fila (SELECT ... FOR UPDATE) en PostgreSQL
  const totalPlatos = 50;
  const nombresBase = ['Hamburguesa', 'Pizza', 'Lomo Saltado', 'Ceviche', 'Arroz Chaufa', 'Pollo Brasa', 'Tallarín', 'Anticucho', 'Causa', 'Tacu Tacu'];
  for (let i = 0; i < totalPlatos; i++) {
    const nombrePlato = `${nombresBase[i % nombresBase.length]} Especial #${i + 1}`;
    const prod = http.post(
      `${BASE}/productos`,
      JSON.stringify({
        categoria_id: categoriaId,
        nombre: `${nombrePlato} (${DURACION})`,
        precio: 20.0 + (i % 15),
        costo: 8.0 + (i % 8),
        stock: stockSuficiente,
      }),
      { headers: auth, responseType: 'text', timeout: '120s' },
    );

    if (prod.status === 201) {
      productosIds.push(prod.json('id'));
    }
  }

  if (productosIds.length === 0) {
    throw new Error('Fallo al crear productos en el setup.');
  }

  // Calentar caché Redis
  http.get(`${BASE}/carta/${slug}`, PARAMS);

  const totalBdObjetivo = Math.min(REGISTRAR_EN_BD, TOTAL_PETICIONES);

  return {
    slug,
    token,
    productosIds,
    totalBdObjetivo,
  };
}

export default function (data) {
  const iterGlobal = exec.scenario.iterationInTest;
  const vuId = exec.vu.idInTest;

  const esPedido = (data.totalBdObjetivo > 0) &&
    (REGISTRAR_EN_BD >= TOTAL_PETICIONES ||
      Math.floor((iterGlobal + 1) * data.totalBdObjetivo / TOTAL_PETICIONES)
        > Math.floor(iterGlobal * data.totalBdObjetivo / TOTAL_PETICIONES));

  if (esPedido) {
    const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const n = Math.floor(Math.random() * 16);
      return (c === 'x' ? n : (n & 3) | 8).toString(16);
    });

    const productoId = data.productosIds[(vuId + iterGlobal) % data.productosIds.length];

    const resPedido = http.post(
      `${BASE}/carta/${data.slug}/pedidos`,
      JSON.stringify({
        clave_operacion: uuid,
        cliente_nombre: `Cliente Pedido #${iterGlobal + 1} (${DURACION})`,
        tipo: 'recojo',
        items: [{ producto_id: productoId, cantidad: 1 }],
      }),
      {
        ...PARAMS,
        headers: {
          ...HEADERS,
          'X-Adbana-Cliente': `00000000-0000-4000-8000-${String(vuId).padStart(12, '0')}`,
        },
        tags: { flujo: 'pedido' }
      },
    );

    limitadas.add(Number(resPedido.status === 429));
    if (resPedido.status === 201) {
      pedidosRegistradosBd.add(1);
      peticionesExitosas.add(1);
    }
    check(resPedido, { 'operacion BD 201': (r) => r.status === 201 });
    return;
  }

  const res = http.get(`${BASE}/carta/${data.slug}`, { ...PARAMS, tags: { flujo: 'carta' } });
  limitadas.add(Number(res.status === 429));
  if (res.status === 200) {
    peticionesExitosas.add(1);
  }
  check(res, { 'respuesta 200 OK': (r) => r.status === 200 });
}
