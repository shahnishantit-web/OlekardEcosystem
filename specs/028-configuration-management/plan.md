# Implementation Plan — E28 Configuration Management

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected — PHP 8.4+, PostgreSQL, Redis, Next.js, REST/OpenAPI
- [x] No TBD value invented — all domains, permissions, thresholds are concrete; escalation threshold default 24h from `config/configuration.php`
- [x] Authorization remains server-side — all write/approve actions gate on `configuration.write`/`configuration.approve` permissions from E04 gate middleware
- [x] Financial integrity preserved — maker-checker workflow guards all financial policy changes; concurrent approval uses `lockForUpdate()`; activation goes through existing idempotent `ConfigurationActivationJob`
- [x] Security/audit requirements incorporated — `configuration_versions` and `configuration_audit` both immutable; REVOKE UPDATE/DELETE at DB level; every read/write action audit-logged
- [x] UX/design-system requirements incorporated — four admin portal pages under `/admin/configuration`; design tokens and existing component patterns used
- [x] QA strategy incorporated — unit tests for all services; feature tests covering SC-001–SC-007; concurrency test for FR-009; PHPStan level 8 target

---

## Technical Context
- Backend: Laravel / PHP 8.4+
- Database: PostgreSQL (schema `platform`)
- Queue/cache: Redis + Laravel Horizon
- Web: Next.js + TypeScript (Admin portal)
- Mobile: Flutter (no changes — read-only via E05 configuration service)
- API: REST/OpenAPI (`openapi/v1/configuration.yaml`)
- Testing: PHPUnit/Pest unit + feature tests; concurrency test; PHPStan level 8
- Observability: structured logs, correlation IDs, audit events per Principle 13

---

## Architecture Decisions

See `research.md` for full rationale. Summary:

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Separate `change_requests`, `approvals`, `versions` tables (not extending `configuration_values`) | Keeps clean read path for `ConfigurationService::get()`; different lifecycle/immutability requirements |
| 2 | Reuse existing `ConfigurationActivationJob` (draft→active) | Already handles cache invalidation; satisfies 60s SLA without new infrastructure |
| 3 | `lockForUpdate()` on change request row for concurrent approval | Same pattern as E27 T078; PostgreSQL `READ COMMITTED` default not sufficient |
| 4 | Unique partial index `(config_key) WHERE status = 'pending_approval'` | DB-level backstop for FR-007 one-pending-per-key |
| 5 | PHP-backed string enums for domain taxonomy | 3 static domains; no runtime DB table needed |
| 6 | `ConfigurationDomainValidatorInterface` + 3 implementations | Testable PHP classes; validation at submission and activation |
| 7 | New permissions: `configuration.read`, `configuration.write`, `configuration.approve` | Enforces maker ≠ checker; read-only visibility role |
| 8 | 4 Next.js admin pages under `/admin/configuration` | Mirrors E19/E22/E27 portal pattern |
| 9 | `configuration:escalation-check` Artisan command, hourly | FR-010 alert for stale pending requests |
| 10 | `config/configuration.php` with env-backed defaults | Consistent with `config/reliability.php` from E27 |

---

## Dependencies on Prior Epics

| Epic | Dependency |
|------|-----------|
| E01 | Admin authentication; `Idempotency-Key` middleware |
| E04 | `Gate::authorize()` with `configuration.read/write/approve` |
| E07 | `platform.configuration_audit` immutable pattern (REVOKE UPDATE/DELETE) |
| E17 | Notification dispatch to maker on rejection/failure |
| E19 | Admin portal shell, layout components, data table patterns |
| E23 | Audit log service (`AuditService::log()`) |
| E27 | `lockForUpdate()` pattern for concurrency; `HasCorrelationId` trait |

---

## File Structure

### Backend — new files

