# Tasks: E30 — DevOps & Operations

**Input**: `specs/030-devops-operations/` (spec.md, plan.md, data-model.md, contracts/openapi.yaml, quickstart.md, research.md)

**Base path (backend)**: `wallet-ecosystem/services/api/app/Modules/Ops/`
**Base path (tests)**: `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/`
**Admin UI**: `wallet-ecosystem/apps/admin/app/(admin)/ops/`

---

## Phase 1: Setup

**Purpose**: Create module skeleton and register it with the framework.

- [X] T001 Create Ops module directory tree: `Domain/{Contracts,Enums,Events,Exceptions}/`, `Application/{Services,DTOs,Jobs}/`, `Infrastructure/{Migrations,Models,Repositories,Adapters,Logging}/`, `Http/{Controllers/Admin,Resources}/` under `wallet-ecosystem/services/api/app/Modules/Ops/`
- [X] T002 Create `wallet-ecosystem/services/api/app/Modules/Ops/OpsServiceProvider.php` — registers all bindings (repositories → interfaces, adapters, backends), loads migrations from `Infrastructure/Migrations/`, loads routes from `routes/modules/ops-probes.php` and `routes/modules/admin-ops.php`
- [X] T003 Register `OpsServiceProvider` in `wallet-ecosystem/services/api/bootstrap/providers.php` (or `config/app.php` providers array)

---

## Phase 2: Foundational

**Purpose**: Shared enums, exceptions, interfaces, base controller, and route stubs that all user-story phases depend on.

⚠️ **CRITICAL**: No user-story phase can begin until this phase is complete.

- [X] T004 Create `wallet-ecosystem/services/api/database/migrations/2026_10_03_100000_create_ops_schema.php` — `CREATE SCHEMA IF NOT EXISTS ops` on the `platform` DB connection
- [X] T005 [P] Create enums in `app/Modules/Ops/Domain/Enums/`: `LogSeverity.php` (debug/info/warning/error/critical), `HealthStatus.php` (healthy/degraded/unhealthy), `ProviderStatus.php` (up/degraded/down), `AlertSeverity.php` (info/warning/critical), `AlertStatus.php` (firing/resolved), `BackupJobStatus.php` (pending/running/completed/failed), `BackupType.php` (full/incremental), `RollbackStatus.php` (pending/in_progress/completed/failed)
- [X] T006 [P] Create domain exception classes in `app/Modules/Ops/Domain/Exceptions/`: `AlertRuleNotFoundException.php`, `BackupJobNotFoundException.php`, `DeploymentVersionNotFoundException.php` — each with `errorCode` and `httpStatus` properties
- [X] T007 [P] Create repository interfaces in `app/Modules/Ops/Domain/Contracts/`: `LogRepositoryInterface.php`, `MetricRepositoryInterface.php`, `HealthSnapshotRepositoryInterface.php`, `ProviderHealthCheckRepositoryInterface.php`, `ProviderStatusRepositoryInterface.php`, `AlertRuleRepositoryInterface.php`, `AlertRepositoryInterface.php`, `BackupJobRepositoryInterface.php`, `DeploymentVersionRepositoryInterface.php`, `DeploymentRollbackRepositoryInterface.php`
- [X] T008 [P] Create adapter interfaces in `app/Modules/Ops/Domain/Contracts/`: `BackupStorageAdapterInterface.php` (methods: `store(array): string`, `verify(string): bool`, `delete(string): void`), `DeploymentBackendInterface.php` (methods: `rollback(string $fromVersion, string $toVersion): void`), and `HealthCheckableProviderInterface.php` (method: `probe(int $timeoutSeconds): array{status: string, response_time_ms: ?int, error: ?string}`) — E25 `ProviderAdapterInterface` has no health-check capability; this optional interface is implemented by provider adapters that support active probing
- [X] T009 Create `app/Modules/Ops/Http/Controllers/OpsBaseController.php` extending the application's base controller; add `ok()`, `fail()`, and `paginatedOk()` helpers consistent with other modules
- [X] T010 [P] Create `wallet-ecosystem/services/api/routes/modules/ops-probes.php` — Route group WITHOUT `identity.auth` middleware for `/v1/ops/health*` stubs (actual routes added in Phase 5)
- [X] T011 [P] Create `wallet-ecosystem/services/api/routes/modules/admin-ops.php` — Route group with `identity.auth` middleware for `/v1/admin/ops/*` stubs (actual routes added per story phase)

**Checkpoint**: All interfaces, enums, and route files exist. User-story implementation can now begin.

---

## Phase 3: US-OPS-001 — Centralized Application Logging (P0)

