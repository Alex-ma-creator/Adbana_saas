import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = (__ENV.BASE_URL || 'https://3.143.255.64.nip.io/api').replace(/\/$/, '');

const TOTAL_PETICIONES = Math.max(1, Number(__ENV.TOTAL_PETICIONES || 5000));
const VUS_SOLICITADOS = Math.max(1, Number(__ENV.VUS || 100));
const DURACION = __ENV.DURACION || __ENV.TIEMPO || (__ENV.MAX_DURATION || '30s');
const MODO = (__ENV.MODO || __ENV.ESCENARIO || 'iteraciones').toLowerCase();

// Calibracion de Concurrencia para k6 en Windows:
// Soporta desde 100 hasta miles de VUs segun lo solicitado por el usuario.
const LIMITE_SEGURO_VUS = Math.max(1, Number(__ENV.MAX_VUS_FISICOS || 2000));
const VUS_FISICOS = Math.min(VUS_SOLICITADOS, LIMITE_SEGURO_VUS);
const MODO_MULTIPLEXADO = TOTAL_PETICIONES > 50000 && VUS_SOLICITADOS > LIMITE_SEGURO_VUS;
const BATCH_SIZE = MODO_MULTIPLEXADO
  ? Math.min(5, Math.max(2, Math.ceil(TOTAL_PETICIONES / (VUS_FISICOS * 20))))
  : 1;

// Porcentaje o cantidad de pedidos que van a la base de datos
const REGISTRAR_EN_BD = __ENV.REGISTRAR_EN_BD !== undefined
  ? Number(__ENV.REGISTRAR_EN_BD)
  : (__ENV.ESCRITURAS_BD !== undefined ? Number(__ENV.ESCRITURAS_BD) : TOTAL_PETICIONES);

const HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
const PARAMS = { headers: HEADERS, timeout: '30s' };

// Reconoce como respuestas esperadas: 200 (carta), 201 (pedido BD), 429/503 (contencion JSON controlada)
http.setResponseCallback(http.expectedStatuses(200, 201, 429, 503));

const peticionesExitosas = new Counter('peticiones_exitosas');
const pedidosRegistradosBd = new Counter('pedidos_registrados_bd');
const peticionesContenidas429 = new Counter('peticiones_contenidas_429');
const peticionesContenidas503 = new Counter('peticiones_contenidas_503');
const caidasNoControladas = new Counter('caidas_no_controladas');
const jsonContencionVerificados = new Counter('json_contencion_verificados');

const motivoShedding = new Counter('motivo_DB_CONCURRENCY_SHEDDING');
const motivoLockTimeout = new Counter('motivo_DB_LOCK_TIMEOUT');
const motivoPoolSaturated = new Counter('motivo_DB_POOL_SATURATED');
const motivoRedisUnavailable = new Counter('motivo_REDIS_UNAVAILABLE');
const motivoRateLimited = new Counter('motivo_RATE_LIMITED');
const motivoNginxQueue = new Counter('motivo_NGINX_FPM_QUEUE_SATURATED');
const motivoOtroError = new Counter('motivo_OTRO_ERROR');

function buildScenarios() {
  if (MODO === 'tiempo' || MODO === 'duracion' || MODO === 'constant-vus') {
    return {
      estres_tiempo: {
        executor: 'constant-vus',
        vus: VUS_FISICOS,
        duration: DURACION,
        gracefulStop: '5s',
      },
    };
  }

  if (MODO === 'rampa' || MODO === 'stages' || MODO === 'escalonado') {
    return {
      estres_rampa: {
        executor: 'ramping-vus',
        startVUs: Math.min(10, VUS_FISICOS),
        stages: [
          { duration: '10s', target: VUS_FISICOS },
          { duration: DURACION, target: VUS_FISICOS },
          { duration: '10s', target: 0 },
        ],
        gracefulRampDown: '5s',
      },
    };
  }

  const iteracionesLote = Math.max(VUS_FISICOS, Math.ceil(TOTAL_PETICIONES / BATCH_SIZE));

  return {
    estres_masivo: {
      executor: 'shared-iterations',
      vus: Math.min(VUS_FISICOS, iteracionesLote),
      iterations: iteracionesLote,
      maxDuration: DURACION,
      gracefulStop: '5s',
    },
  };
}

