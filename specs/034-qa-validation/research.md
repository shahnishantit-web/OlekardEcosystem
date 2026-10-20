# Research — E34 QA & Validation

## Decision 1 — Test Isolation Strategy

**Decision**: Each test run operates against a named fixture dataset seeded into a dedicated `qa_*` namespace within the platform test database. Financial story test cases (US-QA-004 through US-QA-010) wrap each scenario in a database transaction that is rolled back after assertion, ensuring no permanent ledger mutations. Non-financial test cases (auth, KYC, security, UAT) use fixture-seeded records prefixed `qa.` that are reset between runs.

**Rationale**: Constitution Principle 4 prohibits editing historical ledger entries. Wrapping financial assertions in rolled-back transactions is the standard Laravel `DatabaseTransactions` pattern; it provides test isolation without violating immutability of real data.

**Alternatives considered**: Separate test database schema — ruled out because it diverges from production schema and masks migration bugs; test doubles for domain services — ruled out because the spec requires testing the real implementation (US-QA-001/002 explicitly say "unit test authentication must satisfy the source formal specification").

---

## Decision 2 — Execution Model

**Decision**: All test runs are async. The trigger endpoint (`POST /v1/qa/{category}`) creates a `TestRun` record (status: `pending`) and dispatches a `ExecuteQaTestRunJob` to the `qa` Horizon queue. The job executes each `TestCase` in the run, writes `TestResult` records, and transitions `TestRun` status to `completed` or `failed`. The API returns `202 Accepted` with `run_id`.

**Rationale**: Performance and load test suites (US-QA-014/015) can take minutes. Synchronous execution would block HTTP workers and violate timeout limits. Async jobs are consistent with the E17 notification and E24 reporting patterns already established.

**Alternatives considered**: Synchronous inline execution — rejected for long-running suites; Server-Sent Events streaming — over-engineered for initial implementation.

---

## Decision 3 — Result Storage Schema

**Decision**: New PostgreSQL schema `qa` with tables: `qa.test_cases`, `qa.test_runs`, `qa.test_results`. Schema-per-module pattern consistent with Platform Foundation decision (UUID v7 primary keys).

**Rationale**: Keeps QA data isolated from customer/financial data. Schema-per-module is the established pattern. No foreign keys to other modules' schemas (loose coupling per Principle 7).

**Alternatives considered**: Shared `platform` schema — rejected (tight coupling); external DB — rejected (adds infrastructure complexity).

---

## Decision 4 — Performance & Load Testing

**Decision**: Performance tests (US-QA-014) measure p50/p95/p99 latencies of key API paths by making sequential timed calls (100 iterations per endpoint). Load tests (US-QA-015) use PHP Fibers to drive 50 concurrent requests and measure throughput. Both store duration metrics in `test_results.evidence` JSON. No external load-testing tool dependency (k6, JMeter) required for initial implementation; these can be added later.

**Rationale**: Keeps implementation within the approved stack. External tools can be layered on without changing the result storage model.

**Alternatives considered**: k6 integration — deferred; requires additional infrastructure and secrets management.

---

## Decision 5 — Disaster Recovery Testing

**Decision**: DR tests (US-QA-016) assert readiness rather than triggering a real failover. Checks include: (a) database replica lag < 30s, (b) Redis cluster health, (c) Horizon queue depth < threshold, (d) backup recency < 24h. Actual failover simulation is guarded by `QA_DR_SIMULATION_ENABLED` config flag (default: `false`) and requires explicit operator opt-in. This is a Constitution Principle 2 configurable capability.

**Rationale**: Real DR failover in a non-DR drill window would disrupt service. Readiness assertions deliver meaningful test coverage without risk.

**Alternatives considered**: Full failover simulation always on — rejected; risk of service disruption.

---

## Decision 6 — Security Testing Scope

**Decision**: Security tests (US-QA-013) run automated checks: PHPStan level 8 on all modules, OWASP Top 10 pattern assertions (SQL injection guards, XSS in API responses, auth bypass attempts on known endpoints, rate-limit enforcement). No active penetration (human red-team required per Constitution Principle 6). Results categorized as `passed`, `advisory` (potential risk, not exploitable), or `failed` (exploitable).

**Rationale**: Automated security assertions provide continuous regression coverage. Active penetration requires human sign-off and is out of scope for automated CI.

---

## Decision 7 — UAT Orchestration

**Decision**: UAT (US-QA-017) runs a curated subset of acceptance scenarios across all prior epics (E01–E33), derived from each epic's `quickstart.md` SC-* scenarios. The `UatOrchestrationJob` calls each scenario's endpoint with seeded fixture data and asserts the expected response. Results are stored with `test_case.code = 'uat.{epic}.{sc}'`.

**Rationale**: Provides cross-epic end-to-end validation traceable to the original acceptance criteria.

---

## Resolved: All NEEDS CLARIFICATION items resolved. No blocking TBDs remain.
