# Implementation Plan: P12 — Dynamic Bank Integration Configuration and Certification

**Branch**: `061-bank-integration-config` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/061-bank-integration-config/spec.md`

---

## Summary

P12 extends the existing Payments module (P02–P11) to make bank integration fully configurable through the admin panel, without requiring code changes when adding a new bank that matches a supported adapter profile. The existing `payment_providers` / `payment_provider_accounts` tables are extended with a formal configuration lifecycle (Draft → Active), a typed API operation configuration system, an integration profile registry, certification evidence storage, SSRF protection, two-person approval, and a consumer payment options endpoint enhancement. The Flutter mobile app discovers available options dynamically from the backend.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11) — backend; Next.js 14 + TypeScript — admin panel; Flutter 3.x — mobile

**Primary Dependencies**:
- Laravel Eloquent ORM (payment schema, existing)
- Laravel Gate / E04 permission system (authorization)
- Existing Platform Secrets service (credential reference storage)
- Existing `PaymentProviderAdminService`, `ProviderRegistryService`, `EligibilityPipeline`, `PaymentOptionsController` (all reused/extended)
- Existing `PaymentConfigChangeDraftModel` and `PaymentProviderAdminService` draft mechanism (extended for full lifecycle)
- Existing `AbstractBankPaymentAdapter` (extended to support DB-driven operation config)
- OpenAPI / Laravel Sanctum (API contracts)

**Storage**: PostgreSQL, `payment` schema — new tables added to existing schema; no new DB connection

**Testing**: PHPUnit (feature + unit), Jest + React Testing Library (admin), Flutter test (mobile)

**Target Platform**: API service (Docker), Admin portal (Next.js), Customer app (Flutter)

**Performance Goals**: Payment options discovery < 200ms p95; configuration save < 500ms; SSRF check inline (< 5ms)

**Constraints**:
- No raw credential values in any response, log, or export
- SSRF: allowlist-only HTTPS hosts, no internal IPs, no metadata endpoints
- Two-person approval: approver ≠ submitter for production activation
- Existing Active configuration must remain unmodified when a draft is created
- Unknown provider status → unresolved, never success
- No new DB connection; payment schema only

**Scale/Scope**: ~10 new/modified DB tables; ~15 new API endpoints; ~8 admin panel pages; 1 mobile endpoint enhancement

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Requirement | Status |
|-----------|-------------|--------|
| P1 — Source of Truth | P12 spec approved; no silent business intent changes | ✓ PASS |
| P2 — TBD Governance | No TBD values invented; all decisions explicit in spec/research | ✓ PASS |
| P3 — Configuration over Hard-coding | Bank integration configuration made fully configurable through admin | ✓ PASS — core purpose of P12 |
| P4 — Financial Integrity | Unknown provider status → unresolved, not success (FR-010); no auto-retry across banks (FR-026); attempt context immutable after commit (FR-023) | ✓ PASS |
| P5 — Identity & Authorization | Scoped RBAC (payment.configuration permission); two-person approval; step-up for sensitive operations | ✓ PASS |
| P6 — Zero Trust & Security | Credentials by reference only; SSRF protection; secrets externally managed | ✓ PASS |
| P7 — Domain Boundaries | Controllers thin; business logic in services; Payment module boundary preserved | ✓ PASS |
| P8 — API Contracts | Versioned `/v1` endpoints; idempotency for mutations; OpenAPI docs | ✓ PASS |
| P9 — Event Reliability | Configuration lifecycle transitions emit auditable events via existing audit service | ✓ PASS |
| P10 — UX & Design System | Admin desktop-first; mobile option discovery is read-only; existing design tokens reused | ✓ PASS |
| P12 — Test-First Quality | All stories have acceptance criteria; unit + integration + security tests required | ✓ PASS |
| P13 — Observability | Audit log for all config changes; structured logging in adapters; no credential logging | ✓ PASS |
| P14 — No Silent Arch Changes | No new framework or ORM introduced; existing Payment module extended; no new DB connection | ✓ PASS |

**GATE RESULT: ALL PASS — proceed to Phase 0 research.**

---

## Project Structure

### Documentation (this feature)

```text
specs/061-bank-integration-config/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/           ← Phase 1 output
│   ├── admin-bank-api.yaml
│   ├── admin-lifecycle-api.yaml
│   └── consumer-payment-options-api.yaml
└── tasks.md             ← Phase 2 output (/speckit-tasks)
```

### Source Code

```text
wallet-ecosystem/services/api/
└── app/Modules/Payment/
    ├── Domain/
    │   ├── Contracts/
    │   │   ├── BankRepositoryInterface.php            [NEW]
    │   │   ├── IntegrationProfileRepositoryInterface.php [NEW]
    │   │   ├── BankAccountLifecycleServiceInterface.php [NEW]
    │   │   └── SsrfGuardInterface.php                 [NEW]
    │   ├── Enums/
    │   │   ├── BankLifecycleStatus.php                [NEW]
    │   │   └── AccountConfigLifecycleStatus.php       [NEW]
    │   ├── Exceptions/
    │   │   ├── SsrfViolationException.php             [NEW]
    │   │   ├── LifecycleTransitionException.php       [NEW]
    │   │   └── ApprovalConflictException.php          [NEW]
    │   └── ValueObjects/
    │       ├── ApiOperationConfig.php                 [NEW]
    │       └── SecretReference.php                    [NEW]
    ├── Application/
    │   └── Services/
    │       ├── BankAdminService.php                   [NEW]
    │       ├── BankAccountLifecycleService.php        [NEW]
    │       ├── ApiOperationConfigValidator.php        [NEW]
    │       ├── SsrfGuard.php                          [NEW]
    │       └── CertificationEvidenceService.php       [NEW]
    ├── Infrastructure/
    │   ├── Migrations/
    │   │   ├── 2026_10_24_000001_add_bank_metadata_to_providers.php          [NEW] ← T003 (metadata + lifecycle_status combined)
    │   │   ├── 2026_10_24_000002_add_lifecycle_columns_to_provider_accounts.php [NEW] ← T004
    │   │   ├── 2026_10_24_000003_create_integration_profiles_table.php       [NEW] ← T005
    │   │   ├── 2026_10_24_000004_create_api_operation_configs_table.php      [NEW] ← T006
    │   │   ├── 2026_10_24_000005_create_configuration_versions_table.php     [NEW] ← T007
    │   │   ├── 2026_10_24_000006_create_certification_evidence_table.php     [NEW] ← T008
    │   │   ├── 2026_10_24_000007_create_consumer_payment_options_table.php   [NEW] ← T009
    │   │   ├── 2026_10_24_000008_create_credential_rotation_log_table.php    [NEW] ← T010
    │   │   └── 2026_10_24_000009_add_configuration_version_id_to_attempts.php [NEW] ← T003b (FR-023)
    │   ├── Models/
    │   │   ├── IntegrationProfileModel.php            [NEW]
    │   │   ├── ApiOperationConfigModel.php            [NEW]
    │   │   ├── ConfigurationVersionModel.php          [NEW]
    │   │   ├── CertificationEvidenceModel.php         [NEW]
    │   │   └── ConsumerPaymentOptionModel.php         [NEW]
    │   └── Repositories/
    │       ├── EloquentBankRepository.php             [NEW]
    │       └── EloquentIntegrationProfileRepository.php [NEW]
    └── Http/
        └── Controllers/
            └── Admin/
                ├── BankAdminController.php            [NEW]
                ├── IntegrationAccountAdminController.php [NEW]
                ├── ApiOperationConfigAdminController.php [NEW]
                ├── BankLifecycleAdminController.php   [NEW]
                └── CertificationEvidenceAdminController.php [NEW]

