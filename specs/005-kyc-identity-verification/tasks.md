# Tasks: E05 â€” KYC & Identity Verification

**Input**: `specs/005-kyc-identity-verification/` â€” spec.md, plan.md, data-model.md, contracts/, research.md, quickstart.md

**Prerequisites**: E01 (Identity, auth), E04 (permissions, middleware) complete.

**Test strategy**: Tests included throughout â€” unit, feature, security, integration, and concurrency per spec section 15.

**Format**: `- [ ] T### [P?] [Story?] Description â€” file path`
- **[P]**: Parallelizable (different files, no blocking dependencies within the phase)
- **[USn]**: n-th story phase label (sequential, 1â€”10); see each phase heading for the covered `US-KYC-NNN` stories (one phase may group multiple user stories)
- Base service path: `wallet-ecosystem/services/api/`

---

## Phase 1: Setup â€” Platform Extension & Domain Skeleton

**Purpose**: Extend the Platform module and create the KYC domain skeleton that ALL subsequent phases depend on. No story can be implemented without this phase being complete.

**âš ï¸ CRITICAL: Must complete fully before Phase 2 begins.**

- [X] T001 Add `hashNationalId(string $id): string` (HMAC-SHA256 via `config('app.key')`, mirrors existing `hashIp()`) to `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Security/PiiMasker.php`
- [X] T002 [P] Create `KycCaseStatus` backed enum (`initiated`, `documents_submitted`, `under_review`, `approved`, `rejected`, `needs_info`, `expired`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Enums/KycCaseStatus.php`
- [X] T003 [P] Create `KycDocumentType` backed enum (`national_id`, `passport`, `drivers_licence`, `utility_bill`, `selfie`, `other`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Enums/KycDocumentType.php`
- [X] T004 [P] Create `KycVerificationOutcome` backed enum (`pass`, `fail`, `manual_review_required`, `error`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Enums/KycVerificationOutcome.php`
- [X] T005 [P] Create `KycCaseAlreadyOpenException` (HTTP 409, error code `KYC_CASE_ALREADY_OPEN`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Exceptions/KycCaseAlreadyOpenException.php`
- [X] T006 [P] Create `KycAlreadyApprovedException` (HTTP 409, error code `KYC_ALREADY_APPROVED`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Exceptions/KycAlreadyApprovedException.php`
- [X] T007 [P] Create `KycInvalidStatusTransitionException` (HTTP 422, error code `INVALID_CASE_STATUS`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Exceptions/KycInvalidStatusTransitionException.php`
- [X] T008 [P] Create `KycDuplicateIdentityException` (HTTP 409, error code `DUPLICATE_IDENTITY_DETECTED`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Exceptions/KycDuplicateIdentityException.php`
- [X] T009 [P] Create `KycCaseIncompleteException` (HTTP 422, error code `CASE_INCOMPLETE`; carries `$missing` array field) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Exceptions/KycCaseIncompleteException.php`
- [X] T010 Create `KycStateMachine` pure class â€” `transition(KycCaseStatus $from, string $action): KycCaseStatus`; throws `KycInvalidStatusTransitionException` on all invalid transitions; encodes all 11 unique FROM→TO transition pairs from spec state diagram (resubmit and triggerReverification each apply to 2 source states) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Services/KycStateMachine.php`
- [X] T011 [P] Create `KycVerificationProvider` interface (`verify(KycCase $case): VerificationOutcome`) and `VerificationOutcome` readonly class in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Contracts/KycVerificationProvider.php`
- [X] T012 [P] Create `KycDocumentStorageContract` interface (`store()`, `delete()`, `generateSignedUrl()`) and `KycStorageResult` readonly class in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Contracts/KycDocumentStorageContract.php`
- [X] T013 [P] Create `KycCaseRepositoryInterface` (`findOpenCaseForUser()`, `findByIdForActor()`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Contracts/KycCaseRepositoryInterface.php`
- [X] T014 Unit test `PiiMasker::hashNationalId()` â€” same input + same secret â†’ same 64-char hex; empty input â†’ empty string; different inputs â†’ different hashes in `wallet-ecosystem/services/api/tests/Unit/Modules/Platform/Security/PiiMaskerTest.php`

---

## Phase 2: Foundational â€” Migrations, Models, Infrastructure

**Purpose**: Database schema, Eloquent models, provider/storage implementations, domain events, and service provider wiring. ALL story phases are blocked until this phase is complete.

**âš ï¸ CRITICAL: Migrations must run in numeric order (FK constraints).**

- [X] T015 Create migration `2026_09_17_001000_create_kyc_kyc_cases` â€” all columns and indexes from data-model.md; `status` default `initiated`; `attempt_number` default 1 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001000_create_kyc_kyc_cases.php`
- [X] T016 Create migration `2026_09_17_001100_create_kyc_kyc_personal_info` â€” `national_id_hash` column (no `national_id_number`); non-unique index on `national_id_hash` (for fast duplicate-detection lookups; uniqueness enforced by application logic only â€” a DB unique constraint would block legitimate resubmissions by the same person) in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001100_create_kyc_kyc_personal_info.php`
- [X] T017 Create migration `2026_09_17_001200_create_kyc_kyc_documents` â€” `SoftDeletes`; composite partial index `(kyc_case_id, document_type) WHERE deleted_at IS NULL` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001200_create_kyc_kyc_documents.php`
- [X] T018 Create migration `2026_09_17_001300_create_kyc_kyc_verification_results` â€” immutable; `raw_response_hash` NOT NULL; no `raw_response` column in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001300_create_kyc_kyc_verification_results.php`
- [X] T019 Create migration `2026_09_17_001400_seed_kyc_permissions` â€” `insertOrIgnore` 6 permissions into `identity.permissions`; assign all 6 to `admin` role in `identity.role_permissions` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001400_seed_kyc_permissions.php`
- [X] T020 Create migration `2026_09_17_001500_seed_kyc_configuration_values` â€” 6 entries in `platform.configuration_values`; `kyc.auto_verify_enabled = false`; `kyc.provider_max_retries = 3`; 4 TBD entries with `is_tbd = true` and `null` values in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Migrations/2026_09_17_001500_seed_kyc_configuration_values.php`
- [X] T021 [P] Create `KycCaseModel` (`$connection = 'kyc'`, `$table = 'kyc.kyc_cases'`; `$casts` for `status` â†’ `KycCaseStatus`, timestamps) in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycCaseModel.php`
- [X] T022 [P] Create `KycPersonalInfoModel` (`$connection = 'kyc'`, `$table = 'kyc.kyc_personal_info'`; `makeHidden(['national_id_hash'])`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycPersonalInfoModel.php`
- [X] T023 [P] Create `KycDocumentModel` (`$connection = 'kyc'`, `$table = 'kyc.kyc_documents'`; `SoftDeletes`; `$casts` for `document_type` â†’ `KycDocumentType`, `validation_status`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycDocumentModel.php`
- [X] T024 [P] Create `KycVerificationResultModel` (`$connection = 'kyc'`, `$table = 'kyc.kyc_verification_results'`; immutable â€” override `save()` to throw; `makeHidden(['raw_response_hash'])`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Models/KycVerificationResultModel.php`
- [X] T025 Create `EloquentKycCaseRepository` implementing `KycCaseRepositoryInterface` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Persistence/EloquentKycCaseRepository.php`
- [X] T026 Create `NullKycVerificationProvider` implementing `KycVerificationProvider` â€” always returns `VerificationOutcome(outcome: manual_review_required, providerReference: 'null-provider', rawResponse: [])` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Providers/NullKycVerificationProvider.php`
- [X] T027 Create `LaravelDiskKycDocumentStorage` implementing `KycDocumentStorageContract` â€” uses `Storage::disk('kyc-documents')`; `delete()` is no-op if key not found in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Storage/LaravelDiskKycDocumentStorage.php`
- [X] T028 [P] Create `KycCaseApprovedEvent extends BaseIntegrationEvent` (`eventName(): 'Kyc.Case.Approved'`; payload: `case_id`, `user_id`, `verified_at`, `expires_at`, `verification_method`, `attempt_number`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Events/KycCaseApprovedEvent.php`
- [X] T029 [P] Create `KycCaseExpiredEvent extends BaseIntegrationEvent` (`eventName(): 'Kyc.Case.Expired'`; payload: `case_id`, `user_id`, `expired_at`, `was_expires_at`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Events/KycCaseExpiredEvent.php`
- [X] T030 [P] Create `KycCaseReverificationTriggeredEvent extends BaseIntegrationEvent` (`eventName(): 'Kyc.Case.ReverificationTriggered'`; payload: `case_id`, `user_id`, `triggered_by`, `attempt_number`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Events/KycCaseReverificationTriggeredEvent.php`
- [X] T031 Create `KycDuplicateIdentityChecker` â€” queries `kyc.kyc_personal_info JOIN kyc.kyc_cases` for `national_id_hash` match where `status = 'approved'`; returns bool in `wallet-ecosystem/services/api/app/Modules/Kyc/Domain/Services/KycDuplicateIdentityChecker.php`
- [X] T032 Rewrite `KycServiceProvider` â€” bind `KycVerificationProvider` using a config-driven factory: if `ConfigurationService::get('kyc.provider')` is null or TBD â†’ bind `NullKycVerificationProvider`; otherwise resolve and bind the configured class by name (FR-E05-021: provider MUST be swappable via config without code changes); bind `KycDocumentStorageContract` â†’ `LaravelDiskKycDocumentStorage`; register `KycCaseRepositoryInterface` â†’ `EloquentKycCaseRepository`; register Artisan command; register Scheduler hook (`dailyAt('02:00')`) in `wallet-ecosystem/services/api/app/Modules/Kyc/Providers/KycServiceProvider.php`
- [X] T033 Create `KycTestCase` â€” base test class extending `IdentityTestCase`; helpers `kycCreateCase()`, `kycAddPersonalInfo()`, `kycUploadDocument()`, `kycSubmitCase()`; truncates `kyc.*` tables in `setUp()` in `wallet-ecosystem/services/api/tests/Support/KycTestCase.php`
- [X] T034 Unit test `KycStateMachine` â€” assert all 11 valid FROM→TO transition pairs succeed and return correct next status (including both resubmit sources: rejected→initiated and needs_info→initiated; and both reverify sources: approved→initiated and expired→initiated); assert 12+ invalid transitions throw `KycInvalidStatusTransitionException` in `wallet-ecosystem/services/api/tests/Unit/Modules/Kyc/KycStateMachineTest.php`
- [X] T035 Unit test `KycDuplicateIdentityChecker` â€” `hashNationalId()` called correctly; approved case with matching hash â†’ returns true; rejected case with same hash â†’ returns false; no case â†’ returns false in `wallet-ecosystem/services/api/tests/Unit/Modules/Kyc/KycDuplicateIdentityCheckerTest.php`

**Checkpoint**: Run `php artisan migrate --database=kyc`. Verify `kyc.kyc_cases` exists with no `national_id_number` column; `kyc.kyc_verification_results` has `raw_response_hash`; 6 permissions in `identity.permissions`.

---

## Phase 3: US-KYC-001 â€” Customer Starts KYC (P0)

**Goal**: Customer calls `POST /v1/kyc/cases` and receives a new KYC case in `initiated` status.

**Independent test**: `POST /v1/kyc/cases` with Customer token â†’ 201 with `case_id`. Same `Idempotency-Key` â†’ same 201, no duplicate row. Second start with open case â†’ 409 `KYC_CASE_ALREADY_OPEN`.

- [X] T036 [US1] Implement `KycCaseService::startCase(string $userId, string $idempotencyKey): KycCaseModel` â€” check for open case (throw `KycCaseAlreadyOpenException`); check for approved case (throw `KycAlreadyApprovedException`); an `expired` case is NOT open per BR-E05-001 â€” allow start without error (EC-08); create case in `initiated`; write `kyc.case.initiated` audit record in single transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T037 [US1] Create `KycCaseController` with `start(Request $request): JsonResponse` â€” validate `Idempotency-Key` header; call `KycCaseService::startCase()`; return 201 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T038 [P] [US1] Create `KycCaseResource` â€” serializes `case_id`, `status`, `attempt_number`, `created_at`; no PII fields in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Resources/KycCaseResource.php`
- [X] T039 [US1] Register `POST /v1/kyc/cases` route with `identity.auth` + `identity.require-permission:kyc.case.create` middleware in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T040 [P] [US1] Feature test `KycStartCaseTest` â€” 201 success; idempotent replay 201 same body; already-open 409; already-approved 409; unauthenticated 401; missing `Idempotency-Key` 422 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycStartCaseTest.php`
- [X] T041 [P] [US1] Feature test: Customer token without `kyc.case.create` permission â†’ 403 `AUTHORIZATION_001`; DB assertion â€” `kyc.kyc_cases` has correct `user_id`, `status = initiated`, `attempt_number = 1` in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycStartCaseTest.php`
- [X] T042 [P] [US1] Feature test: DB assertion â€” `platform.audit_events` row with `action = kyc.case.initiated` and correct `resource_id` after successful start in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycStartCaseTest.php`

**Checkpoint**: `POST /v1/kyc/cases` â†’ 201. Duplicate start â†’ 409. Audit event written.

---

## Phase 4: US-KYC-002 + US-KYC-016 â€” Personal Information & Duplicate Identity Detection (P0)

**Goal**: Customer submits personal details; system hashes national ID before storage and blocks duplicate identities matched to any approved case.

**Independent test**: `PUT /v1/kyc/cases/{id}/personal-information` â†’ 200; `national_id_hash` is 64-char hex in DB; no `national_id_number` column. Same national ID as an approved case â†’ 409 `DUPLICATE_IDENTITY_DETECTED`.

- [X] T043 [US2] Implement `KycCaseService::capturePersonalInformation(string $caseId, string $actorId, array $data): void` â€” (1) verify ownership and status is `initiated` or `documents_submitted`; (2) hash `national_id_number` via `PiiMasker::hashNationalId()`; (3) run `KycDuplicateIdentityChecker` â€” throw `KycDuplicateIdentityException` if match; (4) upsert `kyc_personal_info`; (5) write `kyc.case.personal-information-captured` audit; all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T044 [US2] Add `capturePersonalInfo(Request $request, string $id): JsonResponse` to `KycCaseController` â€” validate request body per spec; call service; return 200 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T045 [US2] Register `PUT /v1/kyc/cases/{id}/personal-information` with `identity.auth` + `identity.require-permission:kyc.case.update` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T046 [P] [US2] Feature test `KycCapturePersonalInfoTest` â€” success 200; missing required fields 422; case in wrong status 422 `CASE_NOT_EDITABLE`; case not owned by actor 404 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycCapturePersonalInfoTest.php`
- [X] T047 [P] [US2] Feature test: DB assertion â€” `kyc.kyc_personal_info` row has `national_id_hash` (64-char hex); NO `national_id_number` column; `national_id_hash` not in `platform.audit_events` payload in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycCapturePersonalInfoTest.php`
- [X] T048 [P] [US16] Feature test `KycDuplicateIdentityTest` â€” approve case A; attempt personal info on case B with same national ID â†’ 409 `DUPLICATE_IDENTITY_DETECTED`; assert 0 `kyc_personal_info` records for case B; assert `kyc.duplicate-identity.detected` audit event in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycDuplicateIdentityTest.php`
- [X] T049 [P] [US16] Feature test: duplicate detection does NOT block when existing case is `rejected` or `expired` (only `approved` triggers 409); same national ID after rejection â†’ 200 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycDuplicateIdentityTest.php`

**Checkpoint**: PII never stored in DB as plaintext. Duplicate detection blocks on approved case; permits on rejected/expired.

---

## Phase 5: US-KYC-003 + US-KYC-006 + US-KYC-007 â€” Document Upload & Validation (P0)

**Goal**: Customer uploads an identity document; system validates MIME type (magic-byte), file size, and expiry date. Stateless pre-check endpoints exist for format and expiry validation with zero DB writes.

**Independent test**: Upload valid JPEG â†’ 201 `validation_status: valid`. Upload BMP â†’ 400. Oversize â†’ 400. Expired doc â†’ 422. Validate-format â†’ 200, `kyc_documents` count unchanged. Validate-expiry â†’ 200, `kyc_documents` count unchanged.

- [X] T050 [US3] Implement `KycDocumentService::upload(string $caseId, string $actorId, KycDocumentType $type, mixed $file, ?string $expiresOn): KycDocumentModel` â€” (1) verify ownership and editable status; (2) validate MIME via magic-byte against `kyc.accepted_mime_types` (TBD-safe: if TBD log `kyc.config.tbd_active` warning and allow); (3) validate file size against `kyc.max_document_size_bytes` (TBD-safe); (4) reject if `expires_on` is in the past; (5) store via `KycDocumentStorageContract`; (6) soft-delete existing active doc of same type for this case; (7) insert new doc record; (8) write `kyc.case.document-uploaded` audit; (9) transition case to `documents_submitted` if still `initiated` in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycDocumentService.php`
- [X] T051 [US6] Implement `KycDocumentService::validateFormat(mixed $file): array` â€” stateless; checks MIME and size; returns `['valid' => bool, 'error' => ?string]`; NO DB write in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycDocumentService.php`
- [X] T052 [US7] Implement `KycDocumentService::validateExpiry(?string $expiresOn): array` â€” stateless; checks date is in future; returns `['valid' => bool, 'error' => ?string]`; NO DB write in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycDocumentService.php`
- [X] T053 [US3] Create `KycDocumentController` with `upload(Request $request, string $id): JsonResponse` â€” accepts `multipart/form-data`; calls `KycDocumentService::upload()`; returns 201 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycDocumentController.php`
- [X] T054 [US6] Add `validateFormat(Request $request): JsonResponse` to `KycDocumentController` â€” calls `KycDocumentService::validateFormat()`; returns 200 with result; no auth required beyond `identity.auth` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycDocumentController.php`
- [X] T055 [US7] Add `validateExpiry(Request $request): JsonResponse` to `KycDocumentController` â€” calls `KycDocumentService::validateExpiry()`; returns 200 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycDocumentController.php`
- [X] T056 [US3] Register `POST /v1/kyc/cases/{id}/documents` with `identity.auth` + `identity.require-permission:kyc.case.update` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T057 [US6] Register `POST /v1/kyc/documents/validate-format` with `identity.auth` + `identity.require-permission:kyc.case.update` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T058 [US7] Register `POST /v1/kyc/documents/validate-expiry` with `identity.auth` + `identity.require-permission:kyc.case.update` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T059 [P] [US3] Feature test `KycDocumentUploadTest` â€” success 201 `validation_status: valid`; unsupported MIME 400 `INVALID_DOCUMENT_FORMAT`; oversize 400 `DOCUMENT_TOO_LARGE`; expired doc 422 `DOCUMENT_EXPIRED`; supersession: upload same type twice â†’ 1 active row, previous has `deleted_at` in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycDocumentUploadTest.php`
- [X] T060 [P] [US6] Feature test `KycDocumentFormatValidationTest` â€” valid JPEG â†’ `{valid: true}`; BMP â†’ `{valid: false}`; oversized â†’ `{valid: false}`; assert `kyc.kyc_documents` count unchanged after all calls in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycDocumentValidationTest.php`
- [X] T061 [P] [US7] Feature test: validate-expiry with past date â†’ `{valid: false, error: 'DOCUMENT_EXPIRED'}`; future date â†’ `{valid: true}`; null `expires_on` â†’ `{valid: true}`; malformed string (e.g. `"not-a-date"`) â†’ HTTP 400 field validation error (EC-07); assert no DB row created in any case in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycDocumentValidationTest.php`

**Checkpoint**: Documents upload with correct validation. Supersession soft-deletes old doc. Stateless checks create zero DB rows.

---

## Phase 6: US-KYC-008 â€” Submit KYC for Verification (P0)

**Goal**: Customer submits a complete case; system validates completeness, atomically transitions to `under_review`, and optionally enqueues auto-verify job.

**Independent test**: Complete case (personal info + identity doc) â†’ `POST /v1/kyc/cases/{id}/submit` â†’ 200 `status: under_review`. Incomplete â†’ 422 `CASE_INCOMPLETE` with `missing` list. Same `Idempotency-Key` replay â†’ 200 idempotent.

- [X] T062 [US4] Implement `KycCaseService::submitCase(string $caseId, string $actorId, string $idempotencyKey): KycCaseModel` â€” (1) verify ownership and status `initiated`/`documents_submitted`; (2) completeness check: personal info row exists AND at least 1 active identity doc with `validation_status = valid`; throw `KycCaseIncompleteException` with `$missing` list if not; (3) transition to `under_review`; set `submitted_at`; (4) write `kyc.case.submitted` audit (FR-E05-019 mandates audit, not domain event); (5) dispatch `PerformAutomatedVerificationJob` if `kyc.auto_verify_enabled = true`; all in single DB transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T063 [US4] Add `submit(Request $request, string $id): JsonResponse` to `KycCaseController` â€” validate `Idempotency-Key` header; calls `KycCaseService::submitCase()`; returns 200 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T064 [US4] Register `POST /v1/kyc/cases/{id}/submit` with `identity.auth` + `identity.require-permission:kyc.case.update` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T065 [P] [US4] Feature test `KycSubmitCaseTest` â€” success 200 `under_review`; `submitted_at` set; `CASE_INCOMPLETE` with missing personal info; `CASE_INCOMPLETE` with missing identity doc; `INVALID_CASE_STATUS` when already `under_review`; idempotent replay 200 same body in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycSubmitCaseTest.php`
- [X] T066 [P] [US4] Feature test: `kyc.auto_verify_enabled = false` â†’ no `PerformAutomatedVerificationJob` dispatched; `= true` â†’ job dispatched; assert `kyc.case.submitted` audit event in `platform.audit_events` (no outbox event expected â€” FR-E05-019 mandates audit only) in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycSubmitCaseTest.php`

**Checkpoint**: Submit transitions to `under_review`. Completeness check enforced. Idempotency confirmed.

---

## Phase 7: US-KYC-009 â€” Automated Identity Verification (P0)

**Goal**: `PerformAutomatedVerificationJob` calls the provider, hashes the response, records a `KycVerificationResult`, and auto-approves on `pass` or leaves for manual review.

**Independent test**: Submit with `kyc.auto_verify_enabled = true` â†’ job runs â†’ `KycVerificationResult` with `raw_response_hash` (64-char hex); no `raw_response` column; `NullKycVerificationProvider` returns `manual_review_required`; case stays `under_review`.

- [X] T067 [US5] Implement `KycVerificationService::performVerification(string $caseId): void` â€” (1) load case as `KycCaseModel`, re-verify still `under_review`; (2) call `KycVerificationProvider::verify($case)` passing the loaded model (matches typed interface from T011); (3) hash raw response: `hash_hmac('sha256', json_encode($outcome->rawResponse), config('app.key'))`; (4) insert `KycVerificationResult` with hash only; (5) on `pass`: call `KycCaseService::approveCase($caseId, actorId: null, method: 'automated')`; (6) on `fail`/`manual_review_required`: leave `under_review`, emit `kyc.provider.call` metric; all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycVerificationService.php`
- [X] T068 [US5] Create `PerformAutomatedVerificationJob implements ShouldQueue` â€” re-fetches case inside job body; calls `KycVerificationService::performVerification()`; `$tries` from `kyc.provider_max_retries`; exponential `backoff()`; `$timeout = 60` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Jobs/PerformAutomatedVerificationJob.php`
- [X] T069 [P] [US5] Feature test `KycAutomatedVerificationTest` â€” `NullKycVerificationProvider` returns `manual_review_required`; assert `KycVerificationResult` row created; `raw_response_hash` is 64-char hex; no `raw_response` column; case remains `under_review` in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycAutomatedVerificationTest.php`
- [X] T070 [P] [US5] Feature test: mock provider returning `pass` â†’ case status `approved`; `kyc.case.approved` audit; `Kyc.Case.Approved` outbox event; `verified_at` set in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycAutomatedVerificationTest.php`
- [X] T071 [P] [US5] Feature test: provider throws exception â†’ job retries; after exhausting `max_retries` â†’ case remains `under_review`; structured error log with `kyc.provider.error` in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycAutomatedVerificationTest.php`

**Checkpoint**: Verification pipeline runs. Hash-only storage confirmed. Auto-approve path emits domain event.

---

## Phase 8: US-KYC-010 + US-KYC-011 + US-KYC-012 + US-KYC-013 â€” Manual Review Workflow (P0)

**Goal**: Compliance Officers list `under_review` cases, view details, approve (emitting domain event), reject with reason, and request additional information.

**Independent test**: Compliance Officer `GET /v1/kyc/cases?status=under_review` â†’ paginated list. Approve â†’ 200 `approved` + outbox event. Customer token on list endpoint â†’ 403.

- [X] T072 [US6] Create `KycReviewController` with `index(Request $request): JsonResponse` â€” `kyc.case.review` permission; paginated; optional `status` and `user_id` query filters in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycReviewController.php`
- [X] T073 [US6] Add `show(Request $request, string $id): JsonResponse` to `KycReviewController` â€” Compliance Officer sees any case; Customer/Agent sees only own case (404 if not owner); response includes personal info, document list, verification results in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycReviewController.php`
- [X] T074 [US6] Implement `KycCaseService::approveCase(string $caseId, ?string $reviewerId, string $method = 'manual'): KycCaseModel` â€” verify `under_review`; set `approved`, `verified_at`, `expires_at` (from `kyc.validity_period_days`; null with `kyc.config.tbd_active` warning if TBD); set `reviewer_id`; write `kyc.case.approved` audit; publish `KycCaseApprovedEvent`; all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T075 [US6] Add `approve(Request $request, string $id): JsonResponse` to `KycReviewController` â€” requires `kyc.case.approve`; calls service; returns 200 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycReviewController.php`
- [X] T076 [US6] Implement `KycCaseService::rejectCase(string $caseId, string $reviewerId, string $reason): KycCaseModel` â€” validate `$reason` non-empty; verify `under_review`; set `rejected` + `rejection_reason`; write `kyc.case.rejected` audit; all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T077 [US6] Add `reject(Request $request, string $id): JsonResponse` to `KycReviewController` â€” requires `kyc.case.review`; body: `rejection_reason`; 400 `REJECTION_REASON_REQUIRED` if empty or absent in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycReviewController.php`
- [X] T078 [US6] Implement `KycCaseService::requestAdditionalInfo(string $caseId, string $reviewerId, string $note): KycCaseModel` â€” validate `$note` non-empty; verify `under_review`; set `needs_info` + `info_request_note`; write `kyc.case.needs-info` audit in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T079 [US6] Add `requestInfo(Request $request, string $id): JsonResponse` to `KycReviewController` â€” requires `kyc.case.review`; body: `info_request_note`; 400 `INFO_REQUEST_NOTE_REQUIRED` if empty in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycReviewController.php`
- [X] T080 [US6] Register review routes in `wallet-ecosystem/services/api/routes/modules/kyc.php`:
  - `GET /v1/kyc/cases` â€” `kyc.case.review`
  - `GET /v1/kyc/cases/{id}` â€” `kyc.case.read` (own) or `kyc.case.review` (any)
  - `POST /v1/kyc/cases/{id}/approve` â€” `kyc.case.approve`
  - `POST /v1/kyc/cases/{id}/reject` â€” `kyc.case.review`
  - `POST /v1/kyc/cases/{id}/request-info` â€” `kyc.case.review`
- [X] T081 [P] [US6] Feature test `KycReviewListTest` â€” Compliance Officer gets paginated list; filter `status=under_review` returns only those; Customer token â†’ 403; Admin without `kyc.case.review` â†’ 403 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycReviewListTest.php`
- [X] T082 [P] [US6] Feature test `KycApprovalTest` â€” approve success 200 `approved`; `verified_at` set; `kyc.case.approved` audit; `Kyc.Case.Approved` outbox event; wrong status 422 `INVALID_CASE_STATUS`; missing `kyc.case.approve` permission â†’ 403 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycApprovalTest.php`
- [X] T083 [P] [US6] Feature test `KycRejectionTest` â€” reject 200 `rejected`; `rejection_reason` stored and returned; empty reason 400 `REJECTION_REASON_REQUIRED`; wrong status 422 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycRejectionTest.php`
- [X] T084 [P] [US6] Feature test `KycRequestInfoTest` â€” success 200 `needs_info`; `info_request_note` stored; empty note 400 `INFO_REQUEST_NOTE_REQUIRED`; wrong status 422 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycRequestInfoTest.php`

**Checkpoint**: Manual review queue accessible. Approval emits `Kyc.Case.Approved`. Rejection stores reason. Permission enforcement verified on all 5 routes.

---

## Phase 9: US-KYC-014 + US-KYC-015 â€” Resubmission & Status Tracking (P0)

**Goal**: Customer resubmits a rejected/needs-info case, producing attempt 2, with previous documents soft-deleted. Customer can track their KYC status at any time.

**Independent test**: Reject case â†’ resubmit â†’ `attempt_number = 2`, `status = initiated`, all previous docs `deleted_at IS NOT NULL`. `GET /v1/kyc/cases/my` â†’ status + rejection reason visible.

- [X] T085 [US7] Implement `KycCaseService::resubmitCase(string $caseId, string $actorId, string $idempotencyKey): KycCaseModel` â€” verify ownership; verify status `rejected` or `needs_info`; increment `attempt_number`; soft-delete all active documents; clear `rejection_reason` and `info_request_note`; set `initiated`; write `kyc.case.resubmitted` audit (FR-E05-033 mandates audit only, not a domain event); all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T086 [US7] Add `resubmit(Request $request, string $id): JsonResponse` to `KycCaseController` â€” requires `Idempotency-Key`; requires `kyc.case.update` in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T087 [US7] Add `myStatus(Request $request): JsonResponse` to `KycCaseController` â€” resolves authenticated actor's own case; returns `status`, `attempt_number`, `rejection_reason`, `info_request_note`, `verified_at`, `expires_at`; 404 if no case exists; Compliance Officer may pass `?user_id=` with `kyc.case.review` to see any case in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T088 [US7] Register `POST /v1/kyc/cases/{id}/resubmit` with `identity.auth` + `identity.require-permission:kyc.case.update` and `GET /v1/kyc/cases/my` with `identity.auth` + `identity.require-permission:kyc.case.read` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T089 [P] [US7] Feature test `KycResubmitTest` â€” reject â†’ resubmit â†’ 200 `attempt_number = 2`, `status = initiated`; all docs soft-deleted; assert `kyc.case.resubmitted` audit event in `platform.audit_events` (no outbox event expected â€” FR-E05-033 mandates audit only); idempotent replay 200 same body; wrong status 422 in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycResubmitTest.php`
- [X] T090 [P] [US7] Feature test `KycStatusTrackingTest` â€” customer sees own case with `rejection_reason`; no case â†’ 404; customer cannot pass `?user_id=` of another user; Compliance Officer can pass `?user_id=` for any user in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycStatusTrackingTest.php`

**Checkpoint**: Full P0 KYC lifecycle complete â€” start â†’ submit â†’ review â†’ resubmit â†’ approve.

---

## Phase 10: US-KYC-004 + US-KYC-005 â€” Supporting Document & Selfie Upload (P1)

**Goal**: The existing document upload endpoint from Phase 5 already accepts all 5 document types. This phase extends business rules for `utility_bill` (no expiry required) and ensures `selfie` supersession works correctly.

**Independent test**: Upload `utility_bill` without `expires_on` â†’ 201. Upload `selfie` twice â†’ second replaces first (soft-delete). All via `POST /v1/kyc/cases/{id}/documents`.

- [X] T091 [P] [US8] Extend `KycDocumentService::upload()` to skip expiry validation when `document_type = utility_bill`; add guard: selfie max 1 active per case (same supersession logic as identity docs) in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycDocumentService.php`
- [X] T092 [P] [US8] Feature test `KycSupportingDocumentTest` â€” upload `utility_bill` without `expires_on` â†’ 201; upload `utility_bill` with past `expires_on` â†’ accepted (no expiry check); supersession for utility_bill type in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycSupportingDocumentTest.php`
- [X] T093 [P] [US8] Feature test `KycSelfieUploadTest` â€” upload `selfie` â†’ 201; upload second `selfie` â†’ 201, previous soft-deleted; oversized selfie â†’ 400; assert only 1 active selfie row after two uploads in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycSelfieUploadTest.php`

**Checkpoint**: All 5 document types (`national_id`, `passport`, `drivers_licence`, `utility_bill`, `selfie`) uploadable via single endpoint.

---

## Phase 11: US-KYC-017 â€” Detect Expired KYC (P1)

**Goal**: `php artisan kyc:detect-expired` batches through approved cases past `expires_at` and transitions them to `expired` with audit trail and domain event.

**Independent test**: Backdate `expires_at` â†’ run command â†’ `status = expired` + `kyc.case.expired` audit + `Kyc.Case.Expired` outbox event. Future `expires_at` unchanged. `--dry-run` makes zero DB changes.

- [X] T094 [US9] Implement `KycCaseService::detectAndExpireCases(int $batchSize, bool $dryRun = false): array` â€” batch query `status = approved AND expires_at < NOW()`; for each case (unless dry-run): set `expired`; write `kyc.case.expired` audit; publish `KycCaseExpiredEvent`; returns `[expired, skipped, errors]` summary; TBD guard: if `kyc.validity_period_days` is TBD â†’ log warning and return `[0, 0, 0]` in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T095 [US9] Create `DetectExpiredKycCommand` (`kyc:detect-expired`) â€” options `--batch-size=100` and `--dry-run`; calls `KycCaseService::detectAndExpireCases()`; outputs summary lines to stdout in `wallet-ecosystem/services/api/app/Modules/Kyc/Console/Commands/DetectExpiredKycCommand.php`
- [X] T096 [US9] Register `kyc:detect-expired` command and scheduler in `KycServiceProvider` â€” `$schedule->command('kyc:detect-expired')->dailyAt('02:00')` in `wallet-ecosystem/services/api/app/Modules/Kyc/Providers/KycServiceProvider.php`
- [X] T097 [P] [US9] Feature test `KycExpiryDetectionTest` â€” backdate `expires_at`; run command; assert `status = expired`; `kyc.case.expired` in `platform.audit_events`; `Kyc.Case.Expired` in `platform.outbox_events`; future `expires_at` cases unchanged; `--dry-run` makes zero DB changes in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycExpiryDetectionTest.php`

**Checkpoint**: Expiry detection works in batch mode. Dry-run safe. Domain event emitted per expired case.

---

## Phase 12: US-KYC-018 â€” Trigger KYC Reverification (P1)

**Goal**: Admin/Compliance Officer triggers reverification of an approved or expired case, resetting it to `initiated` for full resubmission.

**Independent test**: Approved case â†’ `POST /v1/kyc/cases/{id}/reverify` with `kyc.case.manage` â†’ 200 `initiated`, `attempt_number++`, documents cleared, `Kyc.Case.ReverificationTriggered` emitted. Customer token â†’ 403.

- [X] T098 [US10] Implement `KycCaseService::triggerReverification(string $caseId, string $actorId): KycCaseModel` â€” verify status `approved` or `expired`; increment `attempt_number`; soft-delete all active documents; set `initiated`; write `kyc.case.reverification-triggered` audit; publish `KycCaseReverificationTriggeredEvent`; all in transaction in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T099 [US10] Add `reverify(Request $request, string $id): JsonResponse` to `KycCaseController` â€” requires `kyc.case.manage`; calls service; returns 200 in `wallet-ecosystem/services/api/app/Modules/Kyc/Infrastructure/Http/Controllers/KycCaseController.php`
- [X] T100 [US10] Register `POST /v1/kyc/cases/{id}/reverify` with `identity.auth` + `identity.require-permission:kyc.case.manage` in `wallet-ecosystem/services/api/routes/modules/kyc.php`
- [X] T101 [P] [US10] Feature test `KycReverificationTest` â€” success 200 `initiated` with incremented `attempt_number`; documents soft-deleted; `Kyc.Case.ReverificationTriggered` outbox event; `kyc.case.reverification-triggered` audit; wrong status 422; Customer token 403 `AUTHORIZATION_001` in `wallet-ecosystem/services/api/tests/Feature/Modules/Kyc/KycReverificationTest.php`

**Checkpoint**: All 18 user stories implemented.

---

## Phase 13: Security, Integration & Concurrency Tests

**Purpose**: Cross-cutting quality gates â€” PII safety, cross-user isolation, full lifecycle integration, and race condition safety.

- [X] T102 [P] Security test `KycPiiStorageTest` â€” capture personal info with national ID â†’ DB assertion: `kyc.kyc_personal_info` has `national_id_hash` column (64-char hex); no `national_id_number` column in table schema; plaintext national ID absent from `platform.audit_events` payload in `wallet-ecosystem/services/api/tests/Security/Modules/Kyc/KycPiiStorageTest.php`
- [X] T103 [P] Security test `KycCrossUserAccessTest` â€” User A has approved case; User B calls `GET /v1/kyc/cases/{user_a_case_id}` â†’ HTTP 404 (not 403); User B calls `PUT /v1/kyc/cases/{user_a_case_id}/personal-information` â†’ HTTP 404; response body reveals nothing about User A in `wallet-ecosystem/services/api/tests/Security/Modules/Kyc/KycCrossUserAccessTest.php`
- [X] T104 [P] Security test `KycProviderResponseStorageTest` â€” run automated verification; `kyc.kyc_verification_results` has `raw_response_hash` (64-char hex); no `raw_response` column exists; provider response absent from `platform.audit_events` in `wallet-ecosystem/services/api/tests/Security/Modules/Kyc/KycProviderResponseStorageTest.php`
- [X] T105 [P] Security test `KycPermissionEnforcementTest` â€” CUSTOMER token on `GET /v1/kyc/cases` â†’ 403; CUSTOMER token on `POST /v1/kyc/cases/{id}/approve` â†’ 403; Admin without `kyc.case.approve` â†’ 403; all return `AUTHORIZATION_001` in `wallet-ecosystem/services/api/tests/Security/Modules/Kyc/KycPermissionEnforcementTest.php`
- [X] T106 [P] Security test `KycDuplicateDetectionDoesNotLeakTest` â€” 409 `DUPLICATE_IDENTITY_DETECTED` response body contains no reference to the blocking user's `user_id`, name, email, or case ID in `wallet-ecosystem/services/api/tests/Security/Modules/Kyc/KycDuplicateDetectionDoesNotLeakTest.php`
- [X] T107 Integration test `KycFullHappyPathTest` â€” start â†’ personal info â†’ upload identity doc â†’ submit â†’ manual approve â†’ `Kyc.Case.Approved` in `platform.outbox_events`; `kyc.case.approved` in `platform.audit_events`; `expires_at` null with `kyc.config.tbd_active` warning log in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycFullHappyPathTest.php`
- [X] T108 Integration test `KycRejectionResubmitTest` â€” start â†’ submit â†’ reject (reason stored) â†’ customer checks status (sees reason) â†’ resubmit (`attempt = 2`, docs cleared) â†’ upload new doc â†’ submit â†’ approve; assert `attempt_number = 2`; old docs all soft-deleted in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycRejectionResubmitTest.php`
- [X] T109 Integration test `KycDuplicateIdentityIntegrationTest` â€” approve case for User A; User B captures same national ID â†’ 409; verify `kyc.duplicate-identity.detected` in audit; verify 0 `kyc_personal_info` records for User B in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycDuplicateIdentityIntegrationTest.php`
- [X] T110 Integration test `KycNeedsInfoFlowTest` â€” submit â†’ request-info (`needs_info`) â†’ customer sees `info_request_note` in `GET /v1/kyc/cases/my` â†’ resubmit clears note â†’ re-submit â†’ approve in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycNeedsInfoFlowTest.php`
- [X] T111 Concurrency test `KycConcurrentStartTest` â€” 5 concurrent `POST /v1/kyc/cases` for same user â†’ exactly 1 case created; remaining 4 receive 409 `KYC_CASE_ALREADY_OPEN`; assert single row in `kyc.kyc_cases` in `wallet-ecosystem/services/api/tests/Concurrency/Modules/Kyc/KycConcurrentStartTest.php`
- [X] T112 Concurrency test `KycConcurrentApproveTest` â€” 2 concurrent `POST /v1/kyc/cases/{id}/approve` for same case â†’ exactly 1 succeeds (200); other receives 422 `INVALID_CASE_STATUS`; assert single `Kyc.Case.Approved` row in `platform.outbox_events` in `wallet-ecosystem/services/api/tests/Concurrency/Modules/Kyc/KycConcurrentApproveTest.php`

---

## Phase 14: Polish & Cross-Cutting Concerns

- [X] T113 [P] Verify metric counter emission â€” `kyc.case.initiated`, `kyc.case.approved`, `kyc.case.rejected`, `kyc.provider.call`, `kyc.duplicate_identity_detected` emitted in respective service methods; add `MetricsCollectorInterface` calls where missing in `wallet-ecosystem/services/api/app/Modules/Kyc/Application/Services/KycCaseService.php`
- [X] T114 [P] Integration test: run `php artisan migrate` twice; assert `identity.permissions` has exactly 6 KYC rows (no duplicates); `identity.role_permissions` has exactly 6 admin assignments in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycPermissionSeedIdempotencyTest.php`
- [X] T115 [P] Integration test: run `php artisan migrate` twice; assert `platform.configuration_values` has exactly 6 `kyc.*` rows; `is_tbd = true` on 4 entries; `kyc.auto_verify_enabled = false` in `wallet-ecosystem/services/api/tests/Integration/Modules/Kyc/KycConfigSeedIdempotencyTest.php`
- [X] T116 Verify all 7 quickstart.md scenarios against running service; document any deviations as findings in `specs/005-kyc-identity-verification/quickstart.md`
- [X] T117 Mark all completed tasks `[X]` in `specs/005-kyc-identity-verification/tasks.md`; update `spec.md` status from `Specified` to `IMPLEMENTATION READY` if all phases pass

---

## Dependencies & Execution Order

### Phase Dependencies

```
Phase 1 (Setup â€” Platform Extension & Domain Skeleton)
    â†“
Phase 2 (Foundational â€” Migrations, Models, Infrastructure)
    â†“  BLOCKS ALL STORY PHASES
â”Œâ”€â”€â”€â”´â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
Phase 3 (US-001 Start)  â”‚
    â†“                   â”‚
Phase 4 (US-002+016)    â”‚
    â†“                   â”‚
Phase 5 (US-003+006+007)â”‚
    â†“                   â”‚
Phase 6 (US-008 Submit) â”‚
    â†“                   â”‚
Phase 7 (US-009 Auto)   â”œâ”€ (Phases 7+8 parallel after Phase 6)
Phase 8 (US-010-013)    â”‚
    â†“                   â”‚
Phase 9 (US-014+015)    â”‚
    â†“                   â”‚
â”Œâ”€â”€â”€â”´â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”‚
Phase 10 (US-004+005)â”‚  â”‚  â† P1: parallel after Phase 9
Phase 11 (US-017)    â”‚  â”‚
Phase 12 (US-018)    â”‚  â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜  â”‚
    â†“                   â”‚
Phase 13 (Security + Integration + Concurrency)
    â†“
Phase 14 (Polish)
```

### Within Each Phase

- [P]-marked tasks are parallelizable with other [P] tasks in the same phase
- Non-[P] tasks run sequentially
- T015â€“T020 (migrations) must run in strict numeric order

### Critical Ordering Notes

- T001 (`hashNationalId`) must precede T043 (`capturePersonalInformation`)
- T010â€“T020 (migrations) must run before T021â€“T027 (models)
- T062 (`submitCase`) must precede T067 (`performVerification`) and T074 (`approveCase`)
- T028â€“T030 (domain events) must precede T067 and T074

---

## Parallel Execution Examples

### Phase 2 â€” after T015â€“T020 (migrations) complete:

```
T021 KycCaseModel
T022 KycPersonalInfoModel       â† all parallelizable
T023 KycDocumentModel
T024 KycVerificationResultModel
T028 KycCaseApprovedEvent
T029 KycCaseExpiredEvent
T030 KycCaseReverificationTriggeredEvent
```

### Phase 7+8 â€” run concurrently after Phase 6:

```
T067+T068 (PerformVerificationJob)    T072+T073 (KycReviewController list/show)
T069+T070+T071 (tests)                T074+T075+T076+T077+T078+T079 (services)
                                      T081+T082+T083+T084 (tests)
```

### Phase 13 â€” all security tests parallelizable:

```
T102 KycPiiStorageTest
T103 KycCrossUserAccessTest     â† all parallelizable
T104 KycProviderResponseStorageTest
T105 KycPermissionEnforcementTest
T106 KycDuplicateDetectionDoesNotLeakTest
```

---

## Implementation Strategy

### MVP First (P0 lifecycle â€” Phases 1â€“9)

1. Complete Phase 1: Domain skeleton + `PiiMasker::hashNationalId()`
2. Complete Phase 2: Migrations, models, infrastructure
3. **Validate**: `php artisan migrate --database=kyc` â€” 6 migrations clean
4. Complete Phase 3: `POST /v1/kyc/cases` end-to-end
5. **Validate**: Start returns 201; audit trail written
6. Complete Phase 4: Personal info + duplicate detection
7. **Validate**: PII hashed; 409 on duplicate national ID
8. Complete Phase 5: Document upload + stateless validation
9. Complete Phase 6: Submit (completeness check + idempotency)
10. Complete Phases 7+8 in parallel: Auto-verify + Manual review
11. Complete Phase 9: Resubmit + status tracking
12. **STOP** â€” Full P0 KYC lifecycle testable end-to-end

### P1 Stories (Phases 10â€“12) â€” independent, after Phase 9

Phases 10, 11, and 12 are independent of each other and can be implemented concurrently.

### Final gate (Phases 13â€“14)

Run after all stories pass their independent tests.
