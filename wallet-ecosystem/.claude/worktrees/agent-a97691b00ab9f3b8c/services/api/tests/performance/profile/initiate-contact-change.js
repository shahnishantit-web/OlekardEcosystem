/**
 * T162 — K6 performance test: POST /v1/users/profile/email/change
 * Spec §9: P95 ≤ 500 ms under sustained load.
 *
 * Each VU submits a unique fabricated email address per iteration so the
 * duplicate-challenge guard does not block all iterations after the first.
 * A unique Idempotency-Key per VU-iteration prevents 409 short-circuits.
 *
 * Prerequisites:
 *   - API reachable at K6_BASE_URL
 *   - K6_AUTH_TOKEN holds a valid JWT for an existing user profile
 *   - OTP delivery is stubbed/disabled in the test environment
 *
 * Run:
 *   k6 run -e K6_BASE_URL=http://localhost:8000 \
 *          -e K6_AUTH_TOKEN=<jwt> \
 *          -e K6_VUSER_COUNT=20 \
 *          tests/performance/profile/initiate-contact-change.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, THRESHOLDS, authHeaders, buildOptions } from './config.js';

const initiateContactChangeDuration = new Trend('initiate_contact_change_duration', true);

export const options = buildOptions(
  'initiate_contact_change',
  THRESHOLDS.INITIATE_CONTACT_CHANGE.p95,
);

export default function () {
  const idempotencyKey = `perf-email-vu${__VU}-iter${__ITER}`;

  // Fabricated address unique per VU-iteration avoids duplicate-challenge rejection.
  const newEmail = `perf-vu${__VU}-iter${__ITER}@k6.test`;

  const payload = JSON.stringify({ new_email: newEmail });

  const headers = {
    ...authHeaders(),
    'Idempotency-Key': idempotencyKey,
  };

  const res = http.post(`${BASE_URL}/v1/users/profile/email/change`, payload, {
    headers,
    tags: { tag_name: 'initiate_contact_change' },
  });

  initiateContactChangeDuration.add(res.timings.duration);

  // Accept 200 (initiated) or 409 (challenge already in progress from same VU)
  check(res, {
    'status is 200 or 409': (r) => r.status === 200 || r.status === 409,
    'response is JSON': (r) => {
      try {
        JSON.parse(r.body);
        return true;
      } catch {
        return false;
      }
    },
  });

  sleep(1);
}