**Goal**: Structured log entries written to `ops.log_entries`; searchable by correlation ID, severity, service, and time range via the Admin API.

**Independent Test**: SC-04 in `quickstart.md` — log entry created, searchable within 5 s, context contains no secrets.

- [X] T012 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100100_create_log_entries_table.php` — `ops.log_entries` with columns per data-model.md; GIN index on `context`; B-tree indexes on `(correlation_id)`, `(severity, created_at DESC)`, `(service_name, created_at DESC)`
- [X] T013 Create `app/Modules/Ops/Infrastructure/Logging/LogSanitizer.php` — strips keys matching `password|pin|secret|token|card` (case-insensitive) from the `context` array before persistence
- [X] T014 Create `app/Modules/Ops/Infrastructure/Logging/DatabaseLogHandler.php` — Monolog handler; calls `LogSanitizer`, writes to `ops.log_entries` via `EloquentLogRepository`; wraps write in try/catch to prevent logging failures from crashing requests
- [X] T015 [P] Create `app/Modules/Ops/Infrastructure/Models/LogEntryModel.php` — `$connection = 'platform'`, `$table = 'ops.log_entries'`, casts for `context` (array) and `severity` (LogSeverity enum); create `app/Modules/Ops/Infrastructure/Repositories/EloquentLogRepository.php` implementing `LogRepositoryInterface`
- [X] T016 Create `app/Modules/Ops/Application/Services/LogService.php` — `search(string|null $correlationId, ?LogSeverity $severity, ?string $serviceName, ?Carbon $from, ?Carbon $to, int $perPage, ?string $cursor): CursorPaginator`
- [X] T017 Create `app/Modules/Ops/Http/Controllers/Admin/AdminLogController.php` + `LogEntryResource.php`; add `GET /v1/admin/ops/logs` to `admin-ops.php` with `identity.require-permission:ops.view_logs`
- [X] T018 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/logs/page.tsx` — log search UI with correlation ID input, severity selector, service name filter, date-range picker, results table; uses shared design tokens
- [X] T019 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/LogSearchApiTest.php` — tests: log written and searchable by correlation ID; sanitizer strips secrets; severity filter works; 403 without permission

---

## Phase 4: US-OPS-002 — System Metrics Collection (P0)

**Goal**: HTTP request metrics (count, latency P50/P95/P99, error rate) collected per service, stored in `ops.service_metrics`, and queryable with granularity selection.

**Independent Test**: SC-05 in `quickstart.md` — metrics endpoint returns at least one data point with `metric_name`, `value`, `unit`, `recorded_at`.

- [X] T020 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100200_create_service_metrics_table.php` — `ops.service_metrics` with columns per data-model.md; composite index on `(service_name, metric_name, granularity, recorded_at DESC)`
- [X] T021 [P] Create `app/Modules/Ops/Infrastructure/Models/ServiceMetricModel.php`; create `app/Modules/Ops/Infrastructure/Repositories/EloquentMetricRepository.php` implementing `MetricRepositoryInterface` with a `query(string $service, string $metric, string $granularity, Carbon $from, Carbon $to): Collection` method
- [X] T022 Create `app/Modules/Ops/Application/Services/MetricsEmitter.php` — thin facade over `EloquentMetricRepository::insert()` for recording raw metric data points; used by middleware and monitoring jobs
- [X] T023 Create `app/Modules/Ops/Infrastructure/Logging/MetricsCollectionMiddleware.php` — Laravel HTTP middleware; on response: records `http.request_count`, `http.latency_ms`, `http.error_count` to `ops.service_metrics` via `MetricsEmitter`; extracts `service_name` from config
- [X] T024 Create `app/Modules/Ops/Application/Jobs/MetricsAggregationJob.php` — Horizon scheduled job (hourly); rolls up `raw` rows to `minute` aggregates for the previous 2 hours; rolls up `minute` rows to `hour` aggregates for data older than 24 h; deletes `raw` rows older than 24 h
- [X] T025 Create `app/Modules/Ops/Application/Services/MetricsService.php` — `query(string|null $service, string|null $metric, string $granularity, Carbon $from, Carbon $to): Collection`
- [X] T026 Create `app/Modules/Ops/Http/Controllers/Admin/AdminMetricsController.php` + `MetricDataPointResource.php`; add `GET /v1/admin/ops/metrics` to `admin-ops.php` with `identity.require-permission:ops.view_metrics`
- [X] T027 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/metrics/page.tsx` — time-series chart UI; service/metric selectors; granularity toggle (minute/hour/day); date-range picker; uses shared design tokens
- [X] T028 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/MetricsQueryApiTest.php` — tests: metrics recorded after request; query returns data points; granularity filter works; 403 without permission

