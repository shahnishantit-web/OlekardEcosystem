# Implementation Plan — E30 DevOps & Operations

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no external log aggregator or time-series DB introduced; all observability data stored in PostgreSQL within the existing stack
- [x] No TBD value invented — backup storage provider, RTO/RPO, and retention policies left TBD; adapter interfaces created to defer selection (Principle 2)
- [x] Authorization remains server-side — all admin endpoints protected by `identity.auth` + `identity.require-permission:ops.*` middleware; probe endpoints unauthenticated by documented convention (Principle 5, 6)
- [x] Financial integrity preserved — Ops module has no financial mutations; no ledger involvement
- [x] Security/audit requirements incorporated — FR-018 audit logging for all alert rule changes, backup ops, provider status changes, rollbacks; log sanitizer prevents plaintext secrets (Principle 13)
- [x] UX/design-system requirements incorporated — Admin Portal observability dashboard uses shared design tokens; accessibility applies to all admin UI screens
- [x] QA strategy incorporated — 10 quickstart scenarios (SC-01–SC-10) covering all P0 stories; PHPUnit feature tests required per story

---

## Technical Context

- **Backend**: Laravel / PHP 8.4+ — `app/Modules/Ops/`
- **Database**: PostgreSQL — `ops` schema
- **Queue/cache**: Redis + Laravel Horizon (used for `ProviderHealthCheckJob`, `MetricsAggregationJob`, `BackupJob`)
- **Web Admin**: Next.js + TypeScript — `wallet-ecosystem/apps/admin/app/(admin)/ops/`
- **Mobile**: Not applicable — Ops features are admin/operations-only
- **API**: REST/OpenAPI — documented in `contracts/openapi.yaml`; routes in `routes/modules/admin-ops.php` and `routes/modules/ops-probes.php`
- **Logging**: Monolog → custom `DatabaseLogHandler` → `ops.log_entries` (PostgreSQL JSONB)
- **Metrics**: `MetricsCollectionMiddleware` + `MetricsEmitter` → `ops.service_metrics` → `MetricsAggregationJob` (hourly Horizon job)
- **Health probes**: `HealthCheckService` aggregates component checks; unauthenticated route group
- **Provider monitoring**: `ProviderHealthCheckJob` polls E25 `ProviderRegistryInterface`; `ProviderStatusService` manages state transitions
- **Alerting**: `AlertEvaluationJob` (periodic) evaluates `AlertRule` conditions; `AlertDispatchService` fires `AlertFiredEvent` consumed by E17
- **Backup**: `BackupSchedulerJob` + `BackupStorageAdapterInterface`; `LocalDiskBackupStorageAdapter` for dev/test
- **Deployment**: `DeploymentVersionService` + `DeploymentBackendInterface`; `MockDeploymentBackend` for tests

---

## Module Structure

