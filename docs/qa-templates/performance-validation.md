# Performance Validation Template

## Test Information

| Field | Value |
|-------|-------|
| Feature / PR | <!-- e.g., US-042 / PR-123 --> |
| Tester | <!-- Name --> |
| Date | <!-- YYYY-MM-DD --> |
| Environment | <!-- staging / test (never production) --> |
| Tool | <!-- k6 / Artillery / wrk --> |
| Test Script | <!-- Path to script file --> |
| Build / Tag | <!-- Git tag or commit SHA --> |

---

## Load Test Results

| Endpoint | Method | Virtual Users | Duration | p50 (ms) | p95 (ms) | p99 (ms) | RPS | Error Rate | Target Met |
|----------|--------|---------------|----------|----------|----------|----------|-----|------------|------------|
| <!-- /v1/... --> | <!-- GET/POST --> | | | | | | | <!-- % --> | <!-- PASS/FAIL --> |
| | | | | | | | | | |

**Platform latency targets** (from spec SC-002):

- Read endpoints: p95 ≤ 200 ms
- Write endpoints: p95 ≤ 500 ms
- Error rate: < 0.1% under steady load

---

## Database Query Plan

For any new or modified queries, include the `EXPLAIN ANALYZE` output or summary:

| Query | Execution Plan Summary | Seq Scan? | Index Used | Rows Estimated / Actual |
|-------|------------------------|-----------|------------|-------------------------|
| <!-- SELECT ... --> | | <!-- Yes/No --> | <!-- Index name or N/A --> | |

**Slow query threshold**: queries taking > 100 ms flagged for review.

---

## Connection Pool Metrics

Captured during load test peak:

| Resource | Metric | Observed | Limit | Status |
|----------|--------|----------|-------|--------|
| PostgreSQL pool | Active connections | | <!-- e.g., 20 --> | <!-- OK / WARN --> |
| PostgreSQL pool | Waiting queue depth | | 0 ideally | |
| Redis | Connected clients | | <!-- e.g., 50 --> | |
| Redis | Memory used | | <!-- e.g., 256 MB --> | |
| API container | CPU % | | <!-- e.g., 80% --> | |
| API container | Memory MB | | <!-- e.g., 512 MB --> | |

---

## Baseline Comparison

| Metric | Baseline (main branch) | This PR | Delta | Regression? |
|--------|------------------------|---------|-------|-------------|
| p95 latency (primary endpoint) | | | | <!-- Yes/No --> |
| RPS at steady state | | | | |
| Error rate | | | | |

**Baseline captured**: <!-- Date + build tag of baseline measurement -->

---

## Result

**Overall Performance Result**: <!-- PASS / FAIL / CONDITIONAL PASS -->
**Regressions**: <!-- None / List -->
**Recommendations**: <!-- Indexing changes, caching, etc. -->
**Reviewer Signature**: <!-- Name + Date -->