---

## Phase 5: US-OPS-003 — Service Health Checks (P0)

**Goal**: `GET /v1/ops/health` returns aggregate status + component breakdown within 3 s. Unauthenticated.

**Independent Test**: SC-01 in `quickstart.md` — 200 with `overall_status: healthy` and component array when all services up; 503 with `database` component marked unhealthy when DB is stopped.

- [X] T029 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100300_create_health_snapshots_table.php` — `ops.health_snapshots` with columns per data-model.md
- [X] T030 [P] Create `app/Modules/Ops/Infrastructure/Models/HealthSnapshotModel.php` with `components` cast to array; create `app/Modules/Ops/Infrastructure/Repositories/EloquentHealthSnapshotRepository.php`
- [X] T031 Create `app/Modules/Ops/Application/Services/HealthCheckService.php` — `check(): array{overall_status: HealthStatus, components: array, checked_at: Carbon}`; checks database (simple query), cache (ping), queue (Horizon status); records result via `EloquentHealthSnapshotRepository`; must complete within 3 s (uses 2.5 s per-component timeout)
- [X] T032 Create `app/Modules/Ops/Http/Controllers/HealthController.php` — `aggregate()` calls `HealthCheckService::check()`; returns 200 for healthy/degraded, 503 for unhealthy
- [X] T033 Register `GET /v1/ops/health` in `ops-probes.php` pointing to `HealthController::aggregate()`; confirm route has NO `identity.auth` middleware
- [X] T034 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/HealthCheckApiTest.php` — tests: 200 when all healthy; 503 with failing component identified; probe responds without auth token; response under 3 s

---

## Phase 6: US-OPS-004 — Service Readiness Checks (P0)

**Goal**: `GET /v1/ops/health/ready` returns 200 only after startup checks pass (migrations applied, dependencies reachable). Unauthenticated.

**Independent Test**: SC-02 in `quickstart.md` — 200 after full boot; 503 if database connection fails before startup checks complete.

- [X] T035 Create `app/Modules/Ops/Application/Services/ReadinessCheckService.php` — `isReady(): bool`; checks: (1) all pending migrations have been run (`Schema::hasTable('ops.log_entries')`), (2) database reachable, (3) cache reachable; returns false if any check fails; result is cached for 5 s to avoid probe overhead
- [X] T036 Add `ready()` method to `HealthController` — calls `ReadinessCheckService::isReady()`; returns 200 `{status: "ready"}` or 503 `{status: "not_ready"}`; register `GET /v1/ops/health/ready` in `ops-probes.php`
- [X] T037 Extend `HealthCheckApiTest.php` — add: returns 200 when ready; returns 503 when DB unavailable during startup; responds without auth

---

## Phase 7: US-OPS-005 — Service Liveness Checks (P0)

**Goal**: `GET /v1/ops/health/live` returns 200 within 2 s with no external service calls. Unauthenticated.

**Independent Test**: SC-03 in `quickstart.md` — returns `{status: "live"}` in under 2 s; does NOT fail when database is stopped.

- [X] T038 Add `live()` method to `HealthController` — returns 200 `{status: "live"}` immediately; no DB, cache, or queue checks; register `GET /v1/ops/health/live` in `ops-probes.php`
- [X] T039 Extend `HealthCheckApiTest.php` — add: liveness returns 200 without auth; liveness returns 200 even when DB connection is closed (use `DB::disconnect()` in test setup); response time under 2 s

---

## Phase 8: US-OPS-006 — Database Monitoring (P0)

**Goal**: Periodic collection of PostgreSQL metrics (connection pool, slow queries, storage utilization); queryable via the metrics API filtered by `service_name=database`.

**Independent Test**: `GET /v1/admin/ops/metrics?service_name=database` returns data points for `db.active_connections`, `db.storage_bytes`, and `db.slow_query_count`.

- [X] T040 Create `app/Modules/Ops/Application/Services/DatabaseMonitoringService.php` — queries PostgreSQL system views (`pg_stat_activity`, `pg_stat_database`, `pg_locks`) to collect: `db.active_connections`, `db.max_connections`, `db.storage_bytes`, `db.slow_query_count`; uses `MetricsEmitter` to store under `service_name = 'database'`
- [X] T041 Create `app/Modules/Ops/Application/Jobs/DatabaseMetricsCollectionJob.php` — Horizon scheduled job (every 5 minutes); invokes `DatabaseMonitoringService::collect()`
- [X] T042 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/db-monitoring/page.tsx` — DB monitoring dashboard; shows current connection utilization gauge, slow-query count, storage trend chart; reuses `AdminMetricsController` with `service_name=database` query; uses shared design tokens
- [X] T043 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/DatabaseMonitoringTest.php` — tests: `DatabaseMonitoringService::collect()` writes at least 3 metric names; metrics queryable via admin API with `service_name=database`

