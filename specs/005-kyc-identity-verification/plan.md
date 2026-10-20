# Implementation Plan — E05 KYC & Identity Verification

**Feature**: `005-kyc-identity-verification` | **Branch**: `005-kyc-identity-verification` | **Created**: 2026-09-16

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new framework or ORM introduced
- [x] No TBD value invented — 4 TBDs remain documented; config entries seeded with `is_tbd = true`; system defaults to `auto_verify_enabled = false` and routes to manual review
- [x] Authorization remains server-side — all KYC endpoints use `identity.require-permission` middleware from E04; permission checks are not client-trusting
- [x] Financial integrity preserved — KYC is pre-financial; no ledger/wallet mutations; principles do not apply to this epic
- [x] Security/audit requirements incorporated — all state transitions write `platform.audit_events`; national ID and provider response stored as HMAC-SHA256 hashes only; cross-user access returns 404
- [x] Domain events use outbox pattern — `OutboxEventPublisher` writes to `platform.outbox_events` inside the same DB transaction as the state mutation (Principle 9)
- [x] Module boundary explicit — `App\Modules\Kyc\` is a distinct bounded context; no direct DB access across module boundaries; depends on Platform via injected services
- [x] UX/design-system requirements incorporated — E05 is a backend API epic; no Flutter/Next.js changes in scope; API responses use the standard envelope
- [x] QA strategy incorporated — unit, feature, security, integration, and concurrency tests specified

---

## Technical Context

| Concern | Decision | Source |
|---------|----------|--------|
| Backend framework | Laravel / PHP 8.4+ | Approved stack |
| Database | `kyc` schema via `DB::connection('kyc')` | Already configured in `database.php` |
| Queue | Laravel Horizon, `kyc-verification` queue | Existing Horizon configuration |
| Idempotency | `IdempotencyService` (Redis + `platform.idempotency_records`) | E01/Platform Foundation |
| Audit | `AuditService::record(AuditEventData)` | Platform Foundation |
| Domain events | `OutboxEventPublisher` → `platform.outbox_events` | Platform Foundation (existing) |
| PII hashing | `PiiMasker::hashNationalId()` added to existing class | Platform Foundation (must extend) |
| File storage | `KycDocumentStorageContract` — new interface, `LaravelDiskKycDocumentStorage` impl | E02/Profile pattern |
| Auth middleware | `identity.auth`, `identity.require-permission:kyc.case.*` | E04 (no new middleware) |
| External provider | `KycVerificationProvider` interface; `NullKycVerificationProvider` default | New — TBD-E05-001 unresolved |
| Scheduler | Laravel Scheduler — `kyc:detect-expired` at `02:00 daily` | Standard Laravel |
| Routes | `routes/modules/kyc.php` (stub exists — needs routes added) | KycServiceProvider already loads it |

---

## Phase 0 — Research (Resolved)

All technical unknowns resolved. See `research.md` for full findings.

**Key resolutions**:

1. `kyc` DB connection already configured — no setup work.
2. `PiiMasker::hashNationalId()` does NOT exist — must be added (single method, HMAC-SHA256 like `hashIp()`).
3. File storage: follow `ProfilePhotoStorageContract` pattern — new `KycDocumentStorageContract` in Kyc module.
4. `OutboxEventPublisher` exists — all domain events use it (must be within active DB transaction).
5. Domain event `eventName()` uses PascalCase: `Kyc.Case.Approved`. Audit action uses lowercase: `kyc.case.approved`.
6. Routes file `routes/modules/kyc.php` exists as a placeholder stub — needs actual routes added.
7. `KycVerificationProvider` is a new interface; bound to `NullKycVerificationProvider` until TBD-E05-001 resolved.
8. `KycServiceProvider` is a stub — must be rewritten to register all services.

---

## Phase 1 — Design Artifacts

All design artifacts generated:

| Artifact | Path |
|----------|------|
| Data model | `data-model.md` |
| KYC intake API contract | `contracts/kyc-intake-api.md` |
| KYC review API contract | `contracts/kyc-review-api.md` |
| KYC lifecycle contract | `contracts/kyc-lifecycle-api.md` |
| Domain events | `contracts/events.md` |
| Quickstart validation guide | `quickstart.md` |

---

## Module Structure

```
app/Modules/Kyc/
├── Application/Services/
│   ├── KycCaseService.php           # State machine orchestration
│   ├── KycDocumentService.php       # Upload, validate, supersede
│   └── KycVerificationService.php   # Provider call orchestration
├── Console/Commands/
│   └── DetectExpiredKycCommand.php  # php artisan kyc:detect-expired
├── Domain/
│   ├── Contracts/
│   │   ├── KycDocumentStorageContract.php
│   │   ├── KycVerificationProvider.php
│   │   └── KycCaseRepositoryInterface.php
│   ├── Enums/
│   │   ├── KycCaseStatus.php
│   │   ├── KycDocumentType.php
│   │   └── KycVerificationOutcome.php
│   ├── Events/
│   │   ├── KycCaseApprovedEvent.php
│   │   ├── KycCaseExpiredEvent.php
│   │   └── KycCaseReverificationTriggeredEvent.php
│   ├── Exceptions/
│   │   ├── KycCaseAlreadyOpenException.php
│   │   ├── KycAlreadyApprovedException.php
│   │   ├── KycInvalidStatusTransitionException.php
│   │   ├── KycCaseNotEditableException.php
│   │   ├── KycDuplicateIdentityException.php
│   │   └── KycCaseIncompleteException.php
│   └── Services/
│       ├── KycStateMachine.php
│       └── KycDuplicateIdentityChecker.php
├── Infrastructure/
│   ├── Http/Controllers/
│   │   ├── KycCaseController.php
│   │   ├── KycDocumentController.php
│   │   └── KycReviewController.php
│   ├── Http/Resources/
│   │   └── KycCaseResource.php
│   ├── Jobs/
│   │   └── PerformAutomatedVerificationJob.php
│   ├── Migrations/ (6 migrations)
│   ├── Models/
│   │   ├── KycCaseModel.php
│   │   ├── KycPersonalInfoModel.php
│   │   ├── KycDocumentModel.php
│   │   └── KycVerificationResultModel.php
│   ├── Persistence/
│   │   └── EloquentKycCaseRepository.php
│   ├── Providers/
│   │   └── NullKycVerificationProvider.php
│   └── Storage/
│       └── LaravelDiskKycDocumentStorage.php
└── Providers/
    └── KycServiceProvider.php  (rewrite stub)
