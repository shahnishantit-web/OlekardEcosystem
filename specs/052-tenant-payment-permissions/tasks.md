# Tasks: P03 — Tenant Isolation and Payment Permissions

**Input**: Design documents from `specs/052-tenant-payment-permissions/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Mode**: Implement — PHP seeder update, migrations, domain services, policy guards, new routes, OpenAPI

**Tests**: Included per spec SC-02 (negative tests mandatory FR-10), SC-04 (maker-checker round-trip), SC-05 (step-up round-trip), SC-06 (PHPStan).

---

## Phase 1: Setup (Baseline Verification)

**Purpose**: Verify the existing infrastructure P03 depends on before writing any new code.

- [X] T001 [P] Read `wallet-ecosystem/services/api/database/seeders/PaymentPermissionRoleSeeder.php` — confirm existing 9 permissions and 4 role assignments; record what must be added (5 permissions + agent role)
- [X] T002 [P] Read `wallet-ecosystem/services/api/app/Modules/Payment/Http/Middleware/PaymentOrganisationScopeMiddleware.php` — confirm it extracts `{intentId}` from route and checks `organisation_id`; verify refund routes (`/intents/{intentId}/refunds`) are already covered
- [X] T003 [P] Read `wallet-ecosystem/services/api/app/Modules/Identity/Providers/IdentityServiceProvider.php` lines 89–93 — confirm `ActorStepUpVerifierContract` is bound to `StepUpService`
- [X] T004 [P] Read `wallet-ecosystem/services/api/routes/modules/payment.php` — record all current route/middleware definitions; identify the 3 controllers with redundant `$this->authorize()` calls to remove

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Remove broken authorization pattern; establish correct enforcement baseline.

**⚠️ CRITICAL**: All US phases depend on clean permission enforcement. Complete Phase 2 before any user story work.

- [X] T005 Remove all `$this->authorize(...)` calls from `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/PaymentProviderAdminController.php` — route middleware is the only correct enforcement path (research.md Decision 1); do not remove or change any route or middleware
- [X] T006 Remove all `$this->authorize(...)` calls from `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/ReconciliationExceptionAdminController.php` — same rationale as T005
- [X] T007 Verify `wallet-ecosystem/services/api/routes/modules/payment.php` has `identity.require-permission:payment.providers.read` on the admin group — if not, add it; add `identity.require-permission:payment.config.draft` as an ADDITIONAL middleware on the `PUT /providers/{id}` route (so updating high-risk fields requires `payment.config.draft` in addition to being authenticated)

**Checkpoint**: Redundant authorization calls removed. Route-level permission enforcement is the single enforcement path.

---

## Phase 3: US1 — Seed Payment Permission Catalogue (Priority: P1) 🎯 MVP

**Goal**: 14 payment.* permissions in `identity.permissions`; agent role assigned payment permissions; all permission checks resolvable via `PermissionResolverService`.

**Independent Test (SC-01)**: After running updated seeder twice, `identity.permissions` has exactly 14 payment.* rows; agent role has 3 permissions assigned.

- [X] T008 [US1] Update `wallet-ecosystem/services/api/database/seeders/PaymentPermissionRoleSeeder.php` — add 5 permissions to the `$permissions` array: `payment.config.draft` (Submit high-risk config change for approval), `payment.payouts.view` (View payout records), `payment.payouts.manage` (Initiate payouts), `payment.callbacks.receive` (System callback path), `payment.exports.view` (Export payment data); update `$roleAssignments`: add `agent` role with `[payment.intents.read, payment.intents.manage, payment.refunds.request]`; add to `admin` role: `payment.config.draft`, `payment.payouts.view`, `payment.payouts.manage`, `payment.exports.view`; add to `admin-officer` role: `payment.exports.view` (callbacks.receive is system-only — NOT added to any user role)
- [X] T009 [P] [US1] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentPermissionCatalogueTest.php` — test that the seeder's permission array contains exactly 14 entries; test that `payment.callbacks.receive` is NOT in any role's permission list; test that the agent role assignment is present

**Checkpoint**: `PaymentPermissionRoleSeeder` runs idempotently and produces 14 permissions. Agent role is defined.

---