export const options = {
  discardResponseBodies: true,
  noConnectionReuse: false,
  batch: BATCH_SIZE,
  batchPerHost: Math.min(2, BATCH_SIZE),
  setupTimeout: '5m',
  scenarios: buildScenarios(),
  thresholds: {
    checks: ['rate>0.85'],
    caidas_no_controladas: ['count<100'],
  },
};

function fmtNum(n) {
  return Math.round(Number(n || 0)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function fmtMs(n) {
  return `${Number(n || 0).toFixed(2)} ms`;
}

function generarUuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const n = Math.floor(Math.random() * 16);
    return (c === 'x' ? n : (n & 3) | 8).toString(16);
  });
}

function obtenerHeader(headers, nombreBuscado) {
  if (!headers) return '';
  if (headers[nombreBuscado] !== undefined) return String(headers[nombreBuscado]);
  const lower = nombreBuscado.toLowerCase();
  for (const key of Object.keys(headers)) {
    if (key.toLowerCase() === lower) {
      return String(headers[key]);
    }
  }
  return '';
}

function registrarMotivo(codigo) {
  switch (codigo) {
    case 'DB_CONCURRENCY_SHEDDING':
      motivoShedding.add(1);
      break;
    case 'DB_LOCK_TIMEOUT':
      motivoLockTimeout.add(1);
      break;
    case 'DB_POOL_SATURATED':
      motivoPoolSaturated.add(1);
      break;
    case 'REDIS_UNAVAILABLE':
      motivoRedisUnavailable.add(1);
      break;
    case 'RATE_LIMITED':
      motivoRateLimited.add(1);
      break;
    case 'NGINX_FPM_QUEUE_SATURATED':
      motivoNginxQueue.add(1);
      break;
    default:
      motivoOtroError.add(1);
      break;
  }
}

function evaluarRespuesta(res, esPedido, inspeccionarCuerpo) {
  const status = res.status;

  if (status === 201 && esPedido) {
    pedidosRegistradosBd.add(1);
    peticionesExitosas.add(1);
    check(res, { 'respuesta valida (201 BD o contencion JSON)': () => true });
    return;
  }

  if (status === 200 && !esPedido) {
    peticionesExitosas.add(1);
    check(res, { 'respuesta valida (200 carta o contencion JSON)': () => true });
    return;
  }

  if (status === 429 || status === 503) {
    const contentType = obtenerHeader(res.headers, 'Content-Type').toLowerCase();
    let failureReason = obtenerHeader(res.headers, 'X-Failure-Reason').trim();
    let jsonValido = contentType.includes('application/json') || failureReason !== '';

    if (inspeccionarCuerpo && typeof res.body === 'string' && res.body.length > 0) {
      try {
        const parsed = JSON.parse(res.body);
        if (parsed && parsed.codigo_fallo && parsed.motivo_caida) {
          failureReason = String(parsed.codigo_fallo);
          jsonValido = true;
          jsonContencionVerificados.add(1);
        }
      } catch (_) {
        jsonValido = false;
      }
    }

    if (jsonValido) {
      if (status === 429) {
        peticionesContenidas429.add(1);
        registrarMotivo(failureReason || 'RATE_LIMITED');
      } else {
        peticionesContenidas503.add(1);
        registrarMotivo(failureReason || 'DB_CONCURRENCY_SHEDDING');
      }
      check(res, { 'respuesta valida (201 BD o contencion JSON)': () => true });
      return;
    }
  }

  caidasNoControladas.add(1);
  registrarMotivo('OTRO_ERROR');
  check(res, { 'respuesta valida (201 BD o contencion JSON)': () => false });
}

