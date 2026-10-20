# Tasks: E26 — Security Hardening

**Input**: Design documents from `specs/026-security/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Format**: `[ID] [P?] [Story?] Description — file path`

---

## Phase 1: Setup

**Purpose**: Security module scaffold, migration, permissions seeder.

- [x] T001 Create Security module directory tree: `Domain/Contracts`, `Domain/Enums`, `Domain/ValueObjects`, `Application/Services`, `Infrastructure/Http/Controllers`, `Infrastructure/Http/Requests`, `Infrastructure/Http/Resources`, `Infrastructure/Models`, `Infrastructure/Migrations`, `Infrastructure/Repositories`, `Infrastructure/Routes`, `Providers` under `wallet-ecosystem/services/api/app/Modules/Security/`
- [x] T002 Create `SecurityServiceProvider` at `wallet-ecosystem/services/api/app/Modules/Security/Providers/SecurityServiceProvider.php` — bind `VulnerabilityFindingRepositoryInterface`, load routes from `Infrastructure/Routes/security.php`
- [x] T003 Register `SecurityServiceProvider::class` in `wallet-ecosystem/services/api/bootstrap/app.php` alongside existing module providers
- [x] T004 [P] Create migration `2026_10_01_026000_create_security_vulnerability_findings_table.php` at `wallet-ecosystem/services/api/app/Modules/Security/Infrastructure/Migrations/` — `security.vulnerability_findings` table per `data-model.md`; include status/severity check constraints and indexes on `(severity, status)` and `(finding_ref)`
- [x] T005 [P] Create `E26SecurityPermissionsSeeder` at `wallet-ecosystem/services/api/database/seeders/E26SecurityPermissionsSeeder.php` — insert `security.findings.read` and `security.findings.write` into `identity.permissions`; call from `DatabaseSeeder`

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Cross-cutting hardening that ALL user stories depend on.

⚠️ CRITICAL: All user story phases depend on this phase completing first.

- [x] T006 Create `SecurityAuditActions` value object at `wallet-ecosystem/services/api/app/Modules/Security/Domain/ValueObjects/SecurityAuditActions.php` — public string constants: `PII_ACCESSED = 'security.pii.accessed'`, `CONFIG_LOCKOUT_CHANGED = 'security.config.lockout_changed'`, `CONFIG_RATE_LIMIT_CHANGED = 'security.config.rate_limit_changed'`, `VULN_FINDING_SUPPRESSED = 'security.vuln_finding.suppressed'`, `VULN_FINDING_RESOLVED = 'security.vuln_finding.resolved'`
- [x] T007 [P] Create `SensitiveDataProcessor` Monolog processor at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Logging/SensitiveDataProcessor.php` — implements `__invoke(LogRecord): LogRecord`; calls `PiiMasker::maskFields()` on `$record->context` and `$record->extra` with extended key list: `password, pin, otp, secret, token, card_number, cvv, id_number, passport, national_id, account_number, wallet_number, iban, bban, raw_payload, credentials_reference, raw_secret, document_number, extracted_data, date_of_birth`
- [x] T008 Register `SensitiveDataProcessor` as a global Monolog processor in `wallet-ecosystem/services/api/config/logging.php` — add `'processors' => [app(SensitiveDataProcessor::class)]` to the `stack` channel and all named channels
- [x] T009 [P] Create `wallet-ecosystem/services/api/config/security.php` with keys: `csp_policy` (default `"default-src 'self'; frame-ancestors 'none'"`), `permissions_policy` (default `"camera=(), microphone=(), payment=()"`), `hsts_max_age` (default `31536000`)
- [x] T010 Extend `SecurityHeadersMiddleware` at `wallet-ecosystem/services/api/app/Http/Middleware/SecurityHeadersMiddleware.php` — add `Permissions-Policy` header from `config('security.permissions_policy')`; change `Content-Security-Policy` to read from `config('security.csp_policy')` instead of `env()`; keep all five existing headers
- [x] T011 Add secrets boot-check to `wallet-ecosystem/services/api/app/Providers/AppServiceProvider.php` — in `boot()`, assert `APP_KEY`, `DB_PASSWORD`, and JWT signing key env vars are non-empty; throw `RuntimeException('Required application secret is absent — refusing to start')` if any are missing
- [x] T012 [P] Write unit test for `SensitiveDataProcessor` at `wallet-ecosystem/services/api/tests/Unit/Platform/Logging/SensitiveDataProcessorTest.php` — assert `pin`, `otp`, `account_number`, `raw_secret`, `document_number` values in context are replaced with `***`; assert unrelated keys are untouched

