/**
 * E01 Identity – Login Performance Script (T195)
 *
 * Validates spec §24.2 latency target: p(95) < 500 ms for POST /v1/auth/login.
 *
 * ENVIRONMENT LIMITATION
 * ──────────────────────
 * K6 is not installed in this repository's local development environment.
 * This script must be executed against a dedicated performance environment
 * with a seeded database containing test fixtures produced by setup.js.
 *
 * SMOKE TEST DEFAULTS
 * ───────────────────
 * K6_VUSER_COUNT defaults to 1 and K6_DURATION defaults to 10s.
 * These values are intentional smoke-test baselines and are NOT production
 * capacity targets. Production load profiles must be set via environment
 * variables by the performance team before any capacity decisions are made.
 *
 * Run:
 *   K6_VUSER_COUNT=1 K6_DURATION=10s k6 run login.js
 *
 * Pre-conditions:
 *   1. ./setup.js has been run (or the API environment is seeded with test users).
 *   2. The target API base URL is set via K6_BASE_URL env var.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { SMOKE_TEST_DEFAULTS } from './config.js';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BASE_URL     = __ENV.K6_BASE_URL || 'http://localhost:8000';

// Test fixture credentials — must match the users seeded by setup.js
const FIXTURE_MOBILE   = __ENV.K6_FIXTURE_MOBILE    || '+26771900001';
const FIXTURE_PIN      = __ENV.K6_FIXTURE_PIN        || '123456';
const FIXTURE_DEVICE   = __ENV.K6_FIXTURE_DEVICE_FP || 'perf-login-device-001';

// ---------------------------------------------------------------------------
// K6 options — base from SMOKE_TEST_DEFAULTS; overridden by env vars at run time
// ---------------------------------------------------------------------------

export const options = {
  ...SMOKE_TEST_DEFAULTS,
  vus:      parseInt(__ENV.K6_VUSER_COUNT || String(SMOKE_TEST_DEFAULTS.vus), 10),
  duration: __ENV.K6_DURATION || SMOKE_TEST_DEFAULTS.duration,
  thresholds: {
    // spec §24.2: login p(95) must be below 500 ms
    'http_req_duration{name:login}': ['p(95)<500'],
    'http_req_failed':               ['rate<0.01'],   // < 1 % error rate
  },
};

// Custom metrics
const loginSuccessRate = new Rate('login_success_rate');
const loginDuration    = new Trend('login_duration_ms', true);

// ---------------------------------------------------------------------------
// Idempotency key helper (unique per VU per iteration)
// ---------------------------------------------------------------------------

function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

// ---------------------------------------------------------------------------
// Default function (executed by each VU on every iteration)
// ---------------------------------------------------------------------------

export default function () {
  const correlationId  = uuidv4();
  const idempotencyKey = uuidv4();

  const payload = JSON.stringify({
    identifier:         FIXTURE_MOBILE,
    credential_type:    'pin',
    credential:         FIXTURE_PIN,
    device_fingerprint: FIXTURE_DEVICE,
  });

  const params = {
    headers: {
      'Content-Type':    'application/json',
      'X-Correlation-ID': correlationId,
      'X-Idempotency-Key': idempotencyKey,
    },
    tags: { name: 'login' },
  };

  const start    = Date.now();
  const response = http.post(`${BASE_URL}/v1/auth/login`, payload, params);
  const elapsed  = Date.now() - start;

  loginDuration.add(elapsed);

  const success = check(response, {
    'status is 200':             (r) => r.status === 200,
    'has access_token':          (r) => JSON.parse(r.body)?.data?.access_token != null,
    'no stack trace in body':    (r) => !r.body.includes('Stack trace'),
    'no raw error in body':      (r) => !r.body.includes('Exception in'),
  });

  loginSuccessRate.add(success);

  // Brief pause between iterations (not counted in latency measurement)
  sleep(0.1);
}
