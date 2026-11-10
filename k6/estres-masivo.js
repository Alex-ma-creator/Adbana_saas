import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = (__ENV.BASE_URL || '').replace(/\/$/, '');
if (!BASE || __ENV.ENTORNO_AISLADO !== '1') {
  throw new Error('Configura BASE_URL con /api y confirma ENTORNO_AISLADO=1. La prueba crea datos reales.');
}

const TOTAL_PETICIONES = Number(__ENV.TOTAL_PETICIONES || 5000);
const VUS = Number(__ENV.VUS || 50);
// Por defecto registra el 100% de TOTAL_PETICIONES en la base de datos PostgreSQL (sin tope de 500)
const REGISTRAR_EN_BD = __ENV.REGISTRAR_EN_BD !== undefined
  ? Number(__ENV.REGISTRAR_EN_BD)
  : (__ENV.ESCRITURAS_BD !== undefined ? Number(__ENV.ESCRITURAS_BD) : TOTAL_PETICIONES);
if (!Number.isSafeInteger(TOTAL_PETICIONES) || TOTAL_PETICIONES < 1 ||
    !Number.isSafeInteger(VUS) || VUS < 1 ||
    !Number.isSafeInteger(REGISTRAR_EN_BD) || REGISTRAR_EN_BD < 0 ||
    REGISTRAR_EN_BD > TOTAL_PETICIONES) {
  throw new Error('Indica cantidades enteras válidas para operaciones, usuarios y escrituras.');
}

const HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
const PARAMS = { headers: HEADERS, timeout: '120s' };

http.setResponseCallback(http.expectedStatuses(200, 201));

const peticionesExitosas = new Counter('peticiones_exitosas');
const limitadas = new Counter('peticiones_limitadas');
const pedidosRegistradosBd = new Counter('pedidos_registrados_bd');

export const options = {
  discardResponseBodies: true,
  noConnectionReuse: false,
  setupTimeout: '5m',
  scenarios: {
    estres_masivo: {
      executor: 'shared-iterations',
      vus: VUS,
      iterations: TOTAL_PETICIONES,
      maxDuration: '60m',
    },
  },
  thresholds: {
    peticiones_limitadas: ['count==0'],
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500'],
    ...(REGISTRAR_EN_BD > 0 ? { 'http_req_duration{flujo:pedido}': ['p(95)<1500'] } : {}),
    ...(REGISTRAR_EN_BD < TOTAL_PETICIONES ? { 'http_req_duration{flujo:carta}': ['p(95)<1500'] } : {}),
    peticiones_exitosas: [`count>=${Math.floor(TOTAL_PETICIONES * 0.99)}`],
    pedidos_registrados_bd: [`count>=${Math.floor(Math.min(REGISTRAR_EN_BD, TOTAL_PETICIONES) * 0.99)}`],
  },
};

export function setup() {
  const marca = Date.now();
  const email = `masivo_${TOTAL_PETICIONES}_${marca}@adbana.local`;

  const reg = http.post(
    `${BASE}/auth/registro-dueno`,
    JSON.stringify({
      restaurante: `Benchmark ${TOTAL_PETICIONES} Reqs (${VUS} VUs)`,
      name: `Auditor K6 (${TOTAL_PETICIONES} Reqs)`,
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
        nombre: `${nombrePlato} (${TOTAL_PETICIONES})`,
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

  const esPedido = Math.floor((iterGlobal + 1) * data.totalBdObjetivo / TOTAL_PETICIONES)
    > Math.floor(iterGlobal * data.totalBdObjetivo / TOTAL_PETICIONES);

  if (esPedido) {
    const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const n = Math.floor(Math.random() * 16);
      return (c === 'x' ? n : (n & 3) | 8).toString(16);
    });

    // Distribuir entre los 50 productos combinando VU + iteración para eliminar contención de bloqueos en BD
    const productoId = data.productosIds[(vuId + iterGlobal) % data.productosIds.length];

    const resPedido = http.post(
      `${BASE}/carta/${data.slug}/pedidos`,
      JSON.stringify({
        clave_operacion: uuid,
        cliente_nombre: `Cliente Pedido #${iterGlobal + 1} (${TOTAL_PETICIONES})`,
        tipo: 'recojo',
        items: [{ producto_id: productoId, cantidad: 1 }],
      }),
      { ...PARAMS, headers: {
        ...HEADERS,
        'X-Adbana-Cliente': `00000000-0000-4000-8000-${String(vuId).padStart(12, '0')}`,
      }, tags: { flujo: 'pedido' } },
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