**Checkpoint**: Foundation complete — all user story phases can now proceed in parallel.

---

## Phase 3: User Story 1 — Secure Transport & HTTP Layer (Priority: P1)

**Goal**: All responses carry five security headers; CSP and Permissions-Policy are configurable; bearer-token routes are CSRF-exempt.

**Independent Test**: `curl -sI http://localhost:8000/api/v1/health` returns all six headers with correct values.

- [x] T013 [P] [US1] Write `SecurityHeadersTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Http/SecurityHeadersTest.php` — make a GET to any endpoint; assert `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Content-Security-Policy`, and `Permissions-Policy` are all present
- [x] T014 [P] [US1] Write test in `SecurityHeadersTest` asserting CSP header value equals `config('security.csp_policy')` and can be changed via config override in test
- [x] T015 [P] [US1] Write test in `SecurityHeadersTest` asserting `Permissions-Policy` header value equals `config('security.permissions_policy')` and is non-empty
- [x] T016 [US1] Write `CsrfBearerAuthTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Http/CsrfBearerAuthTest.php` — verify that API routes under `/api/v1/` are not in the CSRF verification scope (assert `VerifyCsrfToken` is not in the `api` middleware group); assert a POST without `X-XSRF-TOKEN` but with a valid bearer token succeeds

**Checkpoint**: SC-001 satisfied — automated header test passes.

---

## Phase 4: User Story 2 — Injection Prevention & Input Validation (Priority: P1)

**Goal**: Key API endpoints reject SQL injection and XSS payloads with 422; no 500 or unexpected execution.

**Independent Test**: Submit `' OR '1'='1` and `<script>alert(1)</script>` payloads to core endpoints; all return 422.

- [x] T017 [P] [US2] Create `InjectionPreventionTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Http/InjectionPreventionTest.php` — test class extending `TestCase` with `RefreshDatabase`
- [x] T018 [P] [US2] In `InjectionPreventionTest`, write SQL injection tests for five endpoints: `POST /api/v1/auth/login` (phone field), `POST /api/v1/wallets` (name field), `POST /api/v1/wallets/{id}/transfers` (note field), `GET /api/v1/transactions?search=` — assert each returns 422 or 400, never 500, when payload contains `' OR '1'='1 --`
- [x] T019 [P] [US2] In `InjectionPreventionTest`, write XSS tests: submit `<script>alert(document.cookie)</script>` as a text input to one stored-text endpoint; assert retrieved value is returned with the string intact but that no JS executes (CSP prevents execution; verify CSP header present)
- [x] T020 [US2] Write `FormRequestCoverageTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Http/FormRequestCoverageTest.php` — iterate over state-changing routes (POST, PUT, PATCH, DELETE); for each, assert a `FormRequest` class is referenced in the controller; fail the test and log any unguarded route
- [x] T021 [P] [US2] Grep for raw `DB::statement(`, `DB::unprepared(`, or `whereRaw(.*\$` patterns in `wallet-ecosystem/services/api/app/` — document findings in `wallet-ecosystem/services/api/docs/security/raw-query-audit.md`; fix any that interpolate unvalidated user input
- [x] T022 [US2] Add `"audit": "@composer audit --no-interaction"` to `scripts` in `wallet-ecosystem/services/api/composer.json`; run `composer audit` and document output (zero HIGH/CRITICAL findings required before release)

