# Implementation Plan — E23 Audit & Compliance

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected — backend Laravel/PHP 8.4+, admin UI Next.js, no new frameworks introduced
- [x] No TBD value invented — minimum retention periods and SAR jurisdiction format deferred to admin config per constitution Principle 3
- [x] Authorization remains server-side — all endpoints enforce `audit.read`, `compliance.read`, `compliance.report`, `compliance.config` permissions via middleware
- [x] Financial integrity preserved — audit events for financial transactions (US-AUDIT-004) are written within the source transaction; no financial state is mutated by E23
- [x] Security/audit requirements incorporated — E23 IS the audit module; all privileged E23 operations (retention rule changes, SAR generation) are themselves audited via AuditModuleContract::record()
- [x] UX/design-system requirements incorporated — Admin Portal screens use shared design tokens; compliance officer UI follows desktop-first pattern (Principle 10)
- [x] QA strategy incorporated — 9 quickstart scenarios; unit tests for immutability enforcement, integrity hash, and permission checks; integration tests for event subscriptions

## Technical Context

- **Backend**: Laravel / PHP 8.4+
- **Module path**: `App\Modules\Audit\`
- **DB schema**: `audit` (PostgreSQL, `platform` connection)
- **Queue/cache**: Redis + Laravel Horizon (report generation jobs, retention enforcement)
- **Web admin**: Next.js + TypeScript (audit event viewer, compliance dashboard, SAR management, retention policy admin)
- **Mobile**: Flutter (read-only compliance status visible to customer/agent — future iteration)
- **API**: REST/OpenAPI — `openapi/v1/audit.yaml`
- **Config**: `config/audit.php`
- **Testing**: PHPUnit/Pest, feature tests for all endpoints, unit tests for AuditService, integrity hash logic, immutability enforcement
- **Observability**: structured logs, metrics (`audit_events_recorded_total`, `compliance_reports_generated_total`), correlation IDs

## Cross-Module Dependencies

| Module | Direction | What E23 needs |
|--------|-----------|----------------|
| E01 Identity | Consumes contract | Actor resolution for `audit.read` and `compliance.read` permission checks |
| E04 Roles & Permissions | Consumes contract | `IdentityModuleContract::hasPermission()` for permission enforcement |
| E05 KYC | Listens to events | `KycStatusChangedEvent` → update `compliance_status.kyc_tier` |
| E07 Ledger | Consumes contract | `OutboxEventPublisher` pattern for `AuditEventRecorded` integration events |
| E17 Notifications | Publishes events | `ComplianceReportGeneratedEvent`, `SARGeneratedEvent` → notify compliance officer |
| E18 Fraud/AML | Listens to events | `AmlRiskLevelChangedEvent`, `AmlRestrictionAppliedEvent` → update `compliance_status` |

**E23 exposes to all other modules**:
- `AuditModuleContract::record(AuditEntry)` — registered in `AuditServiceProvider`; injected by E01–E22

## Data Model

See [data-model.md](data-model.md) for full schema.

**Tables** (all in `audit` schema):
- `audit_events` — immutable, append-only; HMAC-SHA256 integrity hash per row
- `audit_events_archive` — retention enforcement target for `archive` action
- `compliance_status` — denormalized per-user KYC+AML snapshot
- `compliance_history` — immutable, append-only compliance change log
- `compliance_reports` — generated report records (async for large date ranges)
- `suspicious_activity_reports` — SAR drafts and filed reports
- `retention_policies` — configurable per-category retention rules

## File Structure

```
app/Modules/Audit/
├── AuditServiceProvider.php
├── Domain/
│   ├── Contracts/
│   │   └── AuditModuleContract.php
│   ├── Enums/
│   │   ├── AuditEventCategory.php
│   │   ├── AuditActorType.php
│   │   └── AuditOutcome.php
│   ├── Events/
│   │   ├── AuditEventRecordedEvent.php
│   │   ├── ComplianceStatusChangedEvent.php
│   │   ├── ComplianceReportGeneratedEvent.php
│   │   ├── SARGeneratedEvent.php
│   │   └── RetentionPolicyChangedEvent.php
│   ├── Exceptions/
│   │   ├── AuditImmutabilityViolationException.php
│   │   ├── AuditWriteException.php
│   │   ├── ComplianceStatusNotFoundException.php
│   │   ├── ComplianceReportNotFoundException.php
│   │   └── SARNotFoundException.php
│   └── ValueObjects/
│       └── AuditEntry.php
├── Application/
│   └── Services/
│       ├── AuditService.php                  (implements AuditModuleContract)
│       ├── ComplianceStatusService.php        (US-COMP-001 to US-COMP-003)
│       ├── ComplianceReportService.php        (US-COMP-004)
│       ├── SuspiciousActivityReportService.php (US-COMP-005)
│       └── RetentionPolicyService.php         (US-COMP-006)
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuditEventListController.php
│   │   │   ├── AuditEventDetailController.php
│   │   │   ├── ComplianceStatusController.php
│   │   │   ├── ComplianceHistoryController.php
│   │   │   ├── ComplianceTransactionController.php
│   │   │   ├── ComplianceReportController.php
│   │   │   ├── SARController.php
│   │   │   └── RetentionPolicyController.php
│   │   ├── Requests/
│   │   │   ├── ListAuditEventsRequest.php
│   │   │   ├── GenerateComplianceReportRequest.php
│   │   │   ├── GenerateSARRequest.php
│   │   │   └── RetentionPolicyRequest.php
│   │   └── Resources/
│   │       ├── AuditEventSummaryResource.php
│   │       ├── AuditEventDetailResource.php
│   │       ├── ComplianceStatusResource.php
│   │       ├── ComplianceHistoryResource.php
│   │       ├── ComplianceReportResource.php
│   │       ├── SARResource.php
│   │       └── RetentionPolicyResource.php
│   ├── Jobs/
│   │   ├── GenerateComplianceReportJob.php
│   │   └── EnforceRetentionPolicyJob.php
│   ├── Listeners/
│   │   ├── KycStatusChangedListener.php
│   │   ├── AmlRiskLevelChangedListener.php
│   │   └── AmlRestrictionChangedListener.php
│   ├── Models/
│   │   ├── AuditEventModel.php               (immutable — override save/update/delete)
│   │   ├── ComplianceStatusModel.php
│   │   ├── ComplianceHistoryModel.php         (immutable)
│   │   ├── ComplianceReportModel.php
│   │   ├── SuspiciousActivityReportModel.php
│   │   └── RetentionPolicyModel.php
│   └── Migrations/
│       ├── 2026_09_28_000001_create_audit_schema.php
│       ├── 2026_09_28_000002_create_audit_events_table.php
│       ├── 2026_09_28_000003_create_audit_events_archive_table.php
│       ├── 2026_09_28_000004_create_compliance_status_table.php
│       ├── 2026_09_28_000005_create_compliance_history_table.php
│       ├── 2026_09_28_000006_create_compliance_reports_table.php
│       ├── 2026_09_28_000007_create_suspicious_activity_reports_table.php
│       └── 2026_09_28_000008_create_retention_policies_table.php
routes/
└── audit.php
config/
└── audit.php
openapi/v1/
└── audit.yaml
console/Commands/
└── AuditEnforceRetentionCommand.php
```

## Implementation Phases

### Phase 1 — Foundation (US-AUDIT-009 + Module Scaffold)

Establishes the module skeleton and immutability enforcement before any event recording is possible.

1. Create `audit` PostgreSQL schema migration
2. Create `audit_events` and `audit_events_archive` table migrations (with DB-level immutability trigger)
3. Implement `AuditEventCategory`, `AuditActorType`, `AuditOutcome` enums
4. Implement `AuditEntry` value object (see `contracts/AuditEntry.php`)
5. Implement `AuditEventModel` with `boot()` guard (throws `AuditImmutabilityViolationException` on `updating`/`deleting`)
6. Implement integrity hash computation (`AUDIT_HMAC_KEY` from env)
7. Implement `AuditService::record()` — inserts within caller's transaction context
8. Register `AuditModuleContract` binding in `AuditServiceProvider`
9. Unit tests: immutability guard, integrity hash compute/verify, record() within transaction
10. Register service provider in `bootstrap/providers.php`

### Phase 2 — Audit Event Recording (US-AUDIT-001 to US-AUDIT-008)

Exposes the read API for audit events; the write path is the `AuditModuleContract` from Phase 1.

1. Create `routes/audit.php` — read-only routes for `/v1/audit/events`
2. Implement `ListAuditEventsRequest` (filters: category, actor_id, entity_type, entity_id, outcome, correlation_id, from/to, cursor)
3. Implement `AuditEventListController` and `AuditEventDetailController`
4. Implement `AuditEventSummaryResource` and `AuditEventDetailResource` (includes `integrity_verified`)
5. Implement `AuditEventRecordedEvent` (integration event via outbox)
6. Add permission middleware: `audit.read` required on all audit routes
7. Feature tests: list events with filters; single event with integrity_verified; 403 for unauthorized
8. Update `openapi/v1/audit.yaml` with audit event endpoints

### Phase 3 — Compliance Status & History (US-COMP-001, US-COMP-002, US-COMP-003)

1. Create `compliance_status`, `compliance_history` table migrations
2. Implement `ComplianceStatusModel` and `ComplianceHistoryModel` (history: immutable same as audit_events)
3. Implement `ComplianceStatusService`:
   - `getStatus(userId)` — returns current ComplianceStatus
   - `getHistory(userId, cursor, perPage)` — returns paginated ComplianceHistoryEntry list
   - `getTransactions(filters, cursor, perPage)` — compliance-view of E11 transactions
   - `applyKycChange(userId, tier, auditEventId)` — updates status + appends history
   - `applyAmlChange(userId, riskLevel, restrictions, auditEventId)` — updates status + appends history
4. Implement `KycStatusChangedListener` — calls `ComplianceStatusService::applyKycChange()`
5. Implement `AmlRiskLevelChangedListener` — calls `ComplianceStatusService::applyAmlChange()`
6. Implement `AmlRestrictionChangedListener` — updates `active_restrictions`
7. Register event listeners in `AuditServiceProvider`
8. Implement `ComplianceStatusController`, `ComplianceHistoryController`, `ComplianceTransactionController`
9. Implement `ComplianceStatusResource`, `ComplianceHistoryResource`, `ComplianceTransactionResource`
10. Add routes: `GET /v1/compliance/status/{userId}`, `GET /v1/compliance/history/{userId}`, `GET /v1/compliance/transactions`
11. Feature tests: status query, history pagination, transaction listing, 403 for missing `compliance.read`
12. Emit `ComplianceStatusChangedEvent` when status changes (for downstream consumers)

### Phase 4 — Compliance Reports (US-COMP-004)

1. Create `compliance_reports` table migration
2. Implement `ComplianceReportModel`
3. Implement `ComplianceReportService`:
   - `requestReport(reportType, dateRange, filters, idempotencyKey, actorId)` — idempotent; dispatches `GenerateComplianceReportJob` for large ranges or generates inline for ≤24h
   - `getReport(reportId)` — returns report by ID
4. Implement `GenerateComplianceReportJob` — aggregates audit events + compliance status + transactions into `report_data` JSONB
5. Implement `ComplianceReportController`
6. Implement `ComplianceReportResource`
7. Add routes: `POST /v1/compliance/reports`, `GET /v1/compliance/reports/{id}`
8. Emit `ComplianceReportGeneratedEvent` on completion (for E17 notification)
9. Feature tests: idempotent report generation, async status polling, inline generation for ≤24h range, 403 enforcement

### Phase 5 — Suspicious Activity Reports (US-COMP-005)

1. Create `suspicious_activity_reports` table migration
2. Implement `SuspiciousActivityReportModel`
3. Implement `SuspiciousActivityReportService`:
   - `generateSAR(subjectUserId, reasonCode, reasonNarrative, flaggedTransactionIds, idempotencyKey, actorId)` — idempotent; collects subject identity snapshot, AML alert history (from E18 via AuditModuleContract read + stored events), risk score timeline
   - `getSAR(sarId)` — returns SAR by ID
4. Implement `SARController`
5. Implement `SARResource`
6. Add routes: `POST /v1/compliance/sars`, `GET /v1/compliance/sars/{id}`
7. Emit `SARGeneratedEvent` (for E17 notification)
8. Feature tests: SAR generation, idempotency, empty-alert-history case (returns "no alerts found" narrative), 403 enforcement

### Phase 6 — Data Retention (US-COMP-006)

1. Create `retention_policies` table migration
2. Implement `RetentionPolicyModel`
3. Implement `RetentionPolicyService`:
   - `createPolicy(category, minRetentionDays, action, actorId)` — creates; audits via AuditModuleContract
   - `updatePolicy(policyId, minRetentionDays, action, actorId)` — updates; sets `enforcement_hold_until = today + 1` if period shortened; audits
   - `listPolicies()` — returns all policies
4. Implement `AuditEnforceRetentionCommand` (`php artisan audit:enforce-retention`):
   - Cursor-scan per category for records older than `minimum_retention_days`
   - Respects `enforcement_hold_until` grace period
   - Archive action: inserts to `audit_events_archive`, soft-deletes primary
   - Purge action: hard-deletes primary (never archive)
   - Emits `AuditRetentionEnforcedEvent` (audit event)
5. Register `EnforceRetentionPolicyJob` in `app/Console/Kernel.php` (daily 02:00 UTC)
6. Implement `RetentionPolicyController`
7. Implement `RetentionPolicyResource`
8. Add routes: `GET /v1/compliance/retention-policies`, `POST /v1/compliance/retention-policies`, `PUT /v1/compliance/retention-policies/{id}`
9. Emit `RetentionPolicyChangedEvent` on create/update
10. Feature tests: policy creation, policy update with grace period, enforcement command (unit), minimum-window protection, 403 enforcement

### Phase 7 — Polish & Cross-Cutting

1. OpenAPI `openapi/v1/audit.yaml` — final review and completeness check
2. PHPStan level max — resolve any type errors
3. `php artisan test` — all tests passing
4. Metrics: register `audit_events_recorded_total` (counter, by category), `compliance_reports_generated_total` (counter, by type)
5. Structured logging: all service methods log at `info` level with correlation ID
6. Admin UI (Next.js): audit event list/detail, compliance status/history views, SAR list/detail, retention policy management
7. Docker quickstart: run SC-1 through SC-9 from quickstart.md

## Data / Financial Controls

- `AuditEventModel` and `ComplianceHistoryModel` throw on `update()`/`delete()` — application-layer immutability
- DB migration includes `BEFORE UPDATE` and `BEFORE DELETE` triggers on `audit.audit_events` and `audit.compliance_history`
- `integrity_hash` is computed on `creating` event in `AuditEventModel::boot()` and verified on `retrieved`
- No financial balance mutations occur in E23 — the module is read/write for compliance data only
- `GenerateComplianceReportJob` and `GenerateSARJob` are idempotent (idempotency_key UNIQUE constraint)

## Observability

- Correlation IDs propagated to all audit event records and compliance operations
- **Metrics (log-proxy pattern)**: The platform does not yet have a Prometheus client library installed. Metric counters are emitted via `Log::channel('stderr')->debug('metric', ['metric' => '<name>', ...label_key => label_value])`, which is scraped by the infrastructure log pipeline. Named counters: `audit_events_recorded_total` (label: `event_category`), `compliance_reports_generated_total` (labels: `report_type`, `mode`), `sar_generated_total`, `retention_enforcement_runs_total`. To upgrade to real Prometheus counters, add `promphp/prometheus_client_php` to composer.json and replace the log calls — no business logic changes required.
- Structured logs: service-level INFO on `record()`, `getStatus()`, `requestReport()`, `generateSAR()`, `createPolicy()`/`updatePolicy()`
- WARN log when `verifyIntegrity()` returns false on audit event read

## Complexity Tracking

No architecture deviations from the approved stack. `AuditModuleContract` follows the same bounded-context contract pattern as `LedgerModuleContract`, `FeesModuleContract`, etc. from prior epics.