```
app/Modules/Ops/
├── Domain/
│   ├── Contracts/
│   │   ├── LogRepositoryInterface.php
│   │   ├── MetricRepositoryInterface.php
│   │   ├── HealthSnapshotRepositoryInterface.php
│   │   ├── ProviderHealthCheckRepositoryInterface.php
│   │   ├── ProviderStatusRepositoryInterface.php
│   │   ├── AlertRuleRepositoryInterface.php
│   │   ├── AlertRepositoryInterface.php
│   │   ├── BackupJobRepositoryInterface.php
│   │   ├── DeploymentVersionRepositoryInterface.php
│   │   ├── DeploymentRollbackRepositoryInterface.php
│   │   ├── BackupStorageAdapterInterface.php
│   │   └── DeploymentBackendInterface.php
│   ├── Enums/
│   │   ├── LogSeverity.php
│   │   ├── HealthStatus.php
│   │   ├── ProviderStatus.php
│   │   ├── AlertSeverity.php
│   │   ├── AlertStatus.php
│   │   ├── BackupJobStatus.php
│   │   ├── BackupType.php
│   │   └── DeploymentRollbackStatus.php
│   ├── Events/
│   │   ├── AlertFiredEvent.php
│   │   └── AlertResolvedEvent.php
│   └── Exceptions/
│       ├── AlertRuleNotFoundException.php
│       ├── BackupJobNotFoundException.php
│       └── DeploymentVersionNotFoundException.php
├── Application/
│   ├── Services/
│   │   ├── LogService.php
│   │   ├── MetricsService.php
│   │   ├── HealthCheckService.php
│   │   ├── ProviderStatusService.php
│   │   ├── AlertRuleService.php
│   │   ├── AlertEvaluationService.php
│   │   ├── AlertDispatchService.php
│   │   ├── BackupService.php
│   │   └── DeploymentService.php
│   ├── DTOs/
│   │   ├── CreateAlertRuleDTO.php
│   │   ├── UpdateAlertRuleDTO.php
│   │   └── TriggerBackupDTO.php
│   └── Jobs/
│       ├── ProviderHealthCheckJob.php
│       ├── MetricsAggregationJob.php
│       ├── AlertEvaluationJob.php
│       ├── BackupSchedulerJob.php
│       └── BackupRetentionJob.php
├── Infrastructure/
│   ├── Migrations/
│   │   ├── 2026_10_03_100000_create_ops_schema.php
│   │   ├── 2026_10_03_100100_create_log_entries_table.php
│   │   ├── 2026_10_03_100200_create_service_metrics_table.php
│   │   ├── 2026_10_03_100300_create_health_snapshots_table.php
│   │   ├── 2026_10_03_100400_create_provider_health_checks_table.php
│   │   ├── 2026_10_03_100500_create_provider_status_table.php
│   │   ├── 2026_10_03_100600_create_alert_rules_table.php
│   │   ├── 2026_10_03_100700_create_alerts_table.php
│   │   ├── 2026_10_03_100800_create_backup_jobs_table.php
│   │   ├── 2026_10_03_100900_create_deployment_versions_table.php
│   │   └── 2026_10_03_101000_create_deployment_rollbacks_table.php
│   ├── Models/
│   │   ├── LogEntryModel.php
│   │   ├── ServiceMetricModel.php
│   │   ├── HealthSnapshotModel.php
│   │   ├── ProviderHealthCheckModel.php
│   │   ├── ProviderStatusModel.php
│   │   ├── AlertRuleModel.php
│   │   ├── AlertModel.php
│   │   ├── BackupJobModel.php
│   │   ├── DeploymentVersionModel.php
│   │   └── DeploymentRollbackModel.php
│   ├── Repositories/
│   │   ├── EloquentLogRepository.php
│   │   ├── EloquentMetricRepository.php
│   │   ├── EloquentHealthSnapshotRepository.php
│   │   ├── EloquentProviderHealthCheckRepository.php
│   │   ├── EloquentProviderStatusRepository.php
│   │   ├── EloquentAlertRuleRepository.php
│   │   ├── EloquentAlertRepository.php
│   │   ├── EloquentBackupJobRepository.php
│   │   ├── EloquentDeploymentVersionRepository.php
│   │   └── EloquentDeploymentRollbackRepository.php
│   ├── Adapters/
│   │   ├── LocalDiskBackupStorageAdapter.php
│   │   └── MockDeploymentBackend.php
│   └── Logging/
│       ├── DatabaseLogHandler.php
│       └── LogSanitizer.php
├── Http/
│   ├── Controllers/
│   │   ├── HealthController.php              (unauthenticated)
│   │   ├── Admin/
│   │   │   ├── AdminLogController.php
│   │   │   ├── AdminMetricsController.php
│   │   │   ├── AdminProviderMonitoringController.php
│   │   │   ├── AdminAlertRuleController.php
│   │   │   ├── AdminAlertController.php
│   │   │   ├── AdminBackupJobController.php
│   │   │   └── AdminDeploymentController.php
│   └── Resources/
│       ├── LogEntryResource.php
│       ├── MetricDataPointResource.php
│       ├── ProviderStatusResource.php
│       ├── AlertRuleResource.php
│       ├── AlertResource.php
│       ├── BackupJobResource.php
│       ├── DeploymentVersionResource.php
│       └── DeploymentRollbackResource.php
└── OpsServiceProvider.php
```

