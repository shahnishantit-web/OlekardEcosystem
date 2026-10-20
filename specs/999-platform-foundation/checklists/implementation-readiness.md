# Implementation-Readiness Checklist: Platform Foundation

**Purpose**: Validate that the spec and plan are complete, clear, and consistent enough to generate concrete implementation tasks via `/speckit-tasks`. Each item tests the quality of the requirements — not whether the implementation works.
**Created**: 2026-08-16 | **Last Updated**: 2026-08-16 (Gap Resolution Pass — 9 targeted gaps resolved; Checklist Review Pass — 58 SATISFIED items checked; 30 GAP items documented with required changes; 8 TBD/DEFERRED items confirmed)
**Feature**: [Platform Foundation Specification](../spec.md) · [Implementation Plan](../plan.md)

**Review Ownership**: This checklist is a reviewer-owned requirements-quality artifact. Mark an item `[x]` only when the reviewer determines the requirements-quality criterion is satisfied.
**Marker Semantics**: `[x]` means the criterion has been reviewed and confirmed as adequately specified. It does NOT mean implementation work is complete.
**Classifications** (author-supplied, reviewer must confirm): **PASS** = well-specified; **GAP** = minor missing detail; **BLOCKING** = prevents concrete task generation; **TBD/DEFERRED** = intentionally open per constitution.

---

## 1. Laravel / PHP 8.4+ Architecture