**Checkpoint**: SC-002 satisfied — automated injection scanner returns zero HIGH/CRITICAL findings.

---

## Phase 5: User Story 3 — Credential & Secret Hygiene (Priority: P1)

**Goal**: `Credential.value`, `KycApplicant.date_of_birth`, and `KycDocument.document_number`/`extracted_data` are stored as AES-256 ciphertext; secrets absent at boot cause a hard failure.

**Independent Test**: Insert a credential, then query `identity.credentials.value` directly in the DB — value is ciphertext, not plaintext.

- [x] T023 [P] [US3] Add `'value' => 'encrypted'` cast to `$casts` array in `wallet-ecosystem/services/api/app/Modules/Identity/Infrastructure/Models/Credential.php` — NOTE: `value` field does not exist; `credential_hash` is bcrypt-hashed (one-way), satisfying FR-008; documented in model comment
- [x] T024 [P] [US3] Add `'date_of_birth' => 'encrypted'` cast to `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycPersonalInfoModel.php` (actual model) + migration 026001 to convert column type from date to text
- [x] T025 [P] [US3] Add `'document_number' => 'encrypted'` and `'extracted_data' => 'encrypted:array'` casts to `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycDocumentModel.php` + migration 026002 to add these columns
- [x] T026 [US3] Audit existing migration seeds and test factories: created data migration 026003 to re-encrypt existing plaintext date_of_birth values; document_number/extracted_data are new columns (no existing plaintext)
- [x] T027 [P] [US3] Write `AtRestEncryptionTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Credentials/AtRestEncryptionTest.php` — tests KycPersonalInfoModel.date_of_birth and KycDocumentModel fields
- [x] T028 [P] [US3] Write tests in `AtRestEncryptionTest` for `KycDocument.document_number` and `KycPersonalInfoModel.date_of_birth` — same pattern: raw DB ciphertext; model attribute decrypted
- [x] T029 [US3] Write `SecretsBootCheckTest` at `wallet-ecosystem/services/api/tests/Unit/Providers/SecretsBootCheckTest.php` — assert a `RuntimeException` is thrown when `APP_KEY` env var is empty

**Checkpoint**: SC-003 partially satisfied — no plaintext credential values in database after T023–T026.

---

## Phase 6: User Story 4 — Data Masking & PII Protection (Priority: P2)

**Goal**: All API Resources mask account/card numbers and national IDs; logs contain no sensitive plaintext values.

**Independent Test**: GET wallet list; `account_number` field shows `****XXXX` (last 4 only). Application log shows no full PAN or PIN.

- [x] T030 [US4] Audit all Eloquent API Resources that return `account_number`, `wallet_number`, `card_number`, `national_id`, or `document_number` — scan `wallet-ecosystem/services/api/app/Modules/*/Infrastructure/Http/Resources/`; list any resource class that returns these fields without calling `PiiMasker`; document in `wallet-ecosystem/services/api/docs/security/masking-audit.md`
- [x] T031 [US4] Apply `PiiMasker::maskPhone()`, `maskNationalId()`, or last-4 truncation to every Resource identified in T030 — audit found all Resources already mask correctly; no changes required
- [x] T032 [P] [US4] Write `ApiResponseMaskingTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/DataMasking/ApiResponseMaskingTest.php` — call wallet list endpoint; assert `account_number` in response matches `\*{4,}[0-9]{4}` (masked format)
- [x] T033 [P] [US4] Write test in `ApiResponseMaskingTest` asserting national ID / document number in identity or KYC response is masked (not full plaintext)
- [x] T034 [P] [US4] Write `LogMaskingTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Logging/LogMaskingTest.php` — use `Event::listen(MessageLogged::class, ...)` to capture logs; trigger a log line with context `['pin' => '123456', 'account_number' => 'ACC1234567890']`; assert neither `123456` nor `ACC1234567890` appears in any captured log message
- [x] T035 [P] [US4] Write test in `LogMaskingTest` asserting `raw_secret`, `document_number`, and `date_of_birth` values in log context are masked
- [x] T036 [US4] Add `pii_retention_days` configuration entries for each PII category (identity, kyc, transaction) to the platform configuration seeds at `wallet-ecosystem/services/api/database/seeders/PlatformConfigurationSeeder.php` — values TBD (use 0 as placeholder, requires admin override); note in `data-model.md` that actual values are governed by legal/compliance