```
app/
  Modules/
    Platform/
      Domain/
        Enums/
          ConfigurationDomain.php              # backed string enum
          ChangeRequestStatus.php              # backed string enum
          VersionEventType.php                 # backed string enum
        Contracts/
          ConfigurationDomainValidatorInterface.php
          ConfigurationChangeRequestRepositoryInterface.php
          ConfigurationVersionRepositoryInterface.php
          ConfigurationApprovalRepositoryInterface.php
        Validators/
          FinancialPolicyConfigValidator.php
          OperationalPolicyConfigValidator.php
          GeographicConfigValidator.php
      Application/
        Services/
          ConfigurationChangeRequestService.php  # maker/checker workflow logic
        DTOs/
          SubmitChangeRequestDTO.php
          ApproveChangeRequestDTO.php
          RejectChangeRequestDTO.php
          WithdrawChangeRequestDTO.php
      Infrastructure/
        Persistence/
          ConfigurationChangeRequestRepository.php
          ConfigurationVersionRepository.php
          ConfigurationApprovalRepository.php
          Models/
            ConfigurationChangeRequestModel.php
            ConfigurationVersionModel.php
            ConfigurationApprovalModel.php
  Http/
    Controllers/
      ConfigurationChangeRequestAdminController.php
      ConfigurationVersionAdminController.php
    Requests/
      SubmitChangeRequestRequest.php
      ApproveChangeRequestRequest.php
      RejectChangeRequestRequest.php
      WithdrawChangeRequestRequest.php
Console/
  Commands/
    ConfigurationEscalationCheckCommand.php    # configuration:escalation-check
    ConfigurationSeedDomainsCommand.php        # configuration:seed-domains
config/
  configuration.php                            # escalation_threshold_hours, max_history_per_page
database/
  migrations/
    2026_10_02_000001_add_domain_to_platform_configurations.php
    2026_10_02_000002_create_platform_configuration_change_requests.php
    2026_10_02_000003_create_platform_configuration_approvals.php
    2026_10_02_000004_create_platform_configuration_versions.php
  seeders/
    E28ConfigurationPermissionsSeeder.php
routes/
  modules/
    configuration.php                          # extend with new admin routes
openapi/
  v1/
    configuration.yaml                         # full OpenAPI spec
tests/
  Unit/
    ConfigurationChangeRequestServiceTest.php
    FinancialPolicyConfigValidatorTest.php
    OperationalPolicyConfigValidatorTest.php
    GeographicConfigValidatorTest.php
    ConfigurationEscalationCheckCommandTest.php
  Feature/
    ConfigurationChangeRequestApiTest.php      # SC-001–SC-007 + FR-006–FR-009
    ConfigurationVersionHistoryApiTest.php
    ConfigurationConcurrencyTest.php           # SC-006 concurrent approval race
```

### Backend — modified files

```
app/
  Modules/
    Platform/
      Infrastructure/
        Seeders/
          PlatformPermissions.php              # add configuration.read/write/approve
      Application/
        Services/
          ConfigurationActivationJob.php       # hook to also emit VersionEventType::Activated
routes/
  modules/
    configuration.php                          # add admin change-request and history routes
Console/
  Kernel.php                                   # register configuration:escalation-check hourly
```

### Admin Portal — new files

```
wallet-ecosystem/apps/admin/app/(admin)/
  configuration/
    page.tsx                                   # domain list + key browser
    [domain]/
      [key]/
        page.tsx                               # key detail + version history
    approvals/
      page.tsx                                 # pending approvals queue
      [id]/
        page.tsx                               # change request detail + approve/reject/withdraw
```

---

## Implementation Phases

### Phase 1 — Foundation (config, enums, interfaces, migrations, seeder)

1. Create `config/configuration.php`
2. Create PHP enums: `ConfigurationDomain`, `ChangeRequestStatus`, `VersionEventType`
3. Create `ConfigurationDomainValidatorInterface`
4. Write 4 migrations (add domain column + 3 new tables with indexes and REVOKE)
5. Create `E28ConfigurationPermissionsSeeder` (configuration.read/write/approve)
6. Extend `PlatformPermissions` with three new constants
7. Extend `routes/modules/configuration.php` with admin change-request routes

### Phase 2 — Validator implementations

8. `FinancialPolicyConfigValidator` (positive decimal/integer, currency code checks)
9. `OperationalPolicyConfigValidator` (KYC struct shape, risk weight sum, template placeholder check)
10. `GeographicConfigValidator` (ISO 4217, ISO 3166-1, no-duplicate checks)
11. Unit tests for each validator (valid + invalid + edge cases)

### Phase 3 — Domain/Application service

