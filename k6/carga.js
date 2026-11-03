import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://host.docker.internal:8080/api';
const USUARIOS = Number(__ENV.USUARIOS || 50);
const STOCK_INICIAL = Number(__ENV.STOCK || USUARIOS);
const HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

// 200, 201 y 422 (sin stock) son respuestas esperadas; lo demás cuenta como fallo.
http.setResponseCallback(http.expectedStatuses(200, 201, 422));

const ventasOk = new Counter('ventas_ok');
const ventasSinStock = new Counter('ventas_sin_stock');

export const options = {
  scenarios: {
    // Usuarios simultáneos viendo la carta.
    carta: {
      executor: 'ramping-vus',
      exec: 'verCarta',
      startVUs: 0,
      stages: [
        { duration: '10s', target: Math.max(1, Math.floor(USUARIOS / 2)) },
        { duration: '15s', target: USUARIOS },
        { duration: '5s', target: 0 },
      ],
    },
    // N usuarios comprando a la vez un producto y registrándolo en la base de datos.
    compras: {
      executor: 'per-vu-iterations',
      exec: 'comprar',
      vus: USUARIOS,
      iterations: 2,
      startTime: '5s',
      maxDuration: '1m',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<3000'],
    // Deben registrarse exactamente STOCK_INICIAL ventas en la BD, ni más ni menos.
    ventas_ok: [`count==${STOCK_INICIAL}`],
  },
};

export function setup() {
  const email = `k6_${Date.now()}@prueba.com`;

  const reg = http.post(
    `${BASE}/auth/registro-dueno`,
    JSON.stringify({
      restaurante: `Carga ${USUARIOS}VUs ${Date.now()}`,
      name: `Dueno K6 (${USUARIOS} VUs)`,
      email,
      password: 'ClaveSegura123',
    }),
    { headers: HEADERS },
  );
  if (reg.status !== 201) {
    throw new Error(`No se pudo registrar: ${reg.status} ${reg.body}`);
  }
  const { token, restaurante } = reg.json();
  const auth = { ...HEADERS, Authorization: `Bearer ${token}` };

  const prod = http.post(
    `${BASE}/productos`,
    JSON.stringify({ nombre: `Pollo a la brasa (${USUARIOS} VUs)`, precio: 25, costo: 12, stock: STOCK_INICIAL }),
    { headers: auth },
  );
  if (prod.status !== 201) {
    throw new Error(`No se pudo crear el producto: ${prod.status} ${prod.body}`);
  }

  return { slug: restaurante.slug, token, productoId: prod.json('id') };
}

export function verCarta(data) {
  const res = http.get(`${BASE}/carta/${data.slug}`, { headers: HEADERS });
  check(res, { 'carta 200': (r) => r.status === 200 });
}

export function comprar(data) {
  const res = http.post(
    `${BASE}/carta/${data.slug}/pedidos`,
    JSON.stringify({
      clave_operacion: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const n = Math.floor(Math.random()*16); return (c === 'x' ? n : (n & 3) | 8).toString(16); }),
      cliente_nombre: `Cliente K6 (${USUARIOS} VUs)`,
      tipo: 'recojo',
      items: [{ producto_id: data.productoId, cantidad: 1 }],
    }),
    { headers: HEADERS },
  );

  if (res.status === 201) ventasOk.add(1);
  else if (res.status === 422) ventasSinStock.add(1);

  check(res, { 'respuesta valida (201 o 422)': (r) => r.status === 201 || r.status === 422 });
}

export function teardown(data) {
  const res = http.get(`${BASE}/productos/${data.productoId}`, {
    headers: { ...HEADERS, Authorization: `Bearer ${data.token}` },
  });
  const stockFinal = Number(res.json('stock'));
  console.log(`Stock final: ${stockFinal} (debe ser 0, nunca negativo)`);
  check(res, { 'stock final = 0': () => stockFinal === 0 });
}
