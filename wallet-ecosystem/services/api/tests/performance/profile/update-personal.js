/**
 * T161 — K6 performance test: PUT /v1/users/profile/personal
 * Spec §9: P95 ≤ 300 ms under sustained load.
 *
 * Each VU uses a unique Idempotency-Key per iteration (${__VU}-${__ITER}) so
 * the idempotency layer does not short-circuit later iterations from the same VU.
 *
 * Prerequisites:
 *   - API reachable at K6_BASE_URL
 *   - K6_AUTH_TOKEN holds a valid JWT for an existing user profile
 *
 * Run:
 *   k6 run -e K6_BASE_URL=http://localhost:8000 \
 *          -e K6_AUTH_TOKEN=<jwt> \
 *          -e K6_VUSER_COUNT=20 \
 *          tests/performance/profile/update-personal.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, THRESHOLDS, authHeaders, buildOptions } from './config.js';

const updatePersonalDuration = new Trend('update_personal_duration', true);

export const options = buildOptions('update_personal', THRESHOLDS.UPDATE_PERSONAL.p95);

export default function () {
  // Unique key per VU-iteration pair prevents the idempotency cache from
  // returning a cached response for every iteration after the first.
  const idempotencyKey = `perf-vu${__VU}-iter${__ITER}`;

  const payload = JSON.stringify({
    first_name: `PerfUser${__VU}`,
    last_name: 'K6Test',
    preferred_language: 'en',
  });

  const headers = {
    ...authHeaders(),
    'Idempotency-Key': idempotencyKey,
  };

  const res = http.put(`${BASE_URL}/v1/users/profile/personal`, payload, {
    headers,
    tags: { tag_name: 'update_personal' },
  });

  updatePersonalDuration.add(res.timings.duration);

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response has data.user_id': (r) => {
      try {
        return JSON.parse(r.body).data?.user_id !== undefined;
      } catch {
        return false;
      }
    },
    'profile_photo_key absent': (r) => !r.body.includes('profile_photo_key'),
  });

  sleep(0.5);
}