**Route files:**
- `routes/modules/ops-probes.php` — unauthenticated `/v1/ops/health*`
- `routes/modules/admin-ops.php` — authenticated `/v1/admin/ops/*`

**Admin Next.js screens:**
- `apps/admin/app/(admin)/ops/page.tsx` — Ops Dashboard (health + recent alerts summary)
- `apps/admin/app/(admin)/ops/logs/page.tsx` — Log Search
- `apps/admin/app/(admin)/ops/metrics/page.tsx` — Metrics Charts
- `apps/admin/app/(admin)/ops/providers/page.tsx` — Provider Status
- `apps/admin/app/(admin)/ops/alert-rules/page.tsx` — Alert Rule Management
- `apps/admin/app/(admin)/ops/alerts/page.tsx` — Alert History
- `apps/admin/app/(admin)/ops/backup-jobs/page.tsx` — Backup Job History
- `apps/admin/app/(admin)/ops/deployments/page.tsx` — Deployment History + Rollback

---

## Permissions Required

| Permission slug | Covers |
|---|---|
| `ops.view_logs` | Log search |
| `ops.view_metrics` | Metrics query |
| `ops.view_health` | Provider monitoring, health snapshots |
| `ops.manage_alerts` | Alert rule CRUD |
| `ops.manage_backup` | Backup trigger, history |
| `ops.manage_deployments` | Deployment list, rollback initiation |

---

## Integration Points

| Epic | Interface used | Purpose |
|---|---|---|
| E17 Notifications | Internal event subscriber | Alert notification delivery |
| E25 API & Integration Platform | `ProviderRegistryInterface` | Provider list for health polling |
| E27 Reliability | Outbox / TransactionManager | Reliable alert event delivery |
| E28 Configuration | `ConfigurationService::get()` | Retention policies, thresholds, schedules |
| E23 Audit & Compliance | `AuditLogger::record()` | FR-018 audit logging |

---

## Implementation Approach

1. Foundational: `ops` schema + all migrations + ServiceProvider + enums
2. Logging: `DatabaseLogHandler`, `LogSanitizer`, `LogService`, `AdminLogController`
3. Health probes: `HealthCheckService`, `HealthController`, unauthenticated routes
4. Metrics: `MetricsCollectionMiddleware`, `MetricsService`, `MetricsAggregationJob`
5. Provider monitoring: `ProviderHealthCheckJob`, `ProviderStatusService`, `AdminProviderMonitoringController`
6. Alerting: `AlertRuleService`, `AlertEvaluationJob`, `AlertDispatchService`, `AdminAlertRuleController`, `AdminAlertController`
7. Backup: `BackupStorageAdapterInterface`, `LocalDiskBackupStorageAdapter`, `BackupService`, `BackupSchedulerJob`, `BackupRetentionJob`, `AdminBackupJobController`
8. Deployment: `DeploymentVersionService`, `MockDeploymentBackend`, `AdminDeploymentController`
9. Admin UI: Next.js screens for all 8 ops sections
10. OpenAPI: `services/api/openapi/v1/ops.yaml`
11. PHPStan level 8 + test suite

---

## Data / Financial Controls

The Ops module contains no financial mutations. All entities are operational metadata. Idempotency-Key required on backup trigger and deployment rollback (state-changing mutations per Principle 8). BackupJob and DeploymentRollback records are effectively append-only once in a terminal state.

---

## Observability

All admin actions produce structured log entries via the same `DatabaseLogHandler`. Alert rule changes, backup operations, provider status transitions, and rollbacks produce audit events via E23's `AuditLogger`. Correlation IDs are passed through all service methods.