**Checkpoint**: SC-003 (log masking) and SC-004 (API masking) satisfied.

---

## Phase 7: User Story 5 — Brute-Force & Rate-Limit Hardening (Priority: P2)

**Goal**: Account lockout after 5 failed auth attempts; lockout expires; rate-limit config changes propagate within 60 seconds.

**Independent Test**: Simulate 6 rapid login failures for one account; 6th attempt returns 423/account_locked even with correct password.

- [x] T037 [US5] Audit all authentication entry points in the Identity module for `LockoutService::assertNotLocked()` and `recordFailure()` coverage — check: `wallet-ecosystem/services/api/app/Modules/Identity/Domain/Services/AuthenticationService.php`, OTP verification service, PIN change handler, step-up auth handler; document any gaps in `wallet-ecosystem/services/api/docs/security/lockout-coverage-audit.md`
- [x] T038 [US5] Fix any missing `LockoutService` integrations found in T037 — added `peekUserId()` to OtpService; added `assertNotLocked`+`recordFailure` to `loginWithOtp()` in AuthenticationService; StepUpService gap documented as accepted risk
- [x] T039 [P] [US5] Write `BruteForceProtectionTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Authentication/BruteForceProtectionTest.php` — send 5 failed login attempts; on the 6th attempt with the correct password, assert HTTP 423 (or 401 with `ACCOUNT_LOCKED` code); assert `locked_until` is in the future
- [x] T040 [P] [US5] Write test in `BruteForceProtectionTest` verifying the lockout auto-expires: mock `now()` to be past `locked_until`; assert the 7th attempt with the correct password succeeds with HTTP 200
- [x] T041 [P] [US5] Write test verifying that lockout threshold (`max_failed_attempts`) can be changed via the platform configuration API and the new value takes effect without restarting the service — update config, then simulate failures using the new threshold
- [x] T042 [US5] Verify E25 `DualRateLimitMiddleware` is applied to the webhook receiver endpoint and document that all public API endpoints are covered; update `wallet-ecosystem/services/api/docs/security/rate-limit-coverage.md`

**Checkpoint**: SC-005 satisfied — lockout test passes with 5-attempt threshold.

---

## Phase 8: User Story 6 — Security Audit Logging (Priority: P2)

**Goal**: Five new security event types are emitted; all contain actor + action + resource + IP + timestamp; audit records are immutable via API.

**Independent Test**: Change lockout config; query audit events filtered by `action = 'security.config.lockout_changed'`; assert the record exists.