```

---

## Implementation Approach

**Order is critical.** Each phase builds on the previous one.

### Step 1: Platform Extension
- Add `PiiMasker::hashNationalId(string $id): string` to existing `PiiMasker.php`
- Add unit test in Platform module

### Step 2: Domain Layer
- Create `KycCaseStatus`, `KycDocumentType`, `KycVerificationOutcome` enums
- Create `KycStateMachine` (pure: takes current status + transition name → validates + returns new status)
- Create `KycVerificationProvider` interface and `NullKycVerificationProvider`
- Create `KycDocumentStorageContract` and `LaravelDiskKycDocumentStorage`
- Create domain exceptions

### Step 3: Migrations (in order)
- Migration 1: `kyc.kyc_cases`
- Migration 2: `kyc.kyc_personal_info`
- Migration 3: `kyc.kyc_documents`
- Migration 4: `kyc.kyc_verification_results`
- Migration 5: Seed `identity.permissions` and `identity.role_permissions` (6 new permissions)
- Migration 6: Seed `platform.configuration_values` (6 entries with TBD markers)

### Step 4: Eloquent Models
- One model per table; `$connection = 'kyc'`, `$table = 'kyc.{name}'`

### Step 5: Application Services
- `KycCaseService`: start, capturePersonalInfo (with duplicate check), submit, approve, reject, requestInfo, resubmit, reverify
- `KycDocumentService`: upload (validate + store + supersede), validateFormat, validateExpiry
- `KycVerificationService`: dispatch job, process outcome

### Step 6: Domain Events
- `KycCaseApprovedEvent`, `KycCaseExpiredEvent`, `KycCaseReverificationTriggeredEvent` extend `BaseIntegrationEvent`

### Step 7: HTTP Layer
- `KycCaseController`, `KycDocumentController`, `KycReviewController`
- Routes in `routes/modules/kyc.php`
- `KycServiceProvider` rewrite: bind `KycVerificationProvider` → `NullKycVerificationProvider`, bind storage, register Artisan command, register scheduler

### Step 8: Artisan Command
- `DetectExpiredKycCommand` with `--batch-size` and `--dry-run` options

### Step 9: Queue Job
- `PerformAutomatedVerificationJob` — dispatched after submit when `kyc.auto_verify_enabled = true`

### Step 10: Tests (TDD where applicable)
- Unit: `KycStateMachine`, `PiiMasker::hashNationalId`, duplicate checker
- Feature: all 18 API scenarios from spec
- Security: cross-user access returns 404, PII not in DB, provider response not stored
- Integration: full happy path, rejection+resubmit, duplicate detection
- Concurrency: concurrent start-kyc, concurrent submit+approve

---

## Key Design Constraints

### PII Rules
- `national_id_number` must be hashed by `PiiMasker::hashNationalId()` BEFORE `DB::insert()`. Never log or return plaintext.
- Provider raw response must be HMAC-SHA256 hashed before storage. Never stored raw anywhere.
- `AuditEventData.ipAddress` passed as `PiiMasker::hashIp($request->ip())`.

### Transaction Boundaries
All KYC state mutations that emit domain events MUST wrap:
1. `DB::connection('kyc')` state update
2. `AuditService::record()`
3. `OutboxEventPublisher::publish()`

in a single database transaction. The `OutboxEventPublisher` enforces this with a runtime assertion.

### Idempotency
Apply `IdempotencyMiddleware` on:
- `POST /v1/kyc/cases` — store `idempotency_key` in `kyc_cases` row
- `POST /v1/kyc/cases/{id}/submit`
- `POST /v1/kyc/cases/{id}/resubmit`

### TBD Handling
When a TBD config value is required for logic (e.g., `expires_at = verified_at + kyc.validity_period_days`):
- Read from `ConfigurationService`
- If `is_tbd = true` or value is `null`: log a structured warning, use `null` for the field, do NOT throw an exception (cases still transition to `approved`)
- Log: `kyc.config.tbd_active` with key name

### Authorization Scoping
- Customers/Agents: `GET /v1/kyc/cases/{id}` returns 404 for cases not belonging to them
- `GET /v1/kyc/cases/my` → resolves to authenticated actor's own case
- `GET /v1/kyc/cases` (without `/my`) is Compliance Officer only

---

## Observability

All critical request paths include:
- `X-Correlation-ID` header threading (from Platform Foundation middleware)
- Structured log events at info level for state transitions
- Metric counters: `kyc.case.initiated`, `kyc.case.approved`, `kyc.case.rejected`, `kyc.provider.call`, `kyc.duplicate_identity_detected`
- Audit records in `platform.audit_events` for all state transitions

---

## Complexity Notes

Any change to the module structure, technology, or bounded-context interface requires an ADR and a Constitution Check update before implementation.

The `KycVerificationProvider` interface is the primary extension point for E05. When TBD-E05-001 is resolved, only the concrete provider implementation and its binding in `KycServiceProvider` need to change — no other code is affected.
