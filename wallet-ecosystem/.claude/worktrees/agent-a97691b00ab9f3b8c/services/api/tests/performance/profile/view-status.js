/**
 * T163 — K6 performance test: GET /v1/users/profile/status
 * Spec §9: P95 ≤ 200 ms with 100-entry status history seeded.
 *
 * Seeding requirement: The target user's account_status_history table must
 * contain at least 100 rows before this script runs. Seed via the artisan
 * command or a fixture migration in the test environment:
 *
 *   php artisan db:seed --class=ProfileStatusHistorySeeder --count=100 --user-id=<uuid>
 *
 * Then pass the seeded user's JWT in K6_AUTH_TOKEN.
 *
 * Prerequisites:
 *   - API reachable at K6_BASE_URL
 *   - K6_AUTH_TOKEN holds a valid JWT for the seeded user (100 history rows)
 *
 * Run:
 *   k6 run -e K6_BASE_URL=http://localhost:8000 \
 *          -e K6_AUTH_TOKEN=<jwt-for-seeded-user> \
 *          -e K6_VUSER_COUNT=20 \
 *          tests/performance/profile/view-status.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, THRESHOLDS, authHeaders, buildOptions } from './config.js';

const viewStatusDuration = new Trend('view_status_duration', true);

export const options = buildOptions('view_status', THRESHOLDS.VIEW_STATUS.p95);

export default function () {
  const res = http.get(`${BASE_URL}/v1/users/profile/status`, {
    headers: authHeaders(),
    tags: { tag_name: 'view_status' },
  });

  viewStatusDuration.add(res.timings.duration);

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response has data.account_status': (r) => {
      try {
        return JSON.parse(r.body).data?.account_status !== undefined;
      } catch {
        return false;
      }
    },
    'response has data.history': (r) => {
      try {
        return Array.isArray(JSON.parse(r.body).data?.history);
      } catch {
        return false;
      }
    },
  });

  sleep(0.5);
}