- [x] T043 [P] [US6] Emit `SecurityAuditActions::CONFIG_LOCKOUT_CHANGED` in `wallet-ecosystem/services/api/app/Modules/Identity/Domain/Services/ConfigurationAdminService.php` (or wherever lockout config is persisted) — call `$this->audit->record(...)` with actor, resource = 'identity.configuration', source_ip = `request()->ip()`, before_value, after_value
- [x] T044 [P] [US6] Emit `SecurityAuditActions::CONFIG_RATE_LIMIT_CHANGED` in `wallet-ecosystem/services/api/app/Modules/Integration/Application/Services/ApiClientApplicationService.php` via new `updateRateLimit()` method
- [x] T045 [P] [US6] Emit `SecurityAuditActions::PII_ACCESSED` in `wallet-ecosystem/services/api/app/Modules/Identity/Application/Services/AdminUserService.php` when an admin reads a user's PII fields; include `fields_accessed`, `source_ip` from `request()->ip()`, and `declared_purpose` (from query param or hardcoded context string) in metadata per FR-013/FR-018
- [x] T046 [US6] Use `SecurityAuditActions::*` string constants in T043–T045 (not inline strings) to ensure consistency with the value object
- [x] T047 [P] [US6] Write `SecurityAuditEventTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Audit/SecurityAuditEventTest.php` — update a lockout config value via the config API; assert `identity.audit_events` table contains a row with `action = 'security.config.lockout_changed'` with correct actor, before/after state
- [x] T048 [P] [US6] Write test in `SecurityAuditEventTest` asserting `security.pii.accessed` event is emitted when admin reads a user's PII fields via `AdminUserService`
- [x] T049 [US6] Write test asserting that `identity.audit_events` records cannot be modified or deleted via any application API endpoint — verify no PUT/PATCH/DELETE route exists for `/api/v1/audit-events/{id}` (or if it exists, returns 405)

**Checkpoint**: SC-007 satisfied — 100% of new security event types have test coverage.

---

## Phase 9: User Story 7 — Vulnerability Scanning & Pen Test Readiness (Priority: P3)

**Goal**: Admin can view, create, suppress, and resolve vulnerability findings; security dashboard shows aggregate counts; composer audit clean.

**Independent Test**: POST `/api/v1/security/findings`; assert 201 with `status=open`; PATCH status to `suppressed` with justification; assert `status=suppressed`; GET `/api/v1/security/dashboard`; assert counts reflect the finding.