wallet-ecosystem/apps/admin/app/(admin)/payments/
├── banks/
│   ├── page.tsx                [NEW — bank list]
│   ├── [id]/
│   │   ├── page.tsx            [NEW — bank detail]
│   │   └── accounts/
│   │       ├── page.tsx        [NEW — account list]
│   │       └── [accountId]/
│   │           ├── page.tsx    [NEW — account detail + lifecycle]
│   │           └── operations/
│   │               └── page.tsx [NEW — API operation config editor]
│   └── new/
│       └── page.tsx            [NEW — add bank form]
└── components/
    ├── BankStatusBadge.tsx     [NEW]
    ├── LifecyclePanel.tsx      [NEW]
    ├── ApiOperationEditor.tsx  [NEW]
    └── SecretReferenceField.tsx [NEW]

wallet-ecosystem/apps/customer/lib/payments/
└── payment_options_service.dart  [MODIFIED — consume new options response fields]
```

---

## Complexity Tracking

No constitution violations requiring justification.

---

## Implementation Phases

### Phase A: Data Model and Core Domain (US1 + US2 foundations)

Extend the existing `payment` schema with new tables; implement domain value objects, enums, and exceptions. No behavioural change to existing flows.

**Deliverables**:
- 8 new migrations
- 5 new Eloquent models
- `BankLifecycleStatus`, `AccountConfigLifecycleStatus` enums
- `ApiOperationConfig`, `SecretReference` value objects
- `SsrfViolationException`, `LifecycleTransitionException`, `ApprovalConflictException`
- Unit tests for enums and value objects

### Phase B: SSRF Guard and Validator (US2 + US4 security foundation)

Implement `SsrfGuard` service that validates base URLs against allowlist rules: HTTPS-only, no private IP ranges, no cloud metadata endpoints. Implement `ApiOperationConfigValidator` for typed mapping validation.

**Deliverables**:
- `SsrfGuard.php` with comprehensive IP range / scheme validation
- `ApiOperationConfigValidator.php` blocking scripts/executables
- `SsrfGuardInterface.php`
- Unit tests covering all SSRF vectors (RFC1918, loopback, metadata IPs, non-HTTPS)
- Unit tests for validator (script injection, bad field types)

### Phase C: Bank and Account Admin Services (US1)

Implement `BankAdminService` (CRUD for PaymentProviderModel with bank metadata extension) and `BankAccountLifecycleService` (lifecycle state machine for account configurations).

**Deliverables**:
- `BankAdminService.php` — list, find, create, update banks; audit all mutations
- `BankAccountLifecycleService.php` — validate/submit/approve/activate/suspend/retire with guard rails
- `BankRepositoryInterface.php` and `EloquentBankRepository.php`
- Unit tests for lifecycle state transitions

### Phase D: API Operation Configuration (US2)

Implement `ApiOperationConfigAdminService` for CRUD on operation configs within an account configuration version. Integration with SSRF guard and field mapping validator.

**Deliverables**:
- API operation config save/update with SSRF + mapping validation
- Draft creation when editing Active configuration
- ConfigurationVersion snapshot on activation
- Feature tests: initiate/query/refund/callback operation configs persisted and versioned

### Phase E: Certification Evidence (US5)

Implement `CertificationEvidenceService` for evidence upload, test run recording, and evidence-gate enforcement during lifecycle transitions.

**Deliverables**:
- Evidence record creation/listing per configuration version
- Lifecycle gate: block Approved transition without required evidence
- Sandbox test run recording (separate from certification approval)

### Phase F: Admin API Controllers (US1, US2, US3, US4, US5, US7, US8)

Thin controllers delegating to domain services. All endpoints require `payment.configuration` permission.

**Deliverables**:
- `BankAdminController`: list, show, create, update bank
- `IntegrationAccountAdminController`: list, show, create, update account
- `ApiOperationConfigAdminController`: list, show, save, validate operation configs
- `BankLifecycleAdminController`: submit, approve, reject, activate, suspend, retire
- `CertificationEvidenceAdminController`: list, upload, sandbox test run
- OpenAPI documentation for all admin endpoints
- Feature tests: RBAC enforcement, two-person approval, SSRF rejection

### Phase G: Consumer Payment Options (US6)

Extend `PaymentOptionsController` to include display metadata from ConsumerPaymentOption records. Ensure newly activated banks appear without mobile release.

**Deliverables**:
- Extended options response: display label, fees, method, restrictions
- Unit/feature tests: options reflect newly activated accounts; disabled accounts excluded

### Phase H: Admin Panel — Next.js (US8)

Implement admin bank management pages using existing component library and Payments section.

**Deliverables**:
- Bank list page with status badges
- Bank detail page
- Add bank form
- Integration account list and detail
- API operation configuration editor (with SSRF-validation UX)
- Secret reference field (masked, no raw value display)
- Lifecycle controls (submit/approve/reject/activate/suspend/retire buttons with confirmation)
- Certification evidence panel (upload, test run record)
- Configuration version history table
- All state coverage: loading, empty, denied, pending-approval, conflict, error

### Phase I: Flutter Mobile (US6)

Extend customer app payment flow to consume enhanced options response. No hard-coded bank list.

**Deliverables**:
- `payment_options_service.dart` consuming new fields
- Options list widget showing approved label + fee disclosure
- Never treat redirect result as financial confirmation (existing safeguard verified)

### Phase J: Security, Regression, and Documentation

**Deliverables**:
- SSRF automated tests with all rejection vectors
- Two-person approval enforcement tests
- Credential-never-in-response tests
- Unknown-status-remains-unresolved tests
- Two-bank-same-profile zero-code-change demonstration test
- Operating runbook in `docs/operations/bank-integration-runbook.md`
- Certification evidence checklist template
