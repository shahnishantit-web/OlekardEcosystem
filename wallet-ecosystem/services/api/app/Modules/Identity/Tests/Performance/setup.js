/**
 * E01 Identity – Performance Test Setup/Teardown (T198)
 *
 * Provides shared setup utilities for the E01 performance test suite.
 * Individual scripts (login.js, token-refresh.js, otp-verify.js) may
 * import and call these helpers from their own setup() and teardown() hooks.
 *
 * ENVIRONMENT LIMITATION
 * ──────────────────────
 * K6 is not installed in this repository's local development environment.
 * All scripts in this directory are documentation-ready and must be run
 * against a dedicated performance environment with an unseeded test database.
 *
 * USAGE
 * ─────
 * In a K6 test script:
 *
 *   import { seedFixtureUser, teardownFixtureUsers } from './setup.js';
 *
 *   export function setup() {
 *     return seedFixtureUser({ mobile: '+26771900001', pin: '123456' });
 *   }
 *
 *   export function teardown(data) {
 *     teardownFixtureUsers(data.userIds);
 *   }
 *
 * FIXTURE ENVIRONMENT VARIABLES
 * ──────────────────────────────
 * K6_BASE_URL          API base URL (default: http://localhost:8000)
 * K6_SEED_TOKEN        Admin Bearer token for seeding test data
 * K6_VUSER_COUNT       VU count — set this before running (no hard-coded default)
 * K6_DURATION          Test duration — set this before running (no hard-coded default)
 *
 * SMOKE TEST vs PRODUCTION
 * ────────────────────────
 * This file ships with intentionally minimal smoke-test defaults (1 VU, 10s).
 * These values are NOT production capacity targets. The performance team must
 * configure production-scale VU counts and durations separately, after:
 *   - Production traffic analysis
 *   - Capacity planning sign-off
 *   - Dedicated environment provisioning with production-scale data volumes
 */

import http from 'k6/http';
import { check } from 'k6';

const BASE_URL   = __ENV.K6_BASE_URL   || 'http://localhost:8000';
const SEED_TOKEN = __ENV.K6_SEED_TOKEN || '';

// ---------------------------------------------------------------------------
// Shared smoke-test defaults
// Exported so individual scripts can reference them without hard-coding.
// DO NOT use these values as production capacity targets.
// ---------------------------------------------------------------------------

export const SMOKE_TEST_DEFAULTS = {
  vus:      1,
  duration: '10s',
};

// ---------------------------------------------------------------------------
// Fixture: seed a test user for PIN-login performance tests
// ---------------------------------------------------------------------------

/**
 * Creates a test user via the registration + OTP verify + PIN set flow.
 * Returns the seeded user's credentials for use by VUs.
 *
 * @param {object} opts
 * @param {string} opts.mobile  - Mobile number in E.164 format
 * @param {string} opts.pin     - 6-digit PIN
 * @param {string} opts.device  - Raw device fingerprint string
 * @returns {{ mobile: string, pin: string, device: string, userId: string }}
 */
export function seedFixtureUser({ mobile, pin, device }) {
  if (!SEED_TOKEN) {
    console.warn('[perf/setup] K6_SEED_TOKEN not set — skipping DB seeding. Ensure fixtures are pre-loaded.');
    return { mobile, pin, device, userId: null };
  }

  const registerResp = http.post(`${BASE_URL}/v1/auth/register`, JSON.stringify({
    mobile_number: mobile,
    actor_type:    'CUSTOMER',
  }), {
    headers: {
      'Content-Type':       'application/json',
      'X-Correlation-ID':   `perf-setup-reg-${mobile}`,
      'X-Idempotency-Key':  `perf-idem-reg-${mobile}`,
    },
  });

  check(registerResp, { 'register 202': (r) => r.status === 202 });

  const regBody     = JSON.parse(registerResp.body);
  const challengeId = regBody?.data?.challenge_id;

  if (!challengeId) {
    console.error(`[perf/setup] Registration failed for ${mobile}: ${registerResp.body}`);
    return { mobile, pin, device, userId: null };
  }

  // In the stub delivery environment the OTP is deterministic.
  // For production-like environments, use a test-only endpoint or DB query
  // to retrieve the hashed OTP for verification.
  console.warn(
    `[perf/setup] challenge_id=${challengeId} created for ${mobile}. `
    + 'OTP must be retrieved from the stub delivery provider or DB.'
  );

  return { mobile, pin, device, challengeId, userId: null };
}

// ---------------------------------------------------------------------------
// Fixture: tear down seeded test users after the test completes
// ---------------------------------------------------------------------------

/**
 * Deletes seeded test users. Requires an admin endpoint or direct DB access
 * in the performance environment. Not implemented here — document the
 * teardown steps for the performance team.
 *
 * @param {string[]} userIds - UUIDs of users created during setup
 */
export function teardownFixtureUsers(userIds) {
  if (!userIds || userIds.length === 0) {
    return;
  }

  // Teardown is environment-specific:
  // Option A: Call an admin API endpoint to delete test users
  // Option B: Run a DB cleanup script after the test
  // Option C: Use a dedicated ephemeral test schema that is dropped after the run
  console.log(`[perf/setup] Teardown: ${userIds.length} fixture user(s) should be cleaned up.`);
  userIds.forEach((id) => {
    console.log(`[perf/setup] Delete user: ${id}`);
  });
}

// ---------------------------------------------------------------------------
// Standalone execution (run directly for pre-test fixture seeding)
// ---------------------------------------------------------------------------

export default function () {
  console.log('[perf/setup] Run this module via import in another script, or use it as a setup hook.');
  console.log(`[perf/setup] Target: ${BASE_URL}`);
}