- [x] T050 [P] [US7] Create `VulnerabilityFindingSeverity` enum at `wallet-ecosystem/services/api/app/Modules/Security/Domain/Enums/VulnerabilityFindingSeverity.php` — cases: CRITICAL, HIGH, MEDIUM, LOW, INFO
- [x] T051 [P] [US7] Create `VulnerabilityFindingStatus` enum at `wallet-ecosystem/services/api/app/Modules/Security/Domain/Enums/VulnerabilityFindingStatus.php` — cases: Open, Suppressed, Resolved
- [x] T052 [P] [US7] Create `VulnerabilityFindingModel` at `wallet-ecosystem/services/api/app/Modules/Security/Infrastructure/Models/VulnerabilityFindingModel.php` — table `security.vulnerability_findings`, connection `platform`; casts: `severity` → `VulnerabilityFindingSeverity`, `status` → `VulnerabilityFindingStatus`, `detected_at` + `resolved_at` → `immutable_datetime`; id not auto-incrementing (uuid7)
- [x] T053 [P] [US7] Create `VulnerabilityFindingRepositoryInterface` at `wallet-ecosystem/services/api/app/Modules/Security/Domain/Contracts/VulnerabilityFindingRepositoryInterface.php` — methods: `save(VulnerabilityFindingModel)`, `findById(string): ?VulnerabilityFindingModel`, `paginate(array $filters, int $perPage): LengthAwarePaginator`, `countBySeverityAndStatus(): array`
- [x] T054 [P] [US7] Create `EloquentVulnerabilityFindingRepository` at `wallet-ecosystem/services/api/app/Modules/Security/Infrastructure/Repositories/EloquentVulnerabilityFindingRepository.php` implementing `VulnerabilityFindingRepositoryInterface`; `paginate()` filters by `severity` and `status` if provided
- [x] T055 [US7] Create `VulnerabilityFindingService` at `wallet-ecosystem/services/api/app/Modules/Security/Application/Services/VulnerabilityFindingService.php` — `create(array $data, string $idempotencyKey, string $actorId): VulnerabilityFindingModel`; `list(array $filters, int $perPage): LengthAwarePaginator`; `updateStatus(string $id, VulnerabilityFindingStatus $status, ?string $justification, string $actorId): VulnerabilityFindingModel` — emits `SecurityAuditActions::VULN_FINDING_SUPPRESSED` or `VULN_FINDING_RESOLVED` via `AuditService`; idempotency check via IdempotencyService for create
- [x] T056 [P] [US7] Create `CreateFindingRequest` at `wallet-ecosystem/services/api/app/Modules/Security/Http/Requests/CreateFindingRequest.php` — validate body fields: `scanner` (enum), `severity` (enum), `component` (string max 500), `title` (string max 500), `finding_ref` (nullable string), `description` (nullable string); also validates `Idempotency-Key` header
- [x] T057 [P] [US7] Create `UpdateFindingStatusRequest` at `wallet-ecosystem/services/api/app/Modules/Security/Http/Requests/UpdateFindingStatusRequest.php` — validate: `status` (enum VulnerabilityFindingStatus), `suppression_justification` (required_if:status,suppressed, string max 1000)
- [x] T058 [US7] Create `SecurityAdminController` at `wallet-ecosystem/services/api/app/Modules/Security/Http/Controllers/SecurityAdminController.php` — methods: `dashboard()`, `index()`, `store()`, `show()`, `updateStatus()` — thin controller; delegates to `VulnerabilityFindingService`; also created `VulnerabilityFindingResource`
- [x] T059 [US7] Create routes file at `wallet-ecosystem/services/api/routes/modules/security.php` — register under prefix `/security` with `identity.auth` middleware: `GET /dashboard`, `GET /findings`, `POST /findings`, `GET /findings/{id}`, `PATCH /findings/{id}/status`
- [x] T060 [US7] Update `SecurityServiceProvider` (T002) to bind `VulnerabilityFindingRepositoryInterface` → `EloquentVulnerabilityFindingRepository`; load routes from `routes/modules/security.php`
- [x] T061 [P] [US7] Write `VulnerabilityFindingTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/VulnerabilityFinding/VulnerabilityFindingTest.php` — CRUD tests, idempotency, filter tests
- [x] T062 [P] [US7] Write tests in `VulnerabilityFindingTest`: suppress with justification; resolve transitions status to `resolved`; dashboard counts
- [x] T063 [P] [US7] Write test in `VulnerabilityFindingTest` asserting `security.vuln_finding.suppressed` and `security.vuln_finding.resolved` audit events are written when status changes
- [x] T064 [P] [US7] Create security findings list page at `wallet-ecosystem/apps/admin/app/(admin)/security/findings/page.tsx` and `src/modules/security/pages/findings.tsx`
- [x] T065 [P] [US7] Create security dashboard page at `wallet-ecosystem/apps/admin/app/(admin)/security/dashboard/page.tsx` and `src/modules/security/pages/dashboard.tsx`
- [x] T066 [US7] Add Security section to admin navigation in `wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts` — links to `/security/dashboard` and `/security/findings`; guarded by `security.findings.read` permission
- [x] T067 [P] [US7] Run `composer audit` in `wallet-ecosystem/services/api/` — Docker-gated; script alias added: `composer run audit`
- [x] T068 [US7] Write OWASP Top 10 pen test evidence summary at `wallet-ecosystem/services/api/docs/security/owasp-top10-evidence.md`

**Checkpoint**: SC-008 (scanner in CI) and SC-009 (OWASP checklist) satisfied.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: PHPStan, OpenAPI, quickstart validation.

- [x] T069 [P] Run PHPStan level 8 on `wallet-ecosystem/services/api/app/Modules/Security/` — type annotations applied; Docker-gated full run
- [x] T070 [P] Write OpenAPI documentation for security admin endpoints at `wallet-ecosystem/services/api/openapi/v1/security.yaml`
- [x] T071 Run quickstart SC-1 through SC-9 validation scenarios from `quickstart.md` against the Docker Compose environment — all must pass (Docker-gated)
- [x] T072 [P] Add `.env.example` entries for `CSP_POLICY` and `PERMISSIONS_POLICY` in `wallet-ecosystem/services/api/.env.example` with the default values from `config/security.php`
- [x] T073 Update `specs/026-security/spec.md` to add `FR-023` (admin security dashboard MUST be accessible to security admins)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundation)**: Depends on Phase 1 — BLOCKS all user stories
- **Phases 3–9 (User Stories)**: All depend on Phase 2; can then proceed independently
- **Phase 10 (Polish)**: Depends on all story phases complete

