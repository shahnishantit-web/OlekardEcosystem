/**
 * E01 Identity – Token Refresh Performance Script (T196)
 *
 * Validates spec §24.2 latency target: p(95) < 200 ms for POST /v1/auth/token/refresh.
 *
 * ENVIRONMENT LIMITATION
 * ──────────────────────
 * K6 is not installed in this repository's local development environment.
 * This script must be executed in a dedicated performance environment.
 *
 * SMOKE TEST DEFAULTS
 * ───────────────────
 * K6_VUSER_COUNT=1, K6_DURATION=10s — NOT production capacity targets.
 *
 * Run:
 *   K6_VUSER_COUNT=1 K6_DURATION=10s k6 run token-refresh.js
 *
 * Pre-conditions:
 *   A valid refresh token must be provided via K6_FIXTURE_REFRESH_TOKEN.
 *   Each iteration rotates the token; set VUSER_COUNT=1 to avoid contention
 *   on a single token (tokens are single-use by design — BR-008).
 *
 *   For multi-VU tests, each VU must be seeded with its own refresh token.
 *   See setup.js for the fixture-seeding approach.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { SMOKE_TEST_DEFAULTS } from './config.js';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BASE_URL = __ENV.K6_BASE_URL || 'http://localhost:8000';

// ---------------------------------------------------------------------------
// K6 options — base from SMOKE_TEST_DEFAULTS; overridden by env vars at run time
// ---------------------------------------------------------------------------

export const options = {
  ...SMOKE_TEST_DEFAULTS,
  vus:      parseInt(__ENV.K6_VUSER_COUNT || String(SMOKE_TEST_DEFAULTS.vus), 10),
  duration: __ENV.K6_DURATION || SMOKE_TEST_DEFAULTS.duration,
  thresholds: {
    // spec §24.2: token refresh p(95) must be below 200 ms
    'http_req_duration{name:token_refresh}': ['p(95)<200'],
    'http_req_failed':                        ['rate<0.01'],
  },
};

const refreshSuccessRate = new Rate('token_refresh_success_rate');
const refreshDuration    = new Trend('token_refresh_duration_ms', true);

// ---------------------------------------------------------------------------
// Setup — obtain one refresh token per VU
// ---------------------------------------------------------------------------

export function setup() {
  const BASE = __ENV.K6_BASE_URL || 'http://localhost:8000';
  const mobile   = __ENV.K6_FIXTURE_MOBILE    || '+26771900001';
  const pin      = __ENV.K6_FIXTURE_PIN        || '123456';
  const device   = __ENV.K6_FIXTURE_DEVICE_FP || 'perf-refresh-device-001';

  const loginResp = http.post(`${BASE}/v1/auth/login`, JSON.stringify({
    identifier:         mobile,
    credential_type:    'pin',
    credential:         pin,
    device_fingerprint: device,
  }), {
    headers: {
      'Content-Type':     'application/json',
      'X-Correlation-ID': 'perf-setup-refresh',
    },
  });

  if (loginResp.status !== 200) {
    throw new Error(`Setup failed: login returned ${loginResp.status}. Ensure fixture users are seeded.`);
  }

  const body = JSON.parse(loginResp.body);
  return { refreshToken: body.data.refresh_token };
}

// ---------------------------------------------------------------------------
// Default function
// ---------------------------------------------------------------------------

export default function (data) {
  // NOTE: refresh tokens are single-use (BR-008). This script is designed
  // for VUSER_COUNT=1 where the token rotates on each iteration.
  // For multi-VU load tests, provision one token per VU via setup().
  const correlationId = `perf-refresh-${__VU}-${__ITER}`;

  const payload = JSON.stringify({ refresh_token: data.refreshToken });
  const params  = {
    headers: {
      'Content-Type':     'application/json',
      'X-Correlation-ID': correlationId,
    },
    tags: { name: 'token_refresh' },
  };

  const start    = Date.now();
  const response = http.post(`${BASE_URL}/v1/auth/token/refresh`, payload, params);
  const elapsed  = Date.now() - start;

  refreshDuration.add(elapsed);

  const ok = check(response, {
    'status is 200':           (r) => r.status === 200,
    'has new access_token':    (r) => JSON.parse(r.body)?.data?.access_token != null,
    'has new refresh_token':   (r) => JSON.parse(r.body)?.data?.refresh_token != null,
    'no stack trace in body':  (r) => !r.body.includes('Stack trace'),
  });

  if (ok) {
    // Rotate the token for the next iteration (single-VU only)
    data.refreshToken = JSON.parse(response.body).data.refresh_token;
  }

  refreshSuccessRate.add(ok);
  sleep(0.1);
}
