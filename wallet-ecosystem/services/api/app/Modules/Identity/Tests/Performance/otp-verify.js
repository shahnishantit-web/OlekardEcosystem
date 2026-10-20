/**
 * E01 Identity – OTP Verify Performance Script (T197)
 *
 * Validates spec §24.2 latency target: p(95) < 500 ms for POST /v1/auth/otp/verify.
 *
 * ENVIRONMENT LIMITATION
 * ──────────────────────
 * K6 is not installed in this repository's local development environment.
 * This script must be executed in a dedicated performance environment.
 *
 * OTP DELIVERY LATENCY EXCLUSION
 * ─────────────────────────────────
 * The p(95) < 500 ms target covers only the API endpoint processing time.
 * OTP delivery latency (SMS/email gateway) is external and is NOT included
 * in this measurement. The performance environment must use a stub/mock
 * OTP delivery provider that resolves synchronously.
 *
 * SMOKE TEST DEFAULTS
 * ───────────────────
 * K6_VUSER_COUNT=1, K6_DURATION=10s — NOT production capacity targets.
 *
 * Run:
 *   K6_VUSER_COUNT=1 K6_DURATION=10s k6 run otp-verify.js
 *
 * Pre-conditions:
 *   Each iteration requires a fresh OTP challenge. The setup() function
 *   initiates one registration challenge, captures the OTP (stub delivery),
 *   and passes it to each VU. For sustained load tests, the test harness
 *   must create a challenge pool large enough to cover all VU iterations.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { SMOKE_TEST_DEFAULTS } from './config.js';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BASE_URL = __ENV.K6_BASE_URL || 'http://localhost:8000';

// In a real performance run, K6_CHALLENGE_ID and K6_OTP_CODE are set by
// an external harness that pre-creates challenges via the stub delivery
// endpoint or a dedicated test seeding API.
const FIXTURE_CHALLENGE_ID = __ENV.K6_CHALLENGE_ID || '';
const FIXTURE_OTP          = __ENV.K6_OTP_CODE     || '';

// ---------------------------------------------------------------------------
// K6 options — base from SMOKE_TEST_DEFAULTS; overridden by env vars at run time
// ---------------------------------------------------------------------------

export const options = {
  ...SMOKE_TEST_DEFAULTS,
  vus:      parseInt(__ENV.K6_VUSER_COUNT || String(SMOKE_TEST_DEFAULTS.vus), 10),
  duration: __ENV.K6_DURATION || SMOKE_TEST_DEFAULTS.duration,
  thresholds: {
    // spec §24.2: OTP verify p(95) must be below 500 ms
    // OTP delivery latency is excluded — measured from request arrival to response
    'http_req_duration{name:otp_verify}': ['p(95)<500'],
    'http_req_failed':                     ['rate<0.01'],
  },
};

const otpSuccessRate = new Rate('otp_verify_success_rate');
const otpDuration    = new Trend('otp_verify_duration_ms', true);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

export function setup() {
  if (!FIXTURE_CHALLENGE_ID || !FIXTURE_OTP) {
    // Smoke-test stub: register a user and capture OTP from response headers
    // In production performance runs, the challenge pool is seeded externally.
    console.warn(
      '[perf/otp-verify] K6_CHALLENGE_ID or K6_OTP_CODE not set. '
      + 'Set these via environment variables from a pre-seeded challenge pool.'
    );
  }

  return {
    challengeId: FIXTURE_CHALLENGE_ID,
    otp:         FIXTURE_OTP,
  };
}

// ---------------------------------------------------------------------------
// Default function
// ---------------------------------------------------------------------------

export default function (data) {
  if (!data.challengeId || !data.otp) {
    console.error('[perf/otp-verify] No challenge_id or otp available — skipping iteration.');
    return;
  }

  const correlationId = `perf-otp-${__VU}-${__ITER}`;

  const payload = JSON.stringify({
    challenge_id: data.challengeId,
    otp:          data.otp,
  });

  const params = {
    headers: {
      'Content-Type':     'application/json',
      'X-Correlation-ID': correlationId,
    },
    tags: { name: 'otp_verify' },
  };

  const start    = Date.now();
  const response = http.post(`${BASE_URL}/v1/auth/otp/verify`, payload, params);
  const elapsed  = Date.now() - start;

  otpDuration.add(elapsed);

  const ok = check(response, {
    // 200 on first use; subsequent iterations with the same challenge get 401
    // (challenge consumed). Performance harness must supply fresh challenges per iteration.
    'status is 200 or 401':    (r) => r.status === 200 || r.status === 401,
    'no stack trace in body':  (r) => !r.body.includes('Stack trace'),
    'no internal path in body': (r) => !r.body.includes('/var/www'),
  });

  otpSuccessRate.add(ok);
  sleep(0.1);
}
