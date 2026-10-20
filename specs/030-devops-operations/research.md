# Research — E30 DevOps & Operations

## Decision 1: Logging storage and search

**Decision**: Store structured JSON log entries in `ops.log_entries` (PostgreSQL). Expose a log-search API via the Admin Portal backed by PostgreSQL JSONB + GIN index queries.

**Rationale**: The wallet ecosystem stack is self-contained (Laravel + PostgreSQL). Introducing an external log aggregator (ELK, Loki, Datadog) would violate Principle 14 (no silent architecture changes without ADR). PostgreSQL JSONB with a composite GIN index on the `context` column and B-tree indexes on `correlation_id`, `severity`, and `created_at` is adequate for the operational search patterns required (correlation-ID lookup, severity filter, time-range filter). Log entries are partitioned by month to support retention-based deletion.

**Alternatives considered**:
- ELK Stack (Elasticsearch + Logstash + Kibana): Powerful but adds 3 external services; requires ADR.
- Loki + Grafana: Lightweight log aggregator; would require ADR.
- Retain logs only in files, expose no search API: Fails FR-002 (searchable by correlation ID).

---

## Decision 2: Metrics storage

**Decision**: Store raw metrics as time-series rows in `ops.service_metrics` at per-minute granularity. Pre-aggregate to hourly rows via a scheduled Horizon job (`MetricsAggregationJob`). Keep raw rows for 24 hours; keep hourly aggregates for 30 days; keep daily aggregates indefinitely (until configured retention deletes them).

**Rationale**: Avoids an external time-series database (Prometheus, InfluxDB) which would require an ADR. PostgreSQL with a partial index on `(service_name, metric_name, recorded_at DESC)` handles the query patterns required. Laravel Horizon already provides queue-depth metrics natively; the metrics service wraps Horizon's data and augments it with HTTP-layer metrics emitted by a request middleware.

**Alternatives considered**:
- Prometheus + Grafana: Best-in-class for metrics; requires ADR and external infrastructure.
- InfluxDB: Time-series optimized; requires ADR.
- Storing all metrics at raw granularity indefinitely: Table grows unbounded; ruled out.

---

## Decision 3: Health probe security model

**Decision**: Health (`/ops/health`), readiness (`/ops/health/ready`), and liveness (`/ops/health/live`) endpoints are unauthenticated. They are registered outside the `identity.auth` middleware group.

**Rationale**: Standard convention for container/Kubernetes health probes (Docker HEALTHCHECK, Kubernetes readinessProbe/livenessProbe). Infrastructure orchestrators cannot supply bearer tokens. Principle 6 (Zero Trust) applies to user-to-service and service-to-service API calls, not to infrastructure probe endpoints that expose no sensitive data (only boolean status).

**Alternatives considered**:
- IP-allowlist restricted: Hard to maintain in dynamic container environments.
- Separate probe port (non-HTTP): Out of scope for a PHP/Laravel service.

---

## Decision 4: Alert notification delivery

**Decision**: The `AlertDispatchService` publishes an `AlertFiredEvent` / `AlertResolvedEvent` to the internal event bus. The E17 Notifications module's notification handler subscribes to these events and delivers them to configured channels (email, webhook). AlertRule stores channel configuration as JSON referencing notification channel types defined in E17.

**Rationale**: Reuses E17's proven delivery infrastructure (templates, retries, audit). Avoids duplicating SMTP/webhook sending logic. Decoupled via events (Principle 9).

**Alternatives considered**:
- Ops module sends notifications directly: Duplicates E17 logic; rejected.
- Outbox pattern for alert events: Appropriate given Principle 9's reliability requirement; AlertFiredEvent uses the existing outbox infrastructure (E27).

---

## Decision 5: Backup storage adapter

**Decision**: Define `BackupStorageAdapterInterface` with methods `store(BackupArtifact): StorageReference`, `verify(StorageReference): bool`, `delete(StorageReference): void`. Provide a `LocalDiskBackupStorageAdapter` for development. Production adapter is TBD (Principle 2); registered via service container binding in `OpsServiceProvider`.

**Rationale**: Decouples backup scheduling and job tracking from storage specifics. When the provider is selected, only a new adapter class needs to be written and bound — no domain logic changes. Consistent with the provider adapter foundation pattern (E25/E08).

**Alternatives considered**:
- Hard-code S3/GCS: Violates Principle 2 (TBD provider must not be invented).
- No abstraction (direct file writes): Non-extraction-ready; rejected.

---

## Decision 6: Payment provider health polling

**Decision**: A scheduled `ProviderHealthCheckJob` (Horizon, every 60 seconds) iterates registered providers from E25's `ProviderRegistryInterface`, calls `provider->healthCheck()`, and persists the result to `ops.provider_health_checks` and `ops.provider_status`. Status transition logic (up → degraded → down and back) is managed by `ProviderStatusService`.

**Rationale**: Reuses E25's provider registry; no new provider discovery needed. Consistent with existing patterns; minimal coupling — the Ops module depends on the E25 `ProviderRegistryInterface`, not concrete adapters.

**Alternatives considered**:
- Ops module maintains its own provider list: Duplicates E25 registry; rejected.
- HTTP ping instead of provider interface call: Less accurate (doesn't test API authentication); rejected.

---

## Decision 7: Deployment rollback approach

**Decision**: The `ops.deployment_versions` table records each deployment (populated by a post-deploy script or CI/CD hook). `POST /v1/admin/ops/deployments/{id}/rollback` creates a `DeploymentRollback` record and dispatches a `DeploymentRollbackRequestedEvent`. A `DeploymentBackendInterface` (TBD implementation) handles the actual rollback execution. A `MockDeploymentBackend` is used in tests; a configurable backend (e.g., shell-script-based) is the default for production until a provider is selected.

**Rationale**: The spec requires the capability; actual rollback execution depends on the deployment infrastructure (which is TBD). Tracking the request and emitting an event satisfies the audit (FR-018) and audit-log requirements while keeping the domain decoupled from the deployment tool. Consistent with the TBD adapter pattern (Principle 2).

**Alternatives considered**:
- Direct kubectl/docker rollback commands from the API: Tight coupling; security concern; rejected.
- No rollback API, only manual procedure: Fails FR-017.

---

## Integration Points

| E30 dependency | Used for |
|---|---|
| E17 Notifications | Alert notification delivery (AlertFiredEvent subscriber) |
| E25 API & Integration Platform | Provider registry for payment provider health checks |
| E27 Reliability | Outbox pattern for alert events; TransactionManager |
| E28 Configuration | Retention policies (metrics, logs), backup schedule, alert thresholds |
| E23 Audit & Compliance | Audit log writes for FR-018 (alert rule changes, backup operations, rollbacks) |

---

## Module Location

`app/Modules/Ops/` under `wallet-ecosystem/services/api/`

Sub-structure:
- `Domain/` — entities, interfaces, exceptions, enums, events
- `Application/` — services, DTOs, jobs, listeners
- `Infrastructure/` — migrations, models, repositories
- `Http/` — controllers, resources, requests

DB schema: `ops` (PostgreSQL schema)

Routes:
- `routes/modules/ops-probes.php` — unauthenticated health/readiness/liveness
- `routes/modules/admin-ops.php` — authenticated admin operations endpoints