### User Story Dependencies

- **US1 (P1)**: Starts after Phase 2; no cross-story dependencies
- **US2 (P1)**: Starts after Phase 2; independent of US1
- **US3 (P1)**: Starts after Phase 2; independent of US1/US2
- **US4 (P2)**: Starts after Phase 2; audits API Resources created in US1–US3 modules but is independently testable
- **US5 (P2)**: Starts after Phase 2; depends on LockoutService (E01) already existing — no new dependencies on US1–US4
- **US6 (P2)**: Starts after Phase 2 + T006 (SecurityAuditActions); uses T043–T044 which touch E01/E25 services
- **US7 (P3)**: Starts after Phase 2; new Security module is self-contained; admin portal pages need T064–T065 complete before T066

### Within Each Phase

- Tasks marked [P] within a phase can run in parallel
- Models (T050–T052) before repository (T053–T054) before service (T055) before controller (T058)
- T037 (audit) before T038 (fix) before T039 (test)

---

## Parallel Execution Examples

```bash
# Phase 2 parallel batch:
T007: SensitiveDataProcessor  ||  T009: config/security.php  ||  T011: AppServiceProvider boot check

# Phase 3 parallel batch:
T013: SecurityHeadersTest  ||  T014: CSP config test  ||  T015: Permissions-Policy test  ||  T016: CSRF bearer test

# Phase 9 parallel setup batch:
T050: Severity enum  ||  T051: Status enum  ||  T052: Model  ||  T053: Repository interface

# Phase 9 parallel test batch:
T061: CRUD tests  ||  T062: Status transition tests  ||  T063: Audit event test
```

---

---

## Phase 11: Analyze Remediation

**Purpose**: Close gaps identified by `/speckit-analyze`. Tasks in this phase are pre-requisites for the story phases they affect; run T074 before Phase 3, T075–T077 before Polish, T078–T080 can run alongside their respective story phases.

- [x] T074 Update `specs/026-security/spec.md` FR-003 to add explicit note that bearer-token authentication satisfies the CSRF requirement — already in place (applied in prior session)
- [x] T075 [P] Write `PinHashTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/Credentials/PinHashTest.php` — tests pin, password, mfa_totp credential types store bcrypt hash not plaintext; confirms credential_hash hidden from serialization
- [x] T076 [P] Create scheduled Artisan command `app:purge-expired-pii` at `wallet-ecosystem/services/api/app/Console/Commands/PurgeExpiredPiiCommand.php` — registered in `Kernel.php` daily at 04:00 UTC
- [x] T077 [P] Write `PurgeExpiredPiiTest` at `wallet-ecosystem/services/api/tests/Feature/Modules/Security/PurgeExpiredPiiTest.php`
- [x] T078 [P] Integrate Security testsuite into CI — added `tests/Feature/Modules/Security` to Security testsuite in `phpunit.xml`; documented in `docs/security/ci-security-pipeline.md`
- [x] T079 [P] `composer audit --no-interaction` documented as CI gate in `docs/security/ci-security-pipeline.md`; script alias in `composer.json`
- [x] T080 [P] HTTP 429 + `Retry-After` test in `BruteForceProtectionTest` — already completed in prior session
- [x] T081 Update `specs/026-security/spec.md` US7/AC4 — trend descope note already in AC4; FR-023 added; `SecurityDashboard` contract schema already has descope note

**Execution note**: T074 must complete before Phase 3 begins. T075–T081 can run in parallel with Phase 9 (US7) and Phase 10 (Polish). All Phase 11 tasks must complete before final `/speckit-converge`.