## Phase 4: US2 — Enforce Tenant/Org-Scope Isolation (Priority: P1)

**Goal**: All payment routes that reference a resource by ID enforce org-scope isolation. Callback endpoint rejects unknown provider codes.

**Independent Test (SC-02 negative)**: Cross-tenant refund attempt returns 403. Reconciliation list for org-scoped admin only returns that org's exceptions. Callback with unknown providerCode returns 404.

- [X] T010 [US2] Add org-scope enforcement to `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/ReconciliationExceptionAdminController.php` — in `index()`, after the `$this->authorize` removal (T006), read `$actorOrgId = $request->attributes->get('identity.organisation_id')` and if non-null, pass it as an additional WHERE filter to `$this->service->listExceptions(...)` (the service already accepts optional parameters)
- [X] T011 [US2] Update `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentCallbackController.php` — at the start of `store()` before any processing, resolve the `PaymentProviderRepositoryInterface` from the container; call `findByCode($providerCode)` (add this method to the interface if missing, see T012); if provider is null OR `is_enabled === false`, return `response()->json(['error' => 'PROVIDER_NOT_FOUND'], 404)` and log `payment.callback.unknown_provider` with hashed source IP
- [X] T012 [P] [US2] Add `findByCode(string $code): ?PaymentProviderModel` method to `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentProviderRepositoryInterface.php` and implement it in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentProviderRepository.php` — query `payment.payment_providers WHERE code = :code AND is_enabled = true`
- [X] T013 [P] [US2] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/CallbackProviderValidationTest.php` — test: unknown providerCode → 404; disabled provider → 404; active provider → proceed (mock the repository); test that no financial processing occurs on unknown provider
- [X] T014 [P] [US2] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/OrgScopeEnforcementTest.php` — test `PaymentOrganisationScopeMiddleware` returns 403 when `intent.organisation_id` ≠ `actor.organisationId`; test returns 200 when IDs match; test passes through when `intentId` is null in route

**Checkpoint**: Cross-tenant access denied at middleware. Callbacks reject unknown providers. Reconciliation scoped to actor's org.

---

## Phase 5: US3 — Maker-Checker for Payment Provider Configuration (Priority: P1)

**Goal**: High-risk provider config changes require a two-actor workflow. Drafter cannot approve their own draft. Draft lifecycle tracked in `payment_config_change_drafts`.

**Independent Test (SC-04)**: Full round-trip: Actor A submits draft (201) → Actor A attempts self-approval (403 SELF_APPROVAL_NOT_ALLOWED) → Actor B approves (200 approved). PHPStan clean on new files.

- [X] T015 [P] [US3] Create `ConfigChangeDraftStatus` PHP enum in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/ConfigChangeDraftStatus.php` — cases: `PendingApproval='pending_approval'`, `Approved='approved'`, `Rejected='rejected'`, `Withdrawn='withdrawn'`; methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`; transitions: `PendingApproval → Approved|Rejected|Withdrawn`; all others terminal
- [X] T016 [P] [US3] Create `SelfApprovalNotAllowedException` in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/SelfApprovalNotAllowedException.php` — extends `\RuntimeException`; message: 'The requester of a change cannot also approve it.'
- [X] T017 [US3] Create additive migration `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000004_create_payment_config_change_drafts.php` — `protected $connection = 'payment'`; `up()` creates `payment.payment_config_change_drafts` per data-model.md (11 columns: id UUID PK, provider_id FK, change_type varchar(64), change_payload jsonb, status varchar(32) DEFAULT 'pending_approval', drafted_by_actor_id UUID, reviewed_by_actor_id UUID nullable, reviewed_at timestamptz nullable, review_notes varchar(2000) nullable, idempotency_key varchar(255) UNIQUE, correlation_id UUID, timestamps); adds CHECK constraints for status, change_type, and self-approval (`reviewed_by_actor_id IS NULL OR reviewed_by_actor_id != drafted_by_actor_id`); adds 3 indices; `down()` drops table
- [X] T018 [P] [US3] Create `PaymentConfigChangeDraftRepositoryInterface` in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentConfigChangeDraftRepositoryInterface.php` — methods: `create(array $data): PaymentConfigChangeDraftModel`, `findById(string $id): ?PaymentConfigChangeDraftModel`, `findByIdempotencyKey(string $key): ?PaymentConfigChangeDraftModel`, `findPendingByProviderId(string $providerId): Collection`, `updateStatus(string $id, ConfigChangeDraftStatus $status, ?string $reviewerActorId, ?string $notes): PaymentConfigChangeDraftModel` (requires T015, T017)
- [X] T019 [US3] Create `PaymentConfigChangeDraftModel` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentConfigChangeDraftModel.php` — `final class`, `$connection = 'payment'`, `$table = 'payment.payment_config_change_drafts'`, `$keyType = 'string'`, `$incrementing = false`; `$fillable` for all writable columns; `casts()`: `'status' => ConfigChangeDraftStatus::class`, `'change_payload' => 'array'`, datetime casts; `belongsTo(PaymentProviderModel::class, 'provider_id')` relation (requires T015, T017)
- [X] T020 [US3] Create `EloquentPaymentConfigChangeDraftRepository` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentConfigChangeDraftRepository.php` — implement `PaymentConfigChangeDraftRepositoryInterface`; all methods use `DB::connection('payment')` or Eloquent on `PaymentConfigChangeDraftModel` (requires T018, T019)
- [X] T021 [US3] Create `PaymentConfigDraftService` in `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentConfigDraftService.php` — constructor injects `PaymentConfigChangeDraftRepositoryInterface`, `PaymentProviderRepositoryInterface`, `AuditServiceInterface`; methods: `submitDraft(array $data, string $actorId, string $correlationId): PaymentConfigChangeDraftModel` (validates provider exists, creates draft, audits); `approveDraft(string $draftId, string $approverActorId, ?string $notes, string $correlationId): PaymentConfigChangeDraftModel` (loads draft, checks status == PendingApproval else throws `InvalidStateTransitionException`, checks approver ≠ drafter else throws `SelfApprovalNotAllowedException`, transitions to Approved, audits); `rejectDraft(string $draftId, string $approverActorId, string $reason, string $correlationId): PaymentConfigChangeDraftModel` (same guard, transitions to Rejected); `withdrawDraft(string $draftId, string $actorId, string $correlationId): PaymentConfigChangeDraftModel` (only drafter can withdraw) (requires T016, T018, T020)
- [X] T022 [US3] Create `PaymentConfigChangeDraftAdminController` in `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/PaymentConfigChangeDraftAdminController.php` — thin controller; methods: `store()` (requires `payment.config.draft` — enforced by route middleware, NOT `$this->authorize()`), `approve()`, `reject()` (both require `payment.config.approve_high_risk`); return 201 for store, 200 for approve/reject, 403 for SELF_APPROVAL_NOT_ALLOWED, 409 for DRAFT_NOT_PENDING; never return raw `change_payload` fields that contain credential references (requires T021)
- [X] T023 [US3] Update `wallet-ecosystem/services/api/routes/modules/payment.php` — add routes: `POST /api/v1/admin/payment/providers/{id}/config-changes` → `PaymentConfigChangeDraftAdminController::store` (middleware: `identity.require-permission:payment.config.draft`, `idempotency:require`); `POST /api/v1/admin/payment/providers/{id}/config-changes/{draftId}/approve` → `::approve` (middleware: `identity.require-permission:payment.config.approve_high_risk`); `POST /api/v1/admin/payment/providers/{id}/config-changes/{draftId}/reject` → `::reject` (same permission) (requires T022)
- [X] T024 [US3] Register `PaymentConfigChangeDraftRepositoryInterface` → `EloquentPaymentConfigChangeDraftRepository` binding and `PaymentConfigDraftService` singleton in `wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php` (requires T018, T020, T021)
- [X] T025 [P] [US3] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/ConfigChangeDraftStatusTest.php` — test `isTerminal()` for all 4 states; test valid/invalid transitions using `canTransitionTo()` (requires T015)
- [X] T026 [P] [US3] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentConfigDraftServiceTest.php` — test self-approval throws `SelfApprovalNotAllowedException`; test approval by different actor succeeds; test rejection by different actor succeeds; test withdrawal only by original drafter; test double-approval of already-approved draft throws `InvalidStateTransitionException` (requires T021; uses mocked repositories)

**Checkpoint**: Maker-checker round-trip works. Self-approval returns 403. PHPStan clean on new service/model files.

---

## Phase 6: US4 — Refund Approval Gate (Priority: P2)

**Goal**: Refunds above the approval threshold created in `pending_approval` state. Requester cannot approve their own refund. Step-up not yet wired (that's US5).

**Independent Test (SC-04)**: Refund above BWP 200 threshold → `pending_approval`; requester attempts self-approval → 403; second actor approves → `pending_provider`.

- [X] T027 [US4] Create additive migration `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000005_add_approval_columns_to_payment_refunds.php` — `protected $connection = 'payment'`; `up()` adds 6 nullable columns to `payment.payment_refunds`: `approval_required boolean NOT NULL DEFAULT false`, `approved_by_actor_id UUID nullable`, `approved_at timestamptz nullable`, `rejected_by_actor_id UUID nullable`, `rejected_at timestamptz nullable`, `rejection_reason varchar(1000) nullable`; adds CHECK `approved_by_actor_id IS NULL OR approved_by_actor_id != requested_by`; `down()` drops all 6 columns + CHECK with IF EXISTS guards
- [X] T028 [US4] Add `PendingApproval = 'pending_approval'` and `Rejected = 'rejected'` cases to `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentRefundStatus.php` — `PendingApproval` is NOT terminal (awaiting approval); `Rejected` IS terminal; update `isTerminal()` and `transitions()` methods: `PendingApproval → Pending (approved path) | Rejected`; ensure existing transitions are unchanged
- [X] T029 [US4] Add `approval_required`, `approved_by_actor_id`, `approved_at`, `rejected_by_actor_id`, `rejected_at`, `rejection_reason` to `$fillable` and appropriate `casts()` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentRefundModel.php` (requires T027, T028)
- [X] T030 [US4] Update `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php` — in `request()` method, after validating the intent and amount, read `payment.refund_approval_threshold` from `ConfigurationServiceInterface::getOrDefault('payment.refund_approval_threshold', '20000')` (BWP 200 = 20000 minor units); if `amount_minor > threshold`, set `$data['status'] = PaymentRefundStatus::PendingApproval` and `$data['approval_required'] = true`; if ≤ threshold, status remains `PaymentRefundStatus::Pending` as before (requires T028, T029)
- [X] T031 [US4] Create `RefundApprovalAdminController` in `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/RefundApprovalAdminController.php` — thin controller; constructor injects `RefundService` and `AuditServiceInterface` (FR-09: all auth state changes MUST be audited); methods: `index()` (list refunds filtered by status=pending_approval), `approve(string $id, Request $request)`, `reject(string $id, Request $request)`; approval path: load refund, check status == PendingApproval (else 409 REFUND_NOT_PENDING_APPROVAL), check approver ≠ refund.requested_by (else 403 SELF_APPROVAL_NOT_ALLOWED), delegate to `$this->refundService->approveRefund(...)`, then call `$this->audit->record(actor_id, 'payment.refund.approved', refund_id, ['outcome' => 'approved'])` after state change; rejection path: same guards, delegate to `$this->refundService->rejectRefund(...)`, audit with action `'payment.refund.rejected'` and reason; all state transitions go through `RefundService` (requires T029, T030)
- [X] T032 [US4] Update `wallet-ecosystem/services/api/routes/modules/payment.php` — add: `GET /api/v1/admin/payment/refunds` → `RefundApprovalAdminController::index` (middleware: `identity.require-permission:payment.refunds.approve`); `POST /api/v1/admin/payment/refunds/{id}/approve` → `::approve` (middleware: `identity.require-permission:payment.refunds.approve`, **`idempotency:require`** — constitution P4+P8 mandate idempotency on state-changing financial APIs); `POST /api/v1/admin/payment/refunds/{id}/reject` → `::reject` (middleware: `identity.require-permission:payment.refunds.approve`) (requires T031); also update T042 OpenAPI: add `Idempotency-Key` as a required header on the approve endpoint
- [X] T033 [P] [US4] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentRefundStatusTest.php` — test `PendingApproval` is not terminal; test `Rejected` is terminal; test `PendingApproval → pending` transition is valid (approval path); test `PendingApproval → Rejected` is valid; test no transition from `Rejected` (requires T028)
- [X] T034 [P] [US4] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundApprovalGateTest.php` — test: amount below threshold → status is NOT PendingApproval; amount above threshold → status IS PendingApproval AND approval_required = true; test self-approval attempt → 403 SELF_APPROVAL_NOT_ALLOWED; test approval by different actor → transitions to pending; test double-approve (already-approved refund) → 409 (requires T030, T031; uses mocked ConfigurationService + repositories)

