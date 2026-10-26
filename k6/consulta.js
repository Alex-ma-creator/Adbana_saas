import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://host.docker.internal:8080';

export const options = {
  vus: Number(__ENV.USUARIOS || 50),
  duration: '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    checks: ['rate>0.99'],
    http_req_duration: ['p(95)<1500'],
  },
};

export default function () {
  const respuesta = http.get(
    `${BASE}/api/carta/yurakuna`,
    { headers: { Accept: 'application/json' } }
  );

  check(respuesta, {
    'carta responde 200': (r) => r.status === 200,
  });

  sleep(1);
}