12. Eloquent models: `ConfigurationChangeRequestModel`, `ConfigurationVersionModel`, `ConfigurationApprovalModel`
13. Repository interfaces + Eloquent implementations
14. DTOs: `SubmitChangeRequestDTO`, `ApproveChangeRequestDTO`, `RejectChangeRequestDTO`, `WithdrawChangeRequestDTO`
15. `ConfigurationChangeRequestService`:
    - `submit()` — validate schema, check one-pending-per-key, write change_request + submitted version record
    - `approve()` — `lockForUpdate()` guard, maker≠checker check, write approval + approved version, write `draft` to `configuration_values`
    - `reject()` — `lockForUpdate()` guard, write approval + rejected version, notify maker
    - `withdraw()` — caller==maker check, write withdrawn version
16. Unit tests for `ConfigurationChangeRequestService` (all happy paths + all rejection paths + concurrent approval)

### Phase 4 — ConfigurationActivationJob extension

17. Hook into `ConfigurationActivationJob::handle()`: after `draft→active` transition, also write `VersionEventType::Activated` record to `configuration_versions` referencing `change_request_id` (retrieved via config_key + version correlation)
18. Re-validate value against current domain schema at activation time; if fails → status `failed` + notify maker

### Phase 5 — HTTP layer

19. Form requests: `SubmitChangeRequestRequest`, `ApproveChangeRequestRequest`, `RejectChangeRequestRequest`, `WithdrawChangeRequestRequest`
20. `ConfigurationChangeRequestAdminController`: `index`, `store`, `show`, `approve`, `reject`, `withdraw`
21. `ConfigurationVersionAdminController`: `index` (history for a key)
22. API feature tests: SC-001–SC-007, FR-006 self-approval block, FR-007 duplicate pending, FR-008 withdrawal

### Phase 6 — Artisan commands

23. `ConfigurationEscalationCheckCommand` — queries pending requests older than threshold; emits audit event; registered hourly in `Kernel.php`
24. `ConfigurationSeedDomainsCommand` — adds `domain` value to existing config keys from a seedable mapping
25. Command unit tests

### Phase 7 — Admin portal (Next.js)

26. `/admin/configuration` — domain tabs, key table with has_pending_change badge
27. `/admin/configuration/[domain]/[key]` — current value, pending change (if any), paginated version history table
28. `/admin/configuration/approvals` — pending queue table with maker/submitted_at; approve/reject actions
29. `/admin/configuration/approvals/[id]` — full change request detail: proposed vs previous value diff, decision trail, approve/reject/withdraw buttons

### Phase 8 — OpenAPI documentation

30. Write `openapi/v1/configuration.yaml` from `contracts/configuration-admin-api.yaml`

### Phase 9 — Polish and cross-cutting

31. PHPStan level 8 clean pass
32. Accessibility review for admin portal pages (keyboard navigation, focus management)
33. Escalation check integration test (seed stale pending, run command, assert audit event)
34. Concurrency test: simultaneous approve + approve (SC-006)

---

## Data / Financial Controls

- `configuration_versions` is append-only; REVOKE UPDATE/DELETE prevents any mutation (same as `configuration_audit`)
- `lockForUpdate()` on `configuration_change_requests` row inside DB transaction for both `approve()` and `reject()` — prevents concurrent race condition (FR-009)
- Partial index `UNIQUE (config_key) WHERE status = 'pending_approval'` — DB-level one-pending-per-key guard (FR-007)
- Draft `configuration_values` row written on approval; `ConfigurationActivationJob` promotes to active — idempotent (FR-012)
- `Idempotency-Key` header required on all state-changing endpoints (submit, approve, reject)

---

## Observability

- `HasCorrelationId` trait on `ConfigurationChangeRequestService` (same pattern as E27)
- Every state transition writes to `platform.configuration_audit` via `AuditService::log()`
- `ConfigurationEscalationCheckCommand` emits alert-level audit event (not a thrown exception) for stale pending requests
- Structured log on every domain validation failure (includes config_key, domain, proposed_value summary)

---

## Complexity Tracking

No architecture deviations. Existing `ConfigurationActivationJob` reused as-is (Decision 2 in research.md). If the activation hook requires modifying the job's internal logic beyond appending a version record, an ADR will be filed and this check updated.