---

## Phase 9: US-OPS-007 — Queue Monitoring (P0)

**Goal**: Queue depth, throughput, and failed message count collected from Laravel Horizon and stored as service metrics; viewable via admin UI.

**Independent Test**: `GET /v1/admin/ops/metrics?service_name=queue` returns data points for `queue.depth`, `queue.throughput`, and `queue.failed_count`.

- [X] T044 Create `app/Modules/Ops/Application/Services/QueueMonitoringService.php` — reads from Laravel Horizon's `metrics()` API (`Horizon::stats()` and queue-specific data); emits `queue.depth`, `queue.throughput_per_min`, `queue.failed_count` per named queue via `MetricsEmitter` under `service_name = 'queue.{queue_name}'`
- [X] T045 Create `app/Modules/Ops/Application/Jobs/QueueMetricsCollectionJob.php` — Horizon scheduled job (every 1 minute); invokes `QueueMonitoringService::collect()`
- [X] T046 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/queue-monitoring/page.tsx` — queue monitoring UI; shows per-queue depth bar chart, throughput line chart, failed messages table; reuses `AdminMetricsController`; uses shared design tokens
- [X] T047 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/QueueMonitoringTest.php` — tests: `QueueMonitoringService::collect()` writes metrics for at least one queue; metrics queryable via admin API

---

## Phase 10: US-OPS-008 — Payment Provider Monitoring (P0)

**Goal**: Periodic health probes for each registered payment provider; status transitions (up/degraded/down) recorded in `ops.provider_status`; queryable via admin API.

**Independent Test**: SC-06 in `quickstart.md` — `GET /v1/admin/ops/providers` returns all providers with `current_status`, `avg_response_time_ms`, and `last_checked_at`.

⚠️ **Ordering constraint**: T051 (`ProviderStatusService`) calls `AlertDispatchService`, which is created in T064 (Phase 11). Implement T064 before T051 — complete Phase 11 through T064, then return to T051.

- [X] T048 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100400_create_provider_health_checks_table.php` — `ops.provider_health_checks` per data-model.md
- [X] T049 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100500_create_provider_status_table.php` — `ops.provider_status` per data-model.md
- [X] T050 [P] Create `app/Modules/Ops/Infrastructure/Models/ProviderHealthCheckModel.php` + `EloquentProviderHealthCheckRepository.php`; create `ProviderStatusModel.php` + `EloquentProviderStatusRepository.php`
- [X] T051 Create `app/Modules/Ops/Application/Services/ProviderStatusService.php` — `recordProbeResult(string $code, string $probeStatus, ?int $responseMs, ?string $error): void`; updates `ops.provider_status` applying transition logic: >N consecutive failures → degraded → down; >N consecutive successes → up; fires `AlertDispatchService` on status change; writes E23 audit event
- [X] T052 Create `app/Modules/Ops/Application/Jobs/ProviderHealthCheckJob.php` — Horizon scheduled job (every 60 s); iterates `IntegrationProviderType::cases()`; for each type, calls `IntegrationRegistryInterface::resolveAdapter($type)` (skip if null — provider not configured for this environment); if the resolved adapter implements `HealthCheckableProviderInterface` call `adapter->probe(5)`, otherwise record `probe_status = 'unconfigured'`; calls `ProviderStatusService::recordProbeResult(providerCode: $type->value, ...)`
- [X] T053 Create `app/Modules/Ops/Http/Controllers/Admin/AdminProviderMonitoringController.php` — `index()`: `GET /v1/admin/ops/providers`; `history()`: `GET /v1/admin/ops/providers/{code}/history`; add routes to `admin-ops.php` with `identity.require-permission:ops.view_health`
- [X] T054 Create `ProviderStatusResource.php` and `ProviderHealthCheckResource.php` in `Http/Resources/`
- [X] T055 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/providers/page.tsx` — provider status grid with color-coded status badges, response-time sparklines, last-checked timestamp; uses shared design tokens
- [X] T056 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/ProviderMonitoringApiTest.php` — tests: probe result recorded; status transitions up→degraded→down on consecutive failures; status transitions back to up on recovery; API returns current status; audit log entry written on status change; 403 without permission

---

## Phase 11: US-OPS-009 — Alerting Configuration (P0)

**Goal**: Admin creates/updates/deletes alert rules; `AlertEvaluationJob` fires alerts when thresholds breached; `AlertDispatchService` publishes events to E17 for notification delivery.