**Checkpoint**: Refunds above threshold gated behind approval. Self-approval blocked. Two-actor round-trip works.

---

## Phase 7: US5 — Step-Up MFA Enforcement (Priority: P2)

**Goal**: Refund approvals and credential rotations above the step-up threshold require a valid step-up token in the current session.

**Independent Test (SC-05)**: High-value refund approval without step-up → 403 STEP_UP_REQUIRED. Same request with step-up token → 200.

- [X] T035 [P] [US5] Inject `ActorStepUpVerifierContract` into `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php` constructor — add import for `App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract`; add `verifyStepUp()` call in the approval path BEFORE processing: read threshold from `ConfigurationServiceInterface::getOrDefault('auth.step_up_threshold', '10000')` (**default must be '10000' — the constitution P5 GAP-027 approved seed; NOT '1000000' which would silently bypass step-up**); if `amount_minor > threshold` AND `!$this->stepUpVerifier->verify($actorId, $sessionId, $correlationId)`, throw `StepUpRequiredException` (requires T031)
- [X] T036 [P] [US5] Add `rotateCredentialWithStepUp(string $providerId, string $accountId, string $actorId, string $sessionId, string $correlationId): void` method to `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` — inject `ActorStepUpVerifierContract` into the service constructor (constitution P7: business logic belongs in services, not controllers); the method ALWAYS calls `$this->stepUpVerifier->verify($actorId, $sessionId, $correlationId)` regardless of amount (credential rotation requires step-up unconditionally); if not verified, throw `StepUpRequiredException`; then call `$this->rotateCredentialReference(...)`; update `PaymentProviderAdminController::rotateCredential()` to delegate entirely to this new service method and catch `StepUpRequiredException` → return 403 with `code: STEP_UP_REQUIRED` and `next_action: {type: wallet_step_up}` (requires T035 pattern)
- [X] T037 [US5] Update `wallet-ecosystem/services/api/routes/modules/payment.php` — add `identity.step-up-required` middleware alias (if not yet defined) OR add step-up check inline: the route for `POST /payment/callbacks/{providerCode}/accounts/{accountId}/rotate-credential` should document that step-up is required; verify the middleware alias `identity.require-step-up` exists in `IdentityServiceProvider::boot()` and add it to the credential rotation route; if the alias does not exist, document as BLOCKED and note in implementation
- [X] T038 [P] [US5] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/StepUpEnforcementTest.php` — test: refund approval below step-up threshold → no step-up required; above threshold without step-up token → `StepUpRequiredException` thrown; above threshold with valid step-up token → proceeds; credential rotation always requires step-up (requires T035, T036; mocked `ActorStepUpVerifierContract`)

**Checkpoint**: Step-up enforced on high-value refund approvals and credential rotations. STEP_UP_REQUIRED returned without valid step-up.

---

## Phase 8: US6 — Payout Permission Definitions and Sensitive Field Redaction (Priority: P3)

**Goal**: `payment.payouts.view` and `payment.payouts.manage` seeded and verifiable (P18 readiness). Provider responses confirmed to never contain raw credentials.

**Independent Test (SC-03)**: All 14 permissions resolvable. A GET /providers response contains no raw credential values. Actors without `payment.providers.read` receive 403.

- [X] T039 [P] [US6] Inspect `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` method `safeProviderShape()` — confirm it excludes all raw credential fields; if any credential value is included (e.g. api_key, secret, webhook_secret), remove it; only opaque references (reference IDs, display names, last-4 characters) should appear
- [X] T040 [P] [US6] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/SensitiveFieldRedactionTest.php` — test that `safeProviderShape()` with a provider model containing mock credential values returns a response with no field matching patterns `/api.key/i`, `/secret/i`, `/webhook_sign/i`; test that all required display fields (id, code, display_name, is_enabled, environment) are present (requires T039)
- [X] T041 [P] [US6] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PayoutPermissionSeedTest.php` — test that `PaymentPermissionRoleSeeder::$permissions` array contains `payment.payouts.view` and `payment.payouts.manage`; test that `payment.callbacks.receive` is NOT in any role's `$roleAssignments` array (requires T008)

**Checkpoint**: Payout permissions exist in the catalogue. Credential redaction confirmed. P18 can reference `payment.payouts.*` safely.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI documentation, PHPStan, negative feature test.

- [X] T042 Update `wallet-ecosystem/services/api/openapi/v1/payment.yaml` — add 5 new admin paths per contracts/permission-enforcement-contract.md: `POST /admin/payment/providers/{id}/config-changes` (x-required-permission: payment.config.draft), `POST /admin/payment/providers/{id}/config-changes/{draftId}/approve` (x-required-permission: payment.config.approve_high_risk), `POST /admin/payment/providers/{id}/config-changes/{draftId}/reject` (same), `GET /admin/payment/refunds` (x-required-permission: payment.refunds.approve), `POST /admin/payment/refunds/{id}/approve` (same), `POST /admin/payment/refunds/{id}/reject` (same); add `SELF_APPROVAL_NOT_ALLOWED`, `DRAFT_NOT_PENDING`, `REFUND_NOT_PENDING_APPROVAL`, `STEP_UP_REQUIRED` to the `ErrorCode` enum schema
- [X] T043 [P] Create feature test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/PaymentP03NegativeTest.php` — tests the 5 negative scenarios from FR-10 (Docker-gated): (1) ID substitution: cross-org refund attempt returns 403; (2) cross-tenant export/job: org-scoped admin cannot access another org's reconciliation exceptions; (3) revoked session: actor with revoked session cannot make payment operation (uses a test that revokes the session token mid-request); (4) self-approval: same actor submits and approves config draft → 403; (5) invalid callback: POST to `/payment/callbacks/nonexistent` returns 404
- [X] T044 Seed `payment.refund_approval_threshold` default value in `wallet-ecosystem/services/api/database/seeders/PlatformConfigurationSeeder.php` — add `payment.refund_approval_threshold = 20000` (BWP 200 in minor units) as a configurable platform value with description "Refund amount threshold above which a second-actor approval is required (minor units)"
- [ ] T045 [P] Run PHPStan on Payment module `./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress` from `wallet-ecosystem/services/api/` — MUST produce 0 errors; fix any type errors in new service/model/enum files (Docker-gated)
- [ ] T046 [P] Lint `wallet-ecosystem/services/api/openapi/v1/payment.yaml` with `npx @redocly/cli lint openapi/v1/payment.yaml` — MUST produce 0 errors (Docker-gated)
- [ ] T047 Run quickstart Scenario 3 (maker-checker self-approval) per `specs/052-tenant-payment-permissions/quickstart.md` — confirm SELF_APPROVAL_NOT_ALLOWED returned; record pass/fail (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — T001–T004 all parallelizable
- **Phase 2 (Foundational)**: Depends on Phase 1 — T005/T006/T007 sequential within controllers but independent across controllers
- **Phase 3 (US1)**: Depends on Phase 2 — T008 sequential; T009 depends on T008
- **Phase 4 (US2)**: Depends on Phase 2 — T010/T011/T012 independent; T013/T014 depend on T012/T011
- **Phase 5 (US3)**: Depends on Phase 2 — T015/T016 parallel; T017 independent; T018 requires T015; T019 requires T015; T020 requires T018/T019; T021 requires T016/T018/T020; T022 requires T021; T023 requires T022; T024 requires T018/T020/T021; T025 requires T015; T026 requires T021
- **Phase 6 (US4)**: Depends on Phase 2 (for route enforcement) — T027 independent; T028 independent; T029 requires T027/T028; T030 requires T029; T031 requires T030; T032 requires T031; T033 requires T028; T034 requires T030/T031
- **Phase 7 (US5)**: Depends on Phase 6 (US4 refund service) and Phase 5 (US3 credential rotation controller) — T035 requires T030/T031; T036 requires T035 pattern; T037 requires T035/T036; T038 requires T035/T036
- **Phase 8 (US6)**: Depends on Phase 3 (US1 seeder) — T039/T040/T041 independently testable; T041 requires T008
- **Phase 9 (Polish)**: Depends on Phases 3–8 all complete

### User Story Dependencies

- **US1 (P1)**: Independent after Phase 2
- **US2 (P1)**: Independent after Phase 2 — can run parallel with US1
- **US3 (P1)**: Independent after Phase 2 — can run parallel with US1/US2
- **US4 (P2)**: Independent after Phase 2 — can run parallel with US1/US2/US3
- **US5 (P2)**: Depends on US3 (credential rotation) and US4 (refund approval) for injection points
- **US6 (P3)**: Depends on US1 (seeder) for T041; T039/T040 are independent

### Parallel Opportunities

- T001, T002, T003, T004 (Setup): all independent
- T005, T006 (Foundational): different files — parallel
- T008 (US1 seeder) and T010/T011/T012 (US2): different files — parallel after Phase 2
- T015, T016, T017 (US3): different files — parallel
- T025, T026 (US3 tests): parallel after T015, T021
- T027, T028 (US4): different files — parallel
- T033, T034 (US4 tests): parallel after T028, T030
- T035, T036 (US5): different injection targets — parallel
- T039, T040, T041 (US6): independent
- T045, T046 (Polish lint/stan): different tools — parallel

---

## Parallel Example: US1 + US2 + US3 Simultaneously

```text
# After Phase 2 completes, launch in parallel:

Task A (US1 seeder): T008 PaymentPermissionRoleSeeder update

Task B (US2 org-scope): T010 ReconciliationExceptionAdminController org filter
                        T011 PaymentCallbackController provider validation
                        T012 PaymentProviderRepositoryInterface::findByCode

Task C (US3 enum+exception): T015 ConfigChangeDraftStatus enum
                              T016 SelfApprovalNotAllowedException
                              T017 Migration 000004

# After T015+T016+T017 complete:
Task D (US3 model+repo): T018 PaymentConfigChangeDraftRepositoryInterface
                          T019 PaymentConfigChangeDraftModel

# After T018+T019:
Task E (US3 repository impl): T020 EloquentPaymentConfigChangeDraftRepository
Task F (US4 migration): T027 Migration 000005 (can start independently)
Task G (US4 enum): T028 PaymentRefundStatus enum update (can start independently)
```

---

## Implementation Strategy

### MVP (US1 + US2 — Unblocks P04)

1. Complete Phase 1: Setup verification
2. Complete Phase 2: Remove redundant authorize calls
3. Complete US1 (T008–T009): 14 permissions seeded
4. Complete US2 (T010–T014): Org-scope and callback validation
5. **STOP and VALIDATE**: All 14 permissions in DB; cross-tenant refund returns 403; bad callback returns 404
6. P04 and P05 can now reference any `payment.*` permission safely

### Full Delivery

1. Complete Phases 1–2
2. Complete US1 + US2 in parallel (T008–T014)
3. Complete US3 (T015–T026) — maker-checker complete
4. Complete US4 (T027–T034) — refund approval gate
5. Complete US5 (T035–T038) — step-up enforcement
6. Complete US6 (T039–T041) — payout permissions + redaction
7. Complete Phase 9 (T042–T047) — OpenAPI, PHPStan, quickstart

---

## Notes

- [P] tasks = independent files, no sequential dependency
- [US1–US6] maps to spec.md user stories for traceability
- Docker-gated tasks (T043, T045, T046, T047) require running Docker environment; mark SKIP if Docker unavailable
- FR-B01 (role matrix) assumption: agent role gets 3 permissions. Override when FD01 available by updating seeder
- FR-B02 (step-up thresholds): T035 uses `auth.step_up_threshold`; no per-operation override until section 9 available
- T037 step-up middleware alias: if `identity.require-step-up` is not registered in `IdentityServiceProvider`, that task is BLOCKED and must be noted; implement step-up check inline in the controller as fallback
- `payment.callbacks.receive` is seeded (SC-01 count of 14) but must NOT appear in any role's permission assignment (system-only)
