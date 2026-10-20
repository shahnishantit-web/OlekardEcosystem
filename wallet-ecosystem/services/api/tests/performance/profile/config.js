/**
 * K6 shared configuration for Profile (E02) performance tests.
 *
 * Environment variables (all optional — defaults shown):
 *   K6_BASE_URL       API root URL          default: http://localhost:8000
 *   K6_AUTH_TOKEN     Bearer token          default: (empty — 401s expected)
 *   K6_VUSER_COUNT    Number of VUs         default: 10
 *   K6_DURATION       Test duration         default: 60s
 *
 * Run a script:
 *   k6 run -e K6_BASE_URL=http://api:8000 \
 *          -e K6_AUTH_TOKEN=<jwt> \
 *          -e K6_VUSER_COUNT=20 \
 *          tests/performance/profile/view-profile.js
 */

export const BASE_URL     = __ENV.K6_BASE_URL    || 'http://localhost:8000';
export const AUTH_TOKEN   = __ENV.K6_AUTH_TOKEN  || '';
export const VUSER_COUNT  = parseInt(__ENV.K6_VUSER_COUNT || '10', 10);
export const DURATION     = __ENV.K6_DURATION    || '60s';

/**
 * P95 latency thresholds per spec §9 Performance Requirements.
 * Keys match the http_req_duration metric trend tag produced by each script.
 */
export const THRESHOLDS = {
  VIEW_PROFILE:           { p95: 200 },
  UPDATE_PERSONAL:        { p95: 300 },
  INITIATE_CONTACT_CHANGE: { p95: 500 },
  VIEW_STATUS:            { p95: 200 },
};

/**
 * Return a standard Authorization + Content-Type header map.
 */
export function authHeaders() {
  return {
    Authorization: `Bearer ${AUTH_TOKEN}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
}

/**
 * Return K6 options suitable for a sustained load test:
 *   - VUSER_COUNT virtual users for the full DURATION
 *   - 10-second graceful ramp-up
 *   - P95 threshold applied to the named http_req_duration trend
 *
 * @param {string} trendTag  Metric tag name used in the caller script (e.g. 'view_profile')
 * @param {number} p95Ms     P95 target in milliseconds
 */
export function buildOptions(trendTag, p95Ms) {
  return {
    scenarios: {
      sustained_load: {
        executor: 'constant-vus',
        vus: VUSER_COUNT,
        duration: DURATION,
        gracefulStop: '10s',
      },
    },
    thresholds: {
      [`http_req_duration{scenario:sustained_load}`]: [`p(95)<${p95Ms}`],
      [`http_req_duration{tag_name:${trendTag}}`]: [`p(95)<${p95Ms}`],
      http_req_failed: ['rate<0.01'],
    },
  };
}