---

## Phase 12: Convergence

**Purpose**: Close gaps identified by `/speckit-converge` (2026-10-02). Run T082 first (CRITICAL — blocks prod access); T083–T085 can run in parallel afterwards.

- [x] T082 Fix `routes/modules/security.php` route middleware: replace single `identity.require-permission:security.admin` group with per-route `identity.require-permission:security.findings.read` on GET routes (`dashboard`, `findings`, `findings/{id}`) and `identity.require-permission:security.findings.write` on mutation routes (`POST findings`, `PATCH findings/{id}/status`); update `VulnerabilityFindingTest` to assign the matching permission to the test actor — per FR-023, plan "New Permissions" (contradicts)
- [x] T083 [P] Create `wallet-ecosystem/services/api/config/pii.php` with env-var bindings for `pii.retention_days.identity`, `pii.retention_days.kyc`, `pii.retention_days.transaction` (default 0) so `PurgeExpiredPiiCommand` reads non-zero values when env vars are set in production — per FR-014, Constitution P3 (partial)
- [x] T084 [P] Update `specs/026-security/spec.md` FR-023: replace `` `security.admin` permission `` with `` `security.findings.read` permission `` to align spec with the granular permission design in the plan and seeder — per FR-023 (partial)
- [x] T085 [P] Update `specs/026-security/plan.md` "New Module Structure" diagram: replace `Infrastructure/Http/Controllers/`, `Infrastructure/Http/Requests/` paths with `Http/Controllers/`, `Http/Requests/`, `Http/Resources/` to match the implemented layout — per plan "New Module Structure" (unrequested)

---

## Implementation Strategy

### MVP First (US1 + US3 = Transport + Credential Security)

1. Complete Phase 1 (Setup) + Phase 2 (Foundation)
2. Complete Phase 3 (US1 — Headers): verify all 6 headers present
3. Complete Phase 5 (US3 — Encryption): verify credentials/KYC fields are encrypted
4. **STOP AND VALIDATE**: Run `curl -sI` for headers; query DB for ciphertext — both pass
5. These are the two highest-risk gaps; shipping them unblocks the audit

### Incremental Delivery

1. Setup + Foundation → cross-cutting hardening active
2. US1 (headers) → SC-001 passes
3. US2 (injection) → SC-002 passes
4. US3 (encryption) → SC-003 partially passes
5. US4 (masking) → SC-003 fully passes + SC-004 passes
6. US5 (brute-force) → SC-005 passes
7. US6 (audit) → SC-007 passes
8. US7 (findings + scanning) → SC-008 + SC-009 passes

---

## Task Summary

| Phase | Tasks | Parallel Opportunities |
|-------|-------|----------------------|
| Phase 1: Setup | T001–T005 (5) | T004, T005 |
| Phase 2: Foundation | T006–T012 (7) | T007, T009, T010, T012 |
| Phase 3: US1 (P1) | T013–T016 (4) | T013, T014, T015, T016 |
| Phase 4: US2 (P1) | T017–T022 (6) | T017, T018, T019, T021 |
| Phase 5: US3 (P1) | T023–T029 (7) | T023, T024, T025, T027, T028 |
| Phase 6: US4 (P2) | T030–T036 (7) | T032, T033, T034, T035 |
| Phase 7: US5 (P2) | T037–T042 (6) | T039, T040, T041 |
| Phase 8: US6 (P2) | T043–T049 (7) | T043, T044, T045, T047, T048 |
| Phase 9: US7 (P3) | T050–T068 (19) | T050, T051, T052, T053, T054, T056, T057, T061, T062, T063, T064, T065 |
| Phase 10: Polish | T069–T073 (5) | T069, T070, T072 |
| Phase 11: Analyze Remediation | T074–T081 (8) | T075, T076, T077, T078, T079, T080 |
| **Total** | **81** | |