**Independent Test**: SC-07 in `quickstart.md` — create rule → 201; disable → 200; delete → 200; alert fires when threshold condition met.

- [X] T057 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100600_create_alert_rules_table.php` — `ops.alert_rules` per data-model.md
- [X] T058 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100700_create_alerts_table.php` — `ops.alerts` per data-model.md
- [X] T059 [P] Create `app/Modules/Ops/Infrastructure/Models/AlertRuleModel.php` + `EloquentAlertRuleRepository.php`; `AlertModel.php` + `EloquentAlertRepository.php`
- [X] T060 [P] Create `app/Modules/Ops/Domain/Events/AlertFiredEvent.php` and `AlertResolvedEvent.php` — both implement `IntegrationEventInterface` (from E27); include `alertId`, `ruleName`, `severity`, `conditionSummary`, `notificationChannels`, `correlationId`
- [X] T061 Create `app/Modules/Ops/Application/Services/AlertRuleService.php` — `create(CreateAlertRuleDTO): AlertRuleModel`, `update(string $id, UpdateAlertRuleDTO): AlertRuleModel`, `delete(string $id): void`; each writes an E23 audit log entry with actor ID and timestamp
- [X] T062 Create `app/Modules/Ops/Application/Services/AlertEvaluationService.php` — iterates enabled `AlertRule` records; for each, queries `MetricRepositoryInterface` for the condition metric over the rule's duration window; if threshold breached: creates `Alert` in `firing` state; if previously firing but now resolved: updates to `resolved`
- [X] T063 Create `app/Modules/Ops/Application/Jobs/AlertEvaluationJob.php` — Horizon scheduled job (every 60 s); invokes `AlertEvaluationService::evaluate()`
- [X] T064 Create `app/Modules/Ops/Application/Services/AlertDispatchService.php` — `fire(Alert $alert): void`: dispatches `AlertFiredEvent` via outbox (E27); `resolve(Alert $alert): void`: dispatches `AlertResolvedEvent`
- [X] T065 Create `app/Modules/Ops/Http/Controllers/Admin/AdminAlertRuleController.php` — `index()`, `store()`, `update()`, `destroy()`; add CRUD routes to `admin-ops.php` with `identity.require-permission:ops.manage_alerts`; `store()` requires `Idempotency-Key` header
- [X] T066 Create `app/Modules/Ops/Http/Controllers/Admin/AdminAlertController.php` — `index()`: cursor-paginated list with status/severity/time filters; add `GET /v1/admin/ops/alerts` to `admin-ops.php`
- [X] T067 Create `app/Modules/Ops/Http/Resources/AlertRuleResource.php` and `AlertResource.php`
- [X] T068 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/alert-rules/page.tsx` — alert rule management table with create/edit/delete/enable-toggle; severity badge; uses shared design tokens
- [X] T069 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/alerts/page.tsx` — alert history list with status filter (firing/resolved), severity filter, time-range picker; uses shared design tokens
- [X] T070 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/AlertRuleApiTest.php` — tests: create rule returns 201; duplicate idempotency key returns same rule; update returns 200; delete returns 200; audit log written; alert fires when condition met by `AlertEvaluationService`; resolution notification dispatched when condition clears; 403 without permission

---

## Phase 12: US-OPS-010 — Database Backup (P0)

**Goal**: Scheduled and manual backups tracked in `ops.backup_jobs`; idempotent manual trigger; failure alerts.

**Independent Test**: SC-08 in `quickstart.md` — manual backup trigger returns 201 pending job; replay with same idempotency key returns same job ID; completed job has `size_bytes` and `storage_location`.

- [X] T071 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100800_create_backup_jobs_table.php` — `ops.backup_jobs` per data-model.md; `UNIQUE(idempotency_key)`
- [X] T072 [P] Create `app/Modules/Ops/Infrastructure/Models/BackupJobModel.php` + `EloquentBackupJobRepository.php`
- [X] T073 Create `app/Modules/Ops/Infrastructure/Adapters/LocalDiskBackupStorageAdapter.php` — implements `BackupStorageAdapterInterface`; stores to `storage/backups/{timestamp}.dump`; `verify()` checks file exists and is non-empty; `delete()` removes file; for dev/test only
- [X] T074 Create `app/Modules/Ops/Application/Services/BackupService.php` — `trigger(TriggerBackupDTO): BackupJobModel` (idempotency: same key returns existing job); `markRunning(string $id): void`; `markCompleted(string $id, int $sizeBytes, string $location): void`; `markFailed(string $id, string $reason): void` (calls `AlertDispatchService::fire()`)
- [X] T075 Create `app/Modules/Ops/Application/Jobs/BackupSchedulerJob.php` — Horizon scheduled job (frequency from E28 config, default daily); creates a full backup job and runs it via `BackupService`
- [X] T076 Create `app/Modules/Ops/Application/Jobs/BackupRetentionJob.php` — Horizon scheduled job (daily); queries E28 for retention count config; deletes `BackupJobModel` records (and their storage locations via adapter) older than the retention window
- [X] T077 Create `app/Modules/Ops/Http/Controllers/Admin/AdminBackupJobController.php` — `index()` (list with status/time filters), `store()` (trigger manual; requires `Idempotency-Key`); add routes to `admin-ops.php` with `identity.require-permission:ops.manage_backup`
- [X] T078 Create `app/Modules/Ops/Http/Resources/BackupJobResource.php`
- [X] T079 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/backup-jobs/page.tsx` — backup history table with status badge, size, duration, storage location; manual trigger button; uses shared design tokens
- [X] T080 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/BackupJobApiTest.php` — tests: manual trigger returns 201; idempotency replay returns same job; completed job has size and location; failed backup triggers alert; list with status filter; 403 without permission

