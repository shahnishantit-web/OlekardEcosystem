/**
 * T160 — K6 performance test: GET /v1/users/profile
 * Spec §9: P95 ≤ 200 ms under sustained load.
 *
 * Prerequisites:
 *   - API reachable at K6_BASE_URL
 *   - K6_AUTH_TOKEN holds a valid JWT for an existing user profile
 *
 * Run:
 *   k6 run -e K6_BASE_URL=http://localhost:8000 \
 *          -e K6_AUTH_TOKEN=<jwt> \
 *          -e K6_VUSER_COUNT=20 \
 *          tests/performance/profile/view-profile.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, THRESHOLDS, authHeaders, buildOptions } from './config.js';

const viewProfileDuration = new Trend('view_profile_duration', true);

export const options = buildOptions('view_profile', THRESHOLDS.VIEW_PROFILE.p95);

export default function () {
  const res = http.get(`${BASE_URL}/v1/users/profile`, {
    headers: authHeaders(),
    tags: { tag_name: 'view_profile' },
  });

  viewProfileDuration.add(res.timings.duration);

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