export function setup() {
  console.log('========================================================================');
  console.log('  ADBANA SaaS - MOTOR k6 DE ULTRA-ALTA CONCURRENCIA Y RESILIENCIA');
  console.log('========================================================================');
  console.log(`  Servidor Destino     : ${BASE}`);
  console.log(`  Total Peticiones     : ${fmtNum(TOTAL_PETICIONES)}`);
  console.log(`  Usuarios/Concurrencia: ${fmtNum(VUS_SOLICITADOS)}`);
  console.log(`  Tiempo               : ${DURACION}`);
  if (MODO_MULTIPLEXADO) {
    console.log(`  Blindaje Windows TCP : ACTIVO (${VUS_FISICOS} VUs fisicos x batch(${BATCH_SIZE}) sobre Keep-Alive/HTTP2)`);
  } else {
    console.log(`  Modo de Red          : Directo (${VUS_FISICOS} VUs fisicos con Keep-Alive)`);
  }
  console.log('========================================================================');

  const marca = Date.now();
  const email = `masivo_${TOTAL_PETICIONES}_${marca}@adbana.local`;

  const reg = http.post(
    `${BASE}/auth/registro-dueno`,
    JSON.stringify({
      restaurante: `Benchmark ${TOTAL_PETICIONES} (${VUS_SOLICITADOS} VUs ${DURACION})`,
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

  // Catalogo de 50 platos para distribuir locks de fila (SELECT ... FOR UPDATE) en PostgreSQL
  const totalPlatos = 50;
  const nombresBase = ['Hamburguesa', 'Pizza', 'Lomo Saltado', 'Ceviche', 'Arroz Chaufa', 'Pollo Brasa', 'Tallarin', 'Anticucho', 'Causa', 'Tacu Tacu'];
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

  // Calentar cache Redis
  http.get(`${BASE}/carta/${slug}`, PARAMS);

  const totalBdObjetivo = Math.min(REGISTRAR_EN_BD, TOTAL_PETICIONES);

  return {
    slug,
    token,
    productosIds,
    totalBdObjetivo,
  };
}

function construirSolicitud(data, seqIndex, vuId, inspeccionarCuerpo) {
  const esPedido = (data.totalBdObjetivo > 0) &&
    (REGISTRAR_EN_BD >= TOTAL_PETICIONES ||
      Math.floor((seqIndex + 1) * data.totalBdObjetivo / TOTAL_PETICIONES)
        > Math.floor(seqIndex * data.totalBdObjetivo / TOTAL_PETICIONES));

  const paramsBase = inspeccionarCuerpo ? { ...PARAMS, responseType: 'text' } : PARAMS;

  if (esPedido) {
    const productoId = data.productosIds[(vuId + seqIndex) % data.productosIds.length];
    return {
      esPedido: true,
      req: [
        'POST',
        `${BASE}/carta/${data.slug}/pedidos`,
        JSON.stringify({
          clave_operacion: generarUuid(),
          cliente_nombre: `Cliente Pedido #${seqIndex + 1} (${DURACION})`,
          tipo: 'recojo',
          items: [{ producto_id: productoId, cantidad: 1 }],
        }),
        {
          ...paramsBase,
          headers: {
            ...HEADERS,
            'X-Adbana-Cliente': `00000000-0000-4000-8000-${String(vuId).padStart(12, '0')}`,
          },
          tags: { flujo: 'pedido' },
        },
      ],
    };
  }

  return {
    esPedido: false,
    req: [
      'GET',
      `${BASE}/carta/${data.slug}`,
      null,
      { ...paramsBase, tags: { flujo: 'carta' } },
    ],
  };
}

export default function (data) {
  const iterGlobal = exec.scenario.iterationInTest;
  const vuId = exec.vu.idInTest;

  // Escalonamiento de microsegundos en el handshake TLS inicial (iteracion 0 de cada VU)
  // para no desbordar la cola TCP SYN (somaxconn) en el milisegundo 0.
  if (exec.vu.iterationInScenario === 0 && vuId > 15) {
    sleep(((vuId - 1) % 60) * 0.015);
  }

  if (!MODO_MULTIPLEXADO) {
    const inspeccionar = iterGlobal < 15 || (iterGlobal % 100 === 0);
    const item = construirSolicitud(data, iterGlobal, vuId, inspeccionar);
    const [method, url, body, params] = item.req;
    const res = method === 'POST' ? http.post(url, body, params) : http.get(url, params);
    evaluarRespuesta(res, item.esPedido, inspeccionar);
    return;
  }

  const baseSeq = iterGlobal * BATCH_SIZE;
  const lote = [];
  const meta = [];

  for (let i = 0; i < BATCH_SIZE; i++) {
    const seq = baseSeq + i;
    if (MODO === 'iteraciones' && seq >= TOTAL_PETICIONES) {
      break;
    }
    const inspeccionar = seq < 20 || (seq % 250 === 0);
    const item = construirSolicitud(data, seq, vuId + i, inspeccionar);
    lote.push(item.req);
    meta.push({ esPedido: item.esPedido, inspeccionar });
  }

  if (lote.length === 0) {
    return;
  }

  const respuestas = http.batch(lote);
  for (let i = 0; i < respuestas.length; i++) {
    evaluarRespuesta(respuestas[i], meta[i].esPedido, meta[i].inspeccionar);
  }
}

export function handleSummary(data) {
  const m = data.metrics || {};
  const countOf = (name) => (m[name] && m[name].values && m[name].values.count) ? m[name].values.count : 0;
  const rateOf = (name) => (m[name] && m[name].values && m[name].values.rate) ? m[name].values.rate : 0;
  const dur = (m.http_req_duration && m.http_req_duration.values) ? m.http_req_duration.values : {};

  const totalReqs = countOf('http_reqs');
  const throughput = rateOf('http_reqs');
  const pedidos201 = countOf('pedidos_registrados_bd');
  const contenidas429 = countOf('peticiones_contenidas_429');
  const contenidas503 = countOf('peticiones_contenidas_503');
  const totalContenidas = contenidas429 + contenidas503;
  const caidas = countOf('caidas_no_controladas');
  const jsonVerif = countOf('json_contencion_verificados');

  const motivos = [
    ['DB_CONCURRENCY_SHEDDING', countOf('motivo_DB_CONCURRENCY_SHEDDING'), 'Semaforo Redis (>22 tx ACID) protege PostgreSQL RDS'],
    ['DB_LOCK_TIMEOUT', countOf('motivo_DB_LOCK_TIMEOUT'), 'Timeout controlado en SELECT ... FOR UPDATE (1800ms)'],
    ['DB_POOL_SATURATED', countOf('motivo_DB_POOL_SATURATED'), 'Pool de conexiones PostgreSQL saturado / fail-fast'],
    ['REDIS_UNAVAILABLE', countOf('motivo_REDIS_UNAVAILABLE'), 'Contencion por indisponibilidad temporal de Redis'],
    ['RATE_LIMITED', countOf('motivo_RATE_LIMITED'), 'Control de tasa HTTP 429 activo'],
    ['NGINX_FPM_QUEUE_SATURATED', countOf('motivo_NGINX_FPM_QUEUE_SATURATED'), 'Respuesta directa desde Nginx @overload_json (<1ms)'],
    ['OTRO_ERROR', countOf('motivo_OTRO_ERROR'), 'Errores de red / timeout / caida no clasificada'],
  ];

  const lineas = [
    '',
    '========================================================================',
    '  REPORTE EJECUTIVO DE RESILIENCIA Y CUELLOS DE BOTELLA (ADBANA SaaS)',
    '========================================================================',
    `  Objetivo Solicitado  : ${fmtNum(TOTAL_PETICIONES)} peticiones | ${fmtNum(VUS_SOLICITADOS)} VUs | ${DURACION}`,
    `  Modo de Ejecucion    : ${MODO_MULTIPLEXADO ? `Multiplexado (${VUS_FISICOS} VUs fisicos x batch(${BATCH_SIZE}))` : `Directo (${VUS_FISICOS} VUs)`}`,
    '------------------------------------------------------------------------',
    `  Total Peticiones Procesadas   : ${fmtNum(totalReqs)} (${throughput.toFixed(1)} req/s)`,
    `  Pedidos Escritos en RDS (201) : ${fmtNum(pedidos201)}`,
    `  Contenidas con JSON (429/503) : ${fmtNum(totalContenidas)} (429: ${fmtNum(contenidas429)} | 503: ${fmtNum(contenidas503)} | JSON verif: ${fmtNum(jsonVerif)})`,
    `  Caidas No Controladas         : ${fmtNum(caidas)}`,
    '------------------------------------------------------------------------',
    '  MOTIVOS DE CONTENCION / CAIDA DETECTADOS:',
    '  Codigo de Fallo             | Cantidad   | Descripcion',
    '  ----------------------------+------------+----------------------------',
  ];

  for (const [codigo, cant, desc] of motivos) {
    if (cant > 0 || codigo === 'DB_CONCURRENCY_SHEDDING' || codigo === 'NGINX_FPM_QUEUE_SATURATED') {
      lineas.push(`  ${codigo.padEnd(27)} | ${fmtNum(cant).padStart(10)} | ${desc}`);
    }
  }

  lineas.push('------------------------------------------------------------------------');
  lineas.push('  TIEMPOS DE RESPUESTA (http_req_duration):');
  lineas.push(`    avg : ${fmtMs(dur.avg)}   |   med : ${fmtMs(dur.med)}   |   p90 : ${fmtMs(dur['p(90)'])}`);
  lineas.push(`    p95 : ${fmtMs(dur['p(95)'])}   |   max : ${fmtMs(dur.max)}`);
  lineas.push('========================================================================');
  lineas.push('');

  return {
    stdout: lineas.join('\n'),
  };
}