---

## Phase 13: US-OPS-011 — Disaster Recovery (P1)

**Goal**: DR readiness status endpoint; DR runbook document; DR validation test record.

**Independent Test**: `GET /v1/admin/ops/dr/status` returns current backup availability, runbook version, and last DR test date.

- [X] T081 Create `app/Modules/Ops/Application/Services/DisasterRecoveryService.php` — `status(): array{has_recent_backup: bool, latest_backup_at: ?Carbon, runbook_version: string, last_dr_test_at: ?Carbon, rto_hours: ?int}`; reads latest completed `BackupJob`; reads DR runbook version and last test date from E28 config
- [X] T082 Create `app/Modules/Ops/Http/Controllers/Admin/AdminDrController.php` — `GET /v1/admin/ops/dr/status` returning DR readiness summary; add route to `admin-ops.php` with `identity.require-permission:ops.view_health`
- [X] T083 Create `wallet-ecosystem/docs/disaster-recovery-runbook.md` — runbook template covering: prerequisites, step-by-step restore from backup, functional verification checklist (auth, balance read, transfer), DR test recording instructions, version field
- [X] T084 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/DisasterRecoveryStatusApiTest.php` — tests: DR status returns `has_recent_backup: true` when a completed backup exists within 24 h; returns `has_recent_backup: false` when no recent backup; 403 without permission
- [X] T100 Add `AdminDrController::recordTest()` — `POST /v1/admin/ops/dr/tests`; accepts `{outcome: "pass"|"fail"|"partial", actual_rto_minutes: ?int, notes: ?string}`; writes `ops.dr_last_test_at` (ISO-8601 timestamp) and `ops.dr_last_test_outcome` to E28 config via `ConfigurationService::set()`; add route to `admin-ops.php` with `identity.require-permission:ops.view_health`; audit-log the recording via E23 per FR-018; extend `DisasterRecoveryStatusApiTest.php` to verify recorded test appears in the `GET /v1/admin/ops/dr/status` response (`last_dr_test_at` and DR test outcome populated)

---

## Phase 14: US-OPS-012 — Deployment Rollback (P1)

**Goal**: Deployment versions tracked in `ops.deployment_versions`; rollback request recorded idempotently in `ops.deployment_rollbacks`; status visible via admin API.

**Independent Test**: SC-09 in `quickstart.md` — list shows at least 1 version; rollback to previous version returns 201 pending; replay idempotency key returns same rollback ID.

- [X] T085 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_100900_create_deployment_versions_table.php` — `ops.deployment_versions` per data-model.md
- [X] T086 Create migration `app/Modules/Ops/Infrastructure/Migrations/2026_10_03_101000_create_deployment_rollbacks_table.php` — `ops.deployment_rollbacks` per data-model.md; `UNIQUE(idempotency_key)`
- [X] T087 [P] Create `app/Modules/Ops/Infrastructure/Models/DeploymentVersionModel.php` + `EloquentDeploymentVersionRepository.php`; `DeploymentRollbackModel.php` + `EloquentDeploymentRollbackRepository.php`
- [X] T088 Create `app/Modules/Ops/Infrastructure/Adapters/MockDeploymentBackend.php` — implements `DeploymentBackendInterface`; records calls without executing; used in tests and local development
- [X] T089 Create `app/Modules/Ops/Application/Services/DeploymentService.php` — `list(): Collection`; `initiateRollback(string $toVersionId, string $initiatorId, string $idempotencyKey, string $correlationId): DeploymentRollbackModel` (idempotency: same key returns existing rollback; 409 if rollback already in progress); calls `DeploymentBackendInterface::rollback()` asynchronously via Horizon job; writes E23 audit log entry
- [X] T090 Create `app/Modules/Ops/Http/Controllers/Admin/AdminDeploymentController.php` — `index()`: `GET /v1/admin/ops/deployments`; `rollback()`: `POST /v1/admin/ops/deployments/{id}/rollback` (requires `Idempotency-Key`); add routes to `admin-ops.php` with `identity.require-permission:ops.manage_deployments`
- [X] T091 Create `app/Modules/Ops/Http/Resources/DeploymentVersionResource.php` and `DeploymentRollbackResource.php`
- [X] T092 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/deployments/page.tsx` — deployment history table with version tag, deployed-at, is-current badge; rollback button (confirms before initiating); in-progress rollback status indicator; uses shared design tokens
- [X] T093 Create `wallet-ecosystem/services/api/tests/Feature/Modules/Ops/DeploymentRollbackApiTest.php` — tests: list returns seeded current version; rollback returns 201; idempotency replay returns same rollback; 409 if rollback already in progress; audit log written; 403 without permission

---

## Phase 15: Polish & Cross-Cutting Concerns

**Purpose**: Dashboard overview, OpenAPI spec, Horizon scheduling, middleware registration, PHPStan, and quickstart validation.

- [X] T094 Create `wallet-ecosystem/apps/admin/app/(admin)/ops/page.tsx` — Ops Dashboard overview: health status widget, recent firing alerts count, last successful backup timestamp, current deployment version; links to all sub-pages; uses shared design tokens
- [X] T095 Write `wallet-ecosystem/services/api/openapi/v1/ops.yaml` — copy and reconcile from `specs/030-devops-operations/contracts/openapi.yaml`; verify all paths, schemas, security scopes, and error envelopes match the implemented controllers
- [X] T096 Register `MetricsCollectionMiddleware` in `wallet-ecosystem/services/api/bootstrap/app.php` (or `app/Http/Kernel.php`) for the `api` middleware group so it wraps all HTTP requests
- [X] T097 Register Horizon scheduled jobs in `wallet-ecosystem/services/api/routes/console.php` — add `Schedule::job(MetricsAggregationJob::class)->hourly()`, `Schedule::job(DatabaseMetricsCollectionJob::class)->everyFiveMinutes()`, `Schedule::job(QueueMetricsCollectionJob::class)->everyMinute()`, `Schedule::job(ProviderHealthCheckJob::class)->everyMinute()`, `Schedule::job(AlertEvaluationJob::class)->everyMinute()`, `Schedule::job(BackupSchedulerJob::class)->daily()`, `Schedule::job(BackupRetentionJob::class)->daily()`; dispatch all on the `horizon` connection (`->onConnection('horizon')`)
- [ ] T098 [P] Run PHPStan level 8 across `app/Modules/Ops/` — fix all type errors, missing return types, and nullable mismatches; ensure zero errors ⚠️ DOCKER-GATED
- [X] T099 [P] Run quickstart.md validation scenarios SC-01 through SC-10 in Docker environment — record pass/fail for each scenario ⚠️ DOCKER-GATED

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1** (Setup): No dependencies — start immediately
- **Phase 2** (Foundational): Depends on Phase 1 — BLOCKS all story phases
- **Phases 3–14** (User Stories): All depend on Phase 2; can proceed in parallel once Phase 2 is done
- **Phase 15** (Polish): Depends on all story phases; T098/T099 require Docker

### Story Dependencies

- **US-OPS-001 (P3)**: No story dependencies — foundational only
- **US-OPS-002 (P4)**: No story dependencies — foundational only
- **US-OPS-003/004/005 (P5–7)**: No story dependencies; readiness (P6) and liveness (P7) extend HealthController from P5
- **US-OPS-006 (P8)**: Depends on US-OPS-002 (MetricsEmitter)
- **US-OPS-007 (P9)**: Depends on US-OPS-002 (MetricsEmitter)
- **US-OPS-008 (P10)**: No story dependencies — foundational only
- **US-OPS-009 (P11)**: Depends on US-OPS-002 (MetricsService for threshold evaluation); depends on US-OPS-008 (ProviderStatusService used as example alert target)
- **US-OPS-010 (P12)**: No story dependencies
- **US-OPS-011 (P13)**: Depends on US-OPS-010 (BackupJob read)
- **US-OPS-012 (P14)**: No story dependencies

### Parallel Opportunities per Phase

- **Phase 2**: T005, T006, T007, T008, T010, T011 can run in parallel
- **Phase 3**: T015 (model + repo) runs in parallel with T013 (migration)
- **Phase 5**: T030 (model + repo) runs in parallel with T029 (migration)
- **Phases 3–12**: All phases are independently parallelizable once Phase 2 is complete

---

## Parallel Execution Example — Phase 11 (Alerting)

```
# These can run in parallel:
T059 — AlertRuleModel + AlertModel (different files, no deps)
T060 — AlertFiredEvent + AlertResolvedEvent (different files, no deps)