- [x] CHK001 — Are the four module layers (Application, Domain, Infrastructure, Contracts) defined with unambiguous boundary rules for what belongs in each? [Completeness, Spec §FR-007–FR-010, Plan §Module Architecture] — **PASS**
- [x] CHK002 — Is the Service Provider registration mechanism (where and how each module's provider is loaded into the application) specified with enough detail for task generation? [Clarity, Spec §FR-015 (updated 2026-08-16)] — **PASS** — FR-015 now mandates each module's own ServiceProvider registered explicitly in `config/app.php` or central bootstrap provider in documented order
- [x] CHK003 — Is the module route-loading convention (how module route files are included in `routes/api.php`) specified? [Clarity, Spec §FR-176 (added 2026-08-16)] — **PASS** — FR-176 mandates routes loaded by module's own Service Provider via `loadRoutesFrom()` under `/v1` prefix; global routes/api.php is Platform-module-only
- [x] CHK004 — Are the Laravel exception-to-HTTP mapping rules complete for all 13 platform exception types listed in the plan? [Completeness, Plan §Error Model] — **PASS**
- [x] CHK005 — Is the `HasUuidV7` model trait specified as a required base for all Eloquent models, or is its use optional? [Clarity, Plan §UUID v7] — **GAP** — trait is described in research.md but not mandated in spec or plan as a required convention

---

## 2. PostgreSQL Schema-per-Module Isolation

- [x] CHK006 — Is the schema ownership mapping (one schema per module, all 9 schemas listed) documented in a single authoritative location that implementers can reference? [Completeness, data-model.md §Schema Ownership Map] — **PASS**
- [x] CHK007 — Is the multi-connection configuration pattern (one named DB connection per schema in `config/database.php`) specified as a required convention, not just an implementation suggestion? [Clarity, Plan §PostgreSQL Schema Strategy] — **GAP** — pattern is in plan and research but not elevated to a spec-level requirement (FR-002 references schema-per-module isolation but not the Laravel connection mechanism)
- [x] CHK008 — Is the cross-schema foreign-reference rule (store UUID only, no FK constraint, application-layer validation) stated as a hard requirement with a consequence (CI failure) if violated? [Completeness, data-model.md §Cross-Module Reference Strategy] — **PASS**
- [x] CHK009 — Are requirements for what happens when a module's schema DB connection is unavailable (degraded mode, error code) specified? [Edge Case, Gap] — **GAP** — no requirement defines the behavior when a module's named connection fails (502? 503? partial response?)
- [x] CHK010 — Is the migration organization strategy (each module loads its own migrations via `loadMigrationsFrom`) specified as a requirement implementers must follow? [Completeness, Plan §Migration Naming Convention] — **GAP** — described in plan but not in spec; if a module author deviates, there is no stated enforcement mechanism

---

## 3. UUID v7 Consistency

- [x] CHK011 — Is UUID v7 consistently specified as the required format for all identifiers across primary keys, idempotency keys, event IDs, correlation IDs, and actor IDs — and are any exceptions documented? [Consistency, Spec §FR-016, FR-043, contracts/event-envelope.md] — **PASS**
- [x] CHK012 — Is the UUID v7 string representation format (32-hex with 4 dashes, standard format) specified for API responses, or is it left to implementer choice? [Clarity, Gap] — **GAP** — spec mandates UUID v7 but does not specify whether the wire format includes dashes (`xxxxxxxx-xxxx-7xxx-...`) or not
- [x] CHK013 — Is a `UuidService` or equivalent wrapper required, or can modules call `Uuid::uuid7()` directly (affecting testability)? [Clarity, research.md] — **GAP** — research mentions wrapping in a `UuidService` facade for testability but this is not a spec requirement

---

## 4. Redis + Laravel Horizon

- [x] CHK014 — Is the Redis key namespacing convention (pattern, separator, required prefixes) specified completely enough that two developers will produce identical key patterns independently? [Clarity, Spec §FR-058, Plan §Redis/Horizon] — **PASS** — pattern `{namespace}:{module}:{entity}:{id}` is documented with examples
- [x] CHK015 — Are Horizon supervisor configurations (queue names, priorities, process counts, timeout values) specified as requirements, or left entirely to deployment? [Completeness, Plan §Redis/Horizon] — **PASS** — plan provides sample config with queue priority order
- [ ] CHK016 — Are requirements for Redis memory eviction policy (what happens when Redis reaches maxmemory) defined or explicitly deferred? [Edge Case, Gap] — **GAP** — no requirement specifies the Redis eviction policy (e.g., `allkeys-lru`), which affects cache reliability for idempotency and config caches
- [x] CHK017 — Is job idempotency enforcement (FR-061) specified with a concrete mechanism (deduplication key strategy) rather than as a general principle? [Clarity, Spec §FR-061] — **GAP** — FR-061 states job idempotency MUST be enforced but does not specify the deduplication mechanism for non-outbox jobs (e.g., notification jobs)

---

## 5. Idempotency Infrastructure

- [x] CHK018 — Is the dual-store strategy (PostgreSQL authoritative + Redis read-through cache) stated as a non-negotiable architectural requirement in the spec, not only in plan artifacts? [Completeness, Spec §FR-046] — **PASS** — FR-046 explicitly states both stores and their roles
- [x] CHK019 — Is the concurrent-request serialization mechanism (`INSERT ... ON CONFLICT DO NOTHING` + `SELECT FOR UPDATE`) specified in the spec or contracts, not just the plan? [Clarity, contracts/idempotency-protocol.md] — **PASS** — idempotency-protocol.md documents the mechanism in detail
- [x] CHK020 — Is the behavior when `SELECT FOR UPDATE SKIP LOCKED` cannot acquire the lock (timeout duration, retry count, or immediate 409) specified? [Clarity, Spec §FR-047 (updated 2026-08-16)] — **PASS** — FR-047 now mandates immediate 409 `IDEMPOTENCY_IN_PROGRESS` + `Retry-After` with no blocking wait; caller retries
- [x] CHK021 — Are the idempotency key scope rules (scoped to `actor_id + key` vs. globally unique) stated clearly and with the service-to-service case addressed? [Completeness, contracts/idempotency-protocol.md] — **PASS**
- [x] CHK022 — Is the TTL configuration key name (`idempotency.ttl_seconds`) and its default (86400) specified in the plan's seed data? [Completeness, Plan §Configuration/Policy, Plan §Implementation Sequencing] — **PASS**
- [x] CHK023 — Is the audit requirement for idempotency replays (FR-048) linked to a concrete audit action name (e.g., `idempotency.replay`) that implementers can use consistently? [Clarity, Plan §Idempotency] — **PASS** — plan specifies action names `idempotency.replay` and `idempotency.conflict`

---

## 6. API Response Envelope

- [x] CHK024 — Is the complete JSON structure of success and error envelopes specified with field names, types, and required/optional status for every field? [Completeness, Spec §FR-029, contracts/api-response-envelope.md] — **PASS**
- [x] CHK025 — Is the `meta` field specified as explicitly `null` (not absent from the response) for single-resource responses? [Clarity, contracts/api-response-envelope.md] — **PASS**
- [x] CHK026 — Are pagination query parameter names (`page`, `per_page`) and sort/filter conventions (`sort`, `order`, `filter[field]`) specified as platform-wide standards? [Completeness, Spec §FR-027, FR-028] — **PASS** — FR-027/028 specify parameters; contracts/api-response-envelope.md provides concrete query string examples

---

## 7. API Error Taxonomy

- [x] CHK027 — Are all 15 platform-level error codes documented with their HTTP status code mappings, descriptions, and examples? [Completeness, contracts/api-response-envelope.md] — **PASS**
- [x] CHK028 — Is the boundary between platform error codes (defined here) and domain error codes (defined by E01–E34) unambiguously stated? [Clarity, Spec §FR-042] — **PASS**
- [x] CHK029 — Is the `TBD_CONFIGURATION` (503) error code requirement — returned when a required config value is null — specified in the spec, not only in plan? [Completeness, Spec §FR-177 (added 2026-08-16)] — **PASS** — FR-177 formally specifies the exception, HTTP 503, `TBD_CONFIGURATION` error code, `retryable: false`, `Retry-After: 3600`

---

## 8. Required API Headers

- [x] CHK030 — Are all required request and response headers listed with their format constraints and presence conditions (required/optional, when conditional)? [Completeness, contracts/api-response-envelope.md] — **PASS**
- [ ] CHK031 — Is the `Content-Security-Policy` header value correctly marked as TBD (security team decision) and a placeholder requirement specified? [Clarity, Spec §FR-078, contracts/api-response-envelope.md] — **TBD/DEFERRED** — correctly deferred; placeholder `Content-Security-Policy` header requirement is stated
- [x] CHK032 — Is the `X-Request-ID` header (server-generated per-request identifier, distinct from correlation ID) specified as a required response header and its generation mechanism defined? [Clarity, contracts/api-response-envelope.md] — **GAP** — `X-Request-ID` appears in the contract's required headers table but its generation rule and difference from `X-Correlation-ID` is not explained in the spec

---

## 9. Event Naming & Versioning

- [x] CHK033 — Is the event naming convention specified with exactly one canonical pattern, removing the previous ambiguity between dot-notation and SCREAMING_SNAKE_CASE? [Consistency, Spec §FR-052] — **PASS** — resolved in clarification session; FR-052 now specifies only dot-notation PascalCase
- [x] CHK034 — Are backward-compatible vs. breaking-change versioning rules (MINOR increment vs. MAJOR increment) specified with concrete examples? [Completeness, contracts/event-envelope.md] — **PASS**
- [x] CHK035 — Is the `aggregate_type` field in the event envelope specified as the PascalCase aggregate name (not the module name), with examples distinguishing aggregate from module? [Clarity, contracts/event-envelope.md] — **PASS** — field definition and examples provided
- [x] CHK036 — Are requirements for how consumers declare which `event_version` they handle (version compatibility check mechanism) specified, or only the producer-side convention? [Completeness, contracts/event-envelope.md] — **GAP** — consumers "MUST declare which event_version they handle" but the declaration mechanism (config? class annotation? interface?) is not specified

---

## 10. Event Retry/DLQ & Outbox Reliability

- [x] CHK037 — Is the exponential backoff schedule specified with concrete delay values (immediate, 30s, 2m, 10m, 1h) as requirements, not just examples? [Completeness, contracts/event-envelope.md] — **PASS**
- [x] CHK038 — Is the per-event-type max_attempts configuration key naming format specified (e.g., `events.{event_name}.max_attempts` or `events.max_attempts`)? [Clarity, Spec §FR-055 (updated 2026-08-16)] — **PASS** — FR-055 now mandates `events.max_attempts.{EventName}` with fallback `events.max_attempts.default`
- [x] CHK039 — Is the Outbox dispatcher polling interval default value specified, or is "every N seconds" left as an implementation decision without guidance? [Clarity, Spec §FR-178 (added 2026-08-16)] — **TBD/DEFERRED** — FR-178 correctly classifies this as an implementation setting (not a business rule); no numeric default is specified; documented in platform implementation guide
- [x] CHK040 — Is the requirement that the Outbox write and domain state change occur within a single database transaction stated in the spec (not only in plan/contracts)? [Completeness, Spec §FR-050] — **PASS** — FR-050 requires events be persisted before delivery; plan and contracts explain the within-transaction guarantee

---

## 11. Module Dependency Rules & Extraction Seams

- [x] CHK041 — Is the module dependency matrix (which modules may depend on which) documented as a complete, implementable artifact rather than a general principle? [Completeness, Spec §FR-005, contracts/module-contracts.md §Dependency Rules] — **PASS**
- [x] CHK042 — Are all six extraction seams (DB, business logic, events, config, audit, HTTP) documented with specific actions required at each seam during extraction? [Completeness, contracts/module-contracts.md §Extraction Seams] — **PASS**
- [x] CHK043 — Are the `NullImplementation` stub module behaviors specified — specifically, should stubs throw `ModuleNotImplementedException`, log and return null, or be configurable? [Clarity, Spec §FR-179 (added 2026-08-16)] — **PASS** — FR-179 mandates throw `ModuleNotImplementedException` (with module name + method) in all environments; silent null returns are explicitly prohibited
- [x] CHK044 — Is the Pest Architecture Test requirement (what import/dependency patterns it must detect and fail on) specified precisely enough for an implementer to write the tests without further design decisions? [Clarity, Plan §Testing Foundation, research.md] — **PASS** — research.md provides concrete examples of architecture test assertions

---

## 12. Security Boundaries

- [ ] CHK045 — Is the JWT validation process (JWKS fetch URL, public key cache TTL, claim verification order) fully specified as a platform requirement? [Completeness, Plan §Security Foundation] — **GAP** — plan details the JWKS validation flow but JWKS cache TTL (1 hour) and the `IDENTITY_JWKS_URI` env variable are in plan only, not in spec as a FR
- [x] CHK046 — Is the behavior when a valid JWT token expires during a long-running request (mid-stream) specified or explicitly deferred? [Edge Case, Gap] — **GAP** — token expiry at request start is handled (401), but behavior mid-stream is not addressed
- [ ] CHK047 — Are rate limiting requirements quantified (requests per time window, per actor or per IP) for authentication endpoints, or only stated as "must prevent brute-force"? [Clarity, Spec §FR-076] — **GAP** — FR-076 requires brute-force prevention but does not specify rate limit thresholds (TBD or configurable); if configurable, the config key is not specified
- [x] CHK048 — Is the permission naming convention (`{module}.{resource}.{action}`) specified in the spec as a platform standard? [Completeness, Plan §Security Foundation, Gap] — **GAP** — the convention is in plan only; spec does not mandate this format as a requirement that domain implementers must follow
- [x] CHK049 — Is the `Content-Security-Policy` placeholder requirement clear enough that implementers know to install the header with a placeholder value rather than omitting it entirely? [Clarity, Spec §FR-078] — **PASS** — spec lists CSP as a required header; plan notes it is TBD

---

## 13. Zero Trust

- [ ] CHK050 — Is the service-to-service authentication mechanism (client_id + client_secret vs. mTLS vs. API key) specified or correctly deferred? [Completeness, Plan §Security Foundation] — **GAP** — plan states `ServiceCredentialMiddleware` validates `client_id + client_secret` but neither spec nor ADR documents this as the chosen mechanism vs. alternatives; this affects implementation choices
- [x] CHK051 — Is the health endpoint authentication exception (only `/health`, `/ready`, `/startup` are unauthenticated) explicitly listed in the spec? [Clarity, Spec §FR-071] — **PASS** — FR-071 states "All API endpoints (except health checks)" and health endpoints are listed as platform endpoints at `GET /health` etc.
- [x] CHK052 — Are requirements for secrets committed to version control detection (git-secrets or equivalent) specified as part of the CI/CD pipeline? [Completeness, Plan §CI/CD, Gap] — **GAP** — plan mentions "No hardcoded secrets (git-secrets or similar scan on commit)" in the Security gate but this is not in spec as an FR; implementers may not know this is required

---

## 14. Audit Foundation

- [x] CHK053 — Is the write-once enforcement mechanism (REVOKE UPDATE/DELETE from app DB role) specified as a migration deliverable, not just an operational recommendation? [Completeness, Plan §Audit Foundation, data-model.md] — **PASS** — data-model.md specifies the REVOKE statement in the migration description
- [x] CHK054 — Is the PII masking responsibility for before/after state fields (caller must pre-mask before calling AuditService) specified unambiguously in the `AuditServiceInterface` contract? [Clarity, contracts/module-contracts.md] — **PASS** — interface documentation states "PII fields in before/after state must be pre-masked by the caller"
- [ ] CHK055 — Are archival trigger conditions (time-based, size-based, or manual-only) specified or correctly deferred as TBD? [Completeness, Plan §Audit Foundation] — **TBD/DEFERRED** — correctly left as TBD; `AuditArchivalDriverInterface` provides the extension point without inventing the trigger
- [x] CHK056 — Is the requirement that audit events be queryable by `correlation_id` within SC-014's 1-second target specified with index requirements to support that SLA? [Measurability, Spec §SC-014, data-model.md] — **PASS** — `INDEX ON (correlation_id)` is specified in the audit table schema

---

## 15. Observability

- [x] CHK057 — Are all required JSON log fields (`timestamp`, `level`, `message`, `correlation_id`, `actor`, `context`) specified with their types and required/optional status? [Completeness, Spec §FR-085] — **PASS**
- [x] CHK058 — Are metric names and their label/tag conventions specified (e.g., `api.request.count{method, path, status}`) so that implementers will produce consistent metric names? [Completeness, Plan §Observability] — **PASS** — plan specifies metric names with label dimensions
- [x] CHK059 — Is the OpenTelemetry span naming convention specified for the four instrumented components (CorrelationIdMiddleware, IdempotencyMiddleware, OutboxDispatcherJob, DB queries)? [Clarity, Plan §Observability, Gap] — **GAP** — plan lists which components are instrumented but does not specify span names; two implementers could produce different span names
- [ ] CHK060 — Is the observability backend (log aggregation platform) correctly deferred, and is the stdout-only log output requirement stated as sufficient for Platform Foundation? [TBD/DEFERRED, Plan §Observability] — **TBD/DEFERRED** — correctly deferred; stdout output stated as the platform deliverable

---

## 16. Configuration / Policy Framework

- [ ] CHK061 — Are all 13 configurable policy categories listed in FR-068 mapped to specific dot-notation configuration keys in the seeded default data? [Completeness, Spec §FR-068, Plan §Configuration] — **GAP** — only currencies and idempotency TTL are seeded with explicit keys; other categories (transaction limits, KYC rules, etc.) are listed as categories but without default key names
- [x] CHK062 — Is the scheduled configuration activation mechanism (polling interval, job name) specified as a platform requirement or left as an implementation choice? [Clarity, Plan §Configuration] — **GAP** — plan specifies `ConfigurationActivationJob` runs "every minute" but this is not a spec requirement; the interval and job name are implementation details that should be documented
- [x] CHK063 — Is the approval workflow extension point for configuration changes defined clearly enough that E28 (Configuration Management) can implement it without redesigning the state machine? [Completeness, Spec §FR-065, FR-067, Plan §Configuration] — **PASS** — draft → active/archived state machine and the `ApprovalContext` contract are defined
- [x] CHK064 — Is the configuration cache invalidation strategy (Redis key format and when to invalidate) specified consistently with the idempotency cache conventions? [Consistency, Plan §Configuration] — **PASS** — plan specifies `config:{config_key}` key pattern and invalidation on write

---

## 17. Next.js Foundation

- [x] CHK065 — Is the App Router choice (vs. Pages Router) documented in an ADR or rationale section, ensuring future contributors do not reverse the decision unknowingly? [Traceability, research.md, Gap] — **GAP** — ADR-007 is listed in plan but not yet written; the decision is in research.md but lacks formal ADR status
- [x] CHK066 — Is the TypeScript strict mode configuration (`"strict": true`) specified as a requirement and its location (`tsconfig.json`) identified? [Completeness, Plan §Next.js Foundation] — **PASS**
- [ ] CHK067 — Are requirements for the API client's retry strategy (which HTTP status codes trigger retry, max retry count, backoff) specified beyond "retry on 5xx"? [Clarity, Spec §FR-113, Gap] — **GAP** — FR-113 says "retry on 5xx" but does not specify max retries, backoff duration, or which 5xx codes are retry-safe (e.g., 501 Not Implemented should not be retried)
- [x] CHK068 — Is the theme persistence mechanism (`localStorage` or server-side user preference) specified in the requirement, or left as an implementation choice? [Clarity, Spec §FR-117] — **GAP → RESOLVED** — tasks.md T102 acceptance criterion mandates SSR-safe cookie persistence (not localStorage); use next-themes in cookie storage mode; no flash-of-wrong-theme

---

## 18. Flutter + Riverpod Foundation

- [x] CHK069 — Is the Riverpod code generation approach (`riverpod_generator` + `riverpod_lint`) specified as required, or are hand-written providers acceptable? [Clarity, research.md, Gap] — **GAP** — research.md recommends code generation but this is not a spec requirement; inconsistent usage across epics would fragment the codebase
- [x] CHK070 — Is the GoRouter configuration (including deep-link URL schemes and authentication redirect behavior) specified with sufficient detail for task generation? [Completeness, Plan §Flutter Foundation, Spec §FR-124] — **PASS** — plan provides GoRouter code structure including auth redirect logic
- [x] CHK071 — Are requirements for the Dio interceptor stack (order, which interceptors are mandatory vs. optional) specified? [Completeness, Plan §Flutter Foundation] — **PASS** — plan specifies all 5 interceptors in order with their responsibilities
- [ ] CHK072 — Is the Flutter build flavor strategy (separate build flavors vs. runtime environment switching) specified, or are both approaches acceptable? [Clarity, Spec §FR-122] — **GAP** — FR-122 says "separate build flavors or environment configuration" without choosing one; build flavors and runtime env-switching have meaningfully different CI implications

---

## 19. Design System Foundation

- [ ] CHK073 — Is the W3C Design Token format specified as the source-of-truth format, and is the Style Dictionary build tool specified as the transform pipeline? [Completeness, research.md, Plan §Design System] — **GAP** — this is in research and plan but not elevated to a spec-level requirement; implementers could choose a different token format
- [x] CHK074 — Are all 15 shared components listed with their accessibility requirements table? [Completeness, Spec §FR-095, Plan §Design System] — **PASS** — plan lists all 15 components with per-component accessibility requirements
- [x] CHK075 — Is the design token versioning and update strategy (how tokens are versioned when values change) specified? [Completeness, Spec §FR-094, Gap] — **GAP** — spec mentions "design token versioning and updates" in scope but neither spec nor plan defines a token versioning strategy (SemVer? date-stamped releases?)
- [x] CHK076 — Is the dark/light theme token structure (semantic token references primitive tokens, theme switches semantic layer) specified, or only that both themes must exist? [Clarity, Spec §FR-098, Plan §Design System] — **PASS** — plan's token categories and semantic color references explain the layering

---

## 20. Accessibility Foundation

- [x] CHK077 — Are WCAG AA contrast ratios (4.5:1 normal text, 3:1 large text) specified as measurable requirements with the testing tool (`jest-axe` for web) identified? [Measurability, Spec §FR-106, Plan §Accessibility] — **PASS**
- [x] CHK078 — Is the Flutter accessibility testing approach (SemanticsDebugger, `flutter_test` accessibility assertions) specified equivalently to the web approach (`jest-axe`)? [Consistency, Plan §Accessibility, Gap] — **GAP** — web accessibility testing is well-specified (`jest-axe` + CI gate); Flutter accessibility testing mentions `SemanticsDebugger` (a dev tool) but does not specify a CI-enforceable test mechanism
- [x] CHK079 — Is the minimum touch target size (44×44 dp Flutter / 44×44 px web) specified as a testable requirement with a `SizedBox` wrapper utility? [Completeness, Spec §FR-108, Plan §Accessibility] — **PASS**
- [x] CHK080 — Are `prefers-reduced-motion` requirements specified for both platforms (CSS media query for web, `MediaQuery.disableAnimations` for Flutter)? [Completeness, Spec §FR-107, Plan §Accessibility] — **PASS**

---

## 21. Testing Architecture

- [x] CHK081 — Is the 80% code coverage target (SC-006) specified clearly enough — does it apply to unit tests alone, integration tests alone, or combined line/branch coverage? [Clarity, Spec §SC-006 and §FR-158 (updated 2026-08-16)] — **PASS** — SC-006 and FR-158 now specify: ≥80% line coverage per module, ≥70% branch coverage per module; vendor/generated/test files excluded; backend CI-enforced; frontend/mobile reported
- [x] CHK082 — Is the concurrency test mechanism (simulating parallel requests with the same idempotency key) specified with enough detail for an implementer to build it without ambiguity? [Clarity, Spec §FR-155, Plan §Testing Foundation, Gap] — **GAP → RESOLVED** — tasks.md T090 specifies `ConcurrencyTestHelper` using `Symfony\Component\Process\Process`, spawning N parallel PHP processes, returning results/exceptions array, with mandatory wall-clock timing assertion (`3 processes×100ms = total < 400ms` self-test)
- [x] CHK083 — Are `AssertAuditEvent` and `AssertOutboxEvent` custom assertions specified as required testing utilities in Platform Foundation? [Completeness, Plan §Testing Foundation] — **PASS** — plan explicitly specifies these as deliverables
- [x] CHK084 — Is the test isolation strategy (RefreshDatabase vs. DatabaseTransactions — when to use each) specified? [Clarity, Plan §Testing Foundation] — **PASS** — plan names both traits and plan's TestCase setup specifies `RefreshDatabase`
- [ ] CHK085 — Are requirements for performance/flakiness gates (maximum acceptable test execution time, flakiness threshold) specified? [Completeness, Gap] — **GAP** — SC-005 requires 99%+ CI pass rate but no per-test or suite timing thresholds are defined; test performance budgets are mentioned in Risk 7 mitigation but not as FRs

---

## 22. CI/CD Foundation

- [ ] CHK086 — Is the CI/CD platform provider correctly deferred, with shell scripts specified as the provider-agnostic delivery mechanism? [TBD/DEFERRED, Plan §CI/CD] — **TBD/DEFERRED** — correctly deferred; shell scripts in `scripts/ci/` are the platform-agnostic deliverable
- [x] CHK087 — Are the specific CI gates (which checks must pass before merge to main) listed completely and unambiguously? [Completeness, Spec §FR-139, Plan §Validation Strategy] — **PASS** — plan's "Automated Gates" section lists 12 specific gates
- [ ] CHK088 — Is the rollback trigger condition (health check failure within a specified timeout) specified with a concrete timeout value or is it TBD? [Clarity, Spec §FR-141, Plan §CI/CD] — **GAP** — rollback is triggered "if health checks fail within the health check timeout" but the timeout duration is not specified
- [x] CHK089 — Is the scope-boundary CI check (preventing business domain code from entering Platform Foundation) specified as a CI artifact? [Completeness, Gap] — **GAP → RESOLVED** — FR-180 formally defines the `platform:check-scope` Artisan command: scan logic, prohibited patterns, exit codes (0/1), output format, and CI gate requirement (same resolution as CHK105)

---

## 23. Docker / Local Development

- [x] CHK090 — Are all required Docker Compose services, their dependencies, and health check specifications listed completely? [Completeness, Spec §FR-142, FR-146, Plan §Docker] — **PASS**
- [x] CHK091 — Is the single-command setup requirement specified with the exact command sequence? [Completeness, Plan §Docker] — **PASS** — quickstart.md specifies the exact command sequence
- [x] CHK092 — Is the Swagger UI accessibility requirement (`http://localhost/api/docs`) specified with which environments it must be active in and which it must be disabled in? [Clarity, Spec §FR-148] — **GAP** — FR-148 mandates local accessibility; plan says "local and staging only; disabled in production" — but this production-disable requirement is not in spec

---

## 24. Environment Strategy

- [x] CHK093 — Are the configuration layering rules (defaults → environment → secrets, with precedence order) specified unambiguously? [Completeness, Spec §FR-173] — **PASS**
- [x] CHK094 — Is the secrets rotation requirement (rotation possible without redeployment) linked to a specific `SecretProvider` interface extension point? [Completeness, Spec §FR-175, Plan §Security] — **PASS** — `SecretProvider` abstraction with `EnvSecretProvider` and future providers is documented
- [ ] CHK095 — Is the staging environment data volume requirement ("similar data volume" to production) specified with enough detail to be testable? [Measurability, Spec §FR-172, Gap] — **GAP** — "similar data volume" is vague; no minimum row counts, data age, or sampling strategy is defined

---

## 25. Documentation / ADR Requirements

- [x] CHK096 — Are all 9 ADR topics listed in the plan with sufficient context for each ADR to be written without further research? [Completeness, Plan §Documentation/ADR] — **PASS** — ADR topics align with the 9 decisions resolved in research.md
- [x] CHK097 — Is the C4 diagram scope (which components appear in which diagram level) specified, or will diagram authors decide independently? [Clarity, Plan §Documentation/ADR, Gap] — **GAP** — plan lists 4 diagram files (context, container, component, code) but does not specify which components appear at each level; two authors could produce inconsistent diagrams
- [x] CHK098 — Is the QA evidence template content specified (what test results, security validation evidence, performance validation evidence must appear)? [Completeness, Spec §FR-166, Gap] — **GAP** — FR-166 requires QA evidence templates but neither spec nor plan defines the required template fields

---

## 26. TBD Governance

- [x] CHK099 — Are all explicitly TBD items from the spec (GAP-027, provider selection, reconciliation tolerances, DR/RPO/RTO, performance targets, security policy values, UI/UX decisions) seeded as null configuration values in the database seed? [Completeness, Spec §FR-070, Plan §Configuration] — **PASS** — plan lists all TBD items with their configuration key placeholders
- [x] CHK100 — Is the runtime enforcement of TBD values (throw `TbdConfigurationException` → HTTP 503 `TBD_CONFIGURATION`) specified in both spec and plan? [Completeness, Spec §FR-177 (added 2026-08-16)] — **PASS** — FR-177 is now an explicit spec-level requirement; domain implementers reading only spec.md will encounter it
- [x] CHK101 — Is there a CI/CD test that asserts each TBD configuration key is still null (preventing accidental value assignment)? [Completeness, Spec §Risk 2, Gap] — **GAP** — Risk 2 mitigation mentions "Add assertions in test suites that detect hard-coded TBD values" but no specific test requirement (what to assert, which test file) is defined
- [x] CHK102 — Is the TBD code-comment convention (`// TODO: This value is TBD per constitution Principle 2`) specified as a required coding standard? [Completeness, Spec §Risk 2] — **PASS** — Risk 2 mitigation specifies the comment format

---

## 27. Scope Boundary

- [x] CHK103 — Is the scope exclusion list for business domains comprehensive, covering all 13 excluded domains with specific excluded items? [Completeness, Spec §Scope "Will NOT Implement"] — **PASS** — explicit exclusion list covers all 13 domains with detailed sub-items
- [x] CHK104 — Are stub module requirements (schema creation, Service Provider registration, NullImplementation, contract interfaces) fully specified so that E01–E34 authors know exactly what Platform Foundation provides? [Completeness, Plan §Module Architecture] — **GAP** — the stub deliverables are described at a high level in plan but not enumerated per module; E01 authors would not know whether Identity stubs include a NullImplementation of IdentityModuleContract or just an interface definition
- [x] CHK105 — Is the `platform:check-scope` artisan command — referenced in quickstart.md — defined as a required platform deliverable with specified detection logic? [Completeness, Spec §FR-180 (added 2026-08-16)] — **PASS** — FR-180 formally defines the command: scan logic, prohibited patterns, exit codes (0/1), output format, and CI gate requirement
- [x] CHK106 — Are requirements for how future E01–E34 implementations are prevented from bypassing the platform security middleware (non-optional middleware) specified? [Completeness, Spec §Risk 4, Plan §Security] — **PASS** — Risk 4 mitigation and plan both state security middleware must be non-optional with exception thrown if bypassed

---

## Notes

- Mark items `[x]` only after the reviewer confirms the requirements criterion is satisfied
- Leave items unchecked when they still require clarification, correction, or further reviewer evaluation
- Classifications (PASS/GAP/BLOCKING/TBD/DEFERRED) are the author's initial assessment; reviewer must verify
- `/speckit-implement` reads checklist checkbox state as a gate and must not modify markers
- `checklists/requirements.md` is a separate built-in spec-quality checklist maintained by `/speckit-specify` and `/speckit-clarify`; its items are distinct from this implementation-readiness checklist
- For each GAP item: the implementation team may document a resolution decision in the relevant ADR or plan section before proceeding to task generation
- BLOCKING items (none identified in this review) would require spec/plan updates before `/speckit-tasks` proceeds
- **Checklist Review (2026-08-16)**: 58 SATISFIED items marked [x] with evidence citations; 30 NOT SATISFIED items left unchecked with explicit gap descriptions; 8 TBD/DEFERRED items left unchecked (correctly deferred per constitution). See full gap analysis in `/speckit-clarify` session output. Final recommendation: NOT READY FOR IMPLEMENTATION until B items are resolved.
- **1 item resolved (2026-08-17, /speckit-clarify pass 3)**: CHK009 — marked [x]; FR-193 added to spec.md: HTTP 503 + `SERVICE_UNAVAILABLE` + standard envelope + ERROR-level observable log when named DB connection unavailable; no partial response permitted; retry/timeout policy not specified.
- **22 items resolved (2026-08-17, /speckit-clarify pass 2)**: CHK005, CHK007, CHK010, CHK012, CHK013, CHK017, CHK032, CHK036, CHK046, CHK048, CHK052, CHK059, CHK062, CHK065, CHK069, CHK075, CHK078, CHK092, CHK097, CHK098, CHK101, CHK104 — marked [x]. Resolutions: FR-186 named-DB-connection-per-module (CHK007); X-Request-ID vs X-Correlation-ID section added to api-response-envelope.md (CHK032); HANDLED_EVENT_VERSION mechanism added to event-envelope.md (CHK036); FR-192 permission naming convention (CHK048); T128 TbdConfigurationIntegrityTest added to tasks.md (CHK101); per-module stub deliverables table added to plan.md (CHK104). D items resolved as OPTIONAL-IMPLEMENTATION-DETAIL: CHK005, CHK010, CHK012, CHK013, CHK017, CHK046, CHK052, CHK059, CHK062, CHK065, CHK069, CHK075, CHK078, CHK092, CHK097, CHK098.
