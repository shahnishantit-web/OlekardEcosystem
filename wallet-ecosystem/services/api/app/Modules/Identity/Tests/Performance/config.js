/**
 * E01 Identity – Shared K6 Performance Configuration (T238)
 *
 * Exports a common options template and per-script threshold helpers used by
 * login.js, token-refresh.js, and otp-verify.js.  Individual scripts import
 * and merge/override these defaults so all scripts stay consistent.
 *
 * ENVIRONMENT LIMITATION
 * ──────────────────────
 * K6 is not installed in this repository's local development environment.
 * All performance scripts must be run against a dedicated performance
 * environment with a seeded database.
 *
 * PRODUCTION VU TARGETS
 * ─────────────────────
 * Concurrent VU counts for production-scale testing are explicitly TBD.
 * They MUST NOT be derived from these defaults.  The performance team must:
 *   1. Analyse production traffic patterns.
 *   2. Obtain capacity-planning sign-off.
 *   3. Provision a dedicated environment with production-scale data volumes.
 *   4. Set K6_VUSER_COUNT and K6_DURATION via environment variables before
 *      any capacity test or benchmark run.
 *
 * SMOKE TEST DEFAULTS (this file)
 * ────────────────────────────────
 * K6_VUSER_COUNT || 1    — single VU; validates the script path only
 * K6_DURATION    || 10s  — minimal duration; not a latency sample
 * These values are documentation baselines, NOT capacity targets.
 *
 * PERFORMANCE TARGETS (spec §24.2 / plan §Performance Plan)
 * ─────────────────────────────────────────────────────────
 * login P95          ≤ 500 ms
 * token-refresh P95  ≤ 200 ms
 * OTP verify P95     ≤ 500 ms
 *
 * USAGE
 * ─────
 * import { buildOptions, thresholds } from './config.js';
 *
 * export const options = buildOptions(thresholds.login);
 *
 * To run as a smoke test:
 *   K6_VUSER_COUNT=1 K6_DURATION=10s k6 run login.js
 *
 * To run a load test (example — set actual values after capacity planning):
 *   K6_VUSER_COUNT=<TBD> K6_DURATION=<TBD> k6 run login.js
 */

// ---------------------------------------------------------------------------
// Environment resolution
// ---------------------------------------------------------------------------

const VU_COUNT = parseInt(__ENV.K6_VUSER_COUNT || '1', 10);
const DURATION = __ENV.K6_DURATION || '10s';

// ---------------------------------------------------------------------------
// Per-endpoint p95 threshold definitions (spec §24.2)
// ---------------------------------------------------------------------------

export const thresholds = {
  /** POST /v1/auth/login — spec target: p(95) < 500 ms */
  login: {
    http_req_duration: ['p(95)<500'],
  },
  /** POST /v1/auth/token/refresh — spec target: p(95) < 200 ms */
  tokenRefresh: {
    http_req_duration: ['p(95)<200'],
  },
  /** POST /v1/auth/otp/verify — spec target: p(95) < 500 ms */
  otpVerify: {
    http_req_duration: ['p(95)<500'],
  },
};

// ---------------------------------------------------------------------------
// options builder
// ---------------------------------------------------------------------------

/**
 * Returns a K6 options object for the given threshold set.
 *
 * @param {object} endpointThresholds - A thresholds entry from the `thresholds` map above.
 * @returns {object} K6 options block ready for `export const options = ...`
 */
export function buildOptions(endpointThresholds) {
  return {
    vus:      VU_COUNT,
    duration: DURATION,
    thresholds: {
      http_req_failed: ['rate<0.01'],
      ...endpointThresholds,
    },
  };
}

// ---------------------------------------------------------------------------
// Convenience re-export: smoke-test defaults (for scripts that import setup.js)
// ---------------------------------------------------------------------------

export const SMOKE_TEST_DEFAULTS = {
  vus:      1,
  duration: '10s',
};