# Then in sequence:
T061 AlertRuleService  (depends on T059)
T062 AlertEvaluationService (depends on T059 + Phase 4 MetricsService)
T063 AlertEvaluationJob (depends on T062)
T064 AlertDispatchService (depends on T060)
T065 AdminAlertRuleController (depends on T061)
T066 AdminAlertController (depends on T059)

# Parallel UI:
T068 alert-rules/page.tsx [P]
T069 alerts/page.tsx [P]
```

---

## Implementation Strategy

### MVP First (US-OPS-003 Health Checks)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundational)
3. Complete Phase 5 (Health Checks — US-OPS-003)
4. **VALIDATE**: SC-01 health check passes; 503 on DB failure
5. Add Phase 6/7 (Readiness + Liveness) to complete the probe trio

### Incremental Delivery

| Increment | Phases | Delivers |
|---|---|---|
| 1 — Observability Core | 1, 2, 3, 4, 5, 6, 7 | Logging, metrics, health probes |
| 2 — Component Monitoring | 8, 9, 10 | DB, queue, provider monitoring |
| 3 — Alerting | 11 | Alert rules, fired alerts, notification delivery |
| 4 — Resilience | 12, 13, 14 | Backup, DR, rollback |
| 5 — Polish | 15 | Dashboard, OpenAPI, PHPStan |

---

## Notes

- `[P]` = task targets different files from sibling tasks with no blocking dependency
- `[USNN]` label maps task to `US-OPS-0NN` for traceability
- T098 and T099 require the Docker Compose stack; mark with `⚠️ DOCKER-GATED` when blocked
- Backup storage adapter (T073) and deployment backend (T088) are dev/test stubs; production implementations are TBD per Principle 2
- All admin endpoints enforce `identity.require-permission:ops.*`; probe endpoints (`/v1/ops/health*`) are explicitly unauthenticated

---

## Phase 16: Convergence

- [X] T101 Add `ops_database` Monolog channel to `wallet-ecosystem/services/api/config/logging.php` wiring `App\Modules\Ops\Infrastructure\Logging\DatabaseLogHandler` so structured logs flow to `ops.log_entries` per FR-001 (missing)
- [X] T102 Create `wallet-ecosystem/services/api/database/seeders/OpsPermissionSeeder.php` inserting the 6 `ops.*` permission slugs (`ops.view_logs`, `ops.view_metrics`, `ops.view_health`, `ops.manage_alerts`, `ops.manage_backup`, `ops.manage_deployments`) into `identity.permissions`, and register it in `DatabaseSeeder.php` so `identity.require-permission:ops.*` middleware resolves correctly per US-OPS admin routes (missing)
- [X] T103 Add grouping-key deduplication to `app/Modules/Ops/Application/Services/AlertDispatchService.php`: if a rule has a non-null `grouping_key` and an alert with that key is already in `firing` status, return the existing alert without creating a new one or firing a new event per US-OPS-009/AC5 (partial)
- [X] T104 Add `verify(string $backupJobId, string $actorId, string $correlationId): array` to `app/Modules/Ops/Application/Services/BackupService.php`, calling `BackupStorageAdapterInterface::verify()`, recording an audit entry, and returning the verification result; add `POST /v1/admin/ops/backup-jobs/{id}/verify` route in `routes/modules/admin-ops.php` with `ops.manage_backup` permission; add controller action in `AdminBackupController` per US-OPS-010/AC5 (missing)
- [X] T105 Inject `AlertDispatchService` into `app/Modules/Ops/Application/Services/DeploymentService.php` and, in the `catch (\Throwable $e)` block of `rollback()`, look up or create an alert rule for `deployment.rollback.failed` and call `AlertDispatchService::fire()` so an alert escalates to the incident response team per US-OPS-012/AC4 (partial)
- [X] T106 [P] Create `app/Modules/Ops/Application/DTOs/CreateAlertRuleDTO.php`, `UpdateAlertRuleDTO.php`, and `TriggerBackupDTO.php` as readonly classes; update `AdminAlertRuleController::store/update` and `AdminBackupController::store` to hydrate these DTOs from validated request data and pass them to the respective services per plan `Application/DTOs/` decision (missing)
