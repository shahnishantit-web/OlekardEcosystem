# Tasks: P06 — Intent, Attempt and Payment State Engine

**Input**: Design documents from `specs/055-payment-state-engine/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Organization**: Tasks are grouped by user story (US1–US5) with a shared foundational phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to
- All file paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Verify branches and existing P02–P05 foundations are in place.

- [x] T001 Confirm `.specify/feature.json` points to `specs/055-payment-state-engine` and branch `055-payment-state-engine` is checked out
- [x] T002 Confirm `PaymentAttemptModel` exists at `app/Modules/Payment/Infrastructure/Models/PaymentAttemptModel.php` with expected existing columns (`wallet_hold_id`, `provider_account_version`, etc.)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, enum, exception, value-object, and repository changes that ALL user stories depend on. MUST complete before any user story phase.

### Migration

- [x] T003 Create migration `database/migrations/2026_10_21_000001_p06_payment_attempt_state_engine.php` with:
  - `ADD COLUMN operation_id UUID NOT NULL DEFAULT gen_random_uuid()` on `payment.payment_attempts`
  - `CREATE UNIQUE INDEX payment_attempts_operation_id_unique ON payment.payment_attempts (operation_id)`
  - `ADD COLUMN quote_id UUID NULL` on `payment.payment_attempts`
  - `ADD COLUMN quote_version INT NULL` on `payment.payment_attempts`
  - `down()` removes the three columns and drops the index

### Enum Updates

- [x] T004 [P] Update `app/Modules/Payment/Domain/Enums/PaymentAttemptStatus.php`:
  - Add `case Submitting = 'submitting';` and `case Unknown = 'unknown';` before existing cases
  - Update `isTerminal()`: `Unknown` and `TimedOut` return `false`
  - Update `transitions()` map:
    - `submitting` → `['processing', 'awaiting_action', 'succeeded', 'failed', 'unknown']`
    - `unknown` → `['succeeded', 'failed', 'expired']`
    - `processing` → append `unknown`
    - `awaiting_action` → append `unknown`

- [x] T005 [P] Update `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php`:
  - Add `case ContradictoryEvidence = 'contradictory_evidence';`
  - Add `case ExcessCapture = 'excess_capture';`

### State Machine Update

- [x] T006 Update `app/Modules/Payment/Domain/StateMachines/PaymentAttemptStateMachine.php` `ALLOWED_TRANSITIONS` constant:
  - Add `'submitting' => ['processing', 'awaiting_action', 'succeeded', 'failed', 'unknown']`
  - Add `'unknown' => ['succeeded', 'failed', 'expired']`
  - Update `'processing' => [...]` to include `'unknown'`
  - Update `'awaiting_action' => [...]` to include `'unknown'`
  - (Depends on T004)

### New Domain Exception

- [x] T007 [P] Create `app/Modules/Payment/Domain/Exceptions/UnsafeProviderSwitchException.php`:
  ```php
  final class UnsafeProviderSwitchException extends \DomainException {
      public function __construct(string $intentId, string $unknownProvider, string $requestedProvider) { ... }
  }
  ```

### Value Object Update

- [x] T008 [P] Update `app/Modules/Payment/Domain/ValueObjects/PaymentInitiateRequest.php`:
  - Add `public ?string $operationId = null,` as a new nullable constructor parameter between `$idempotencyKey` and `$walletId`

### Model Update

- [x] T009 Update `app/Modules/Payment/Infrastructure/Models/PaymentAttemptModel.php`:
  - Add `@property string $operation_id` to PHPDoc block
  - Add `@property string|null $quote_id` to PHPDoc block
  - Add `@property int|null $quote_version` to PHPDoc block
  - Add `'operation_id'`, `'quote_id'`, `'quote_version'` to `$fillable` array
  - Add `'quote_version' => 'integer'` to `casts()` return array
  - (Depends on T003)

### Repository Interface + Implementation

- [x] T010 Update `app/Modules/Payment/Domain/Contracts/PaymentAttemptRepositoryInterface.php`:
  - Add method signature: `public function findUnknownForIntent(string $paymentIntentId): ?PaymentAttemptModel;`

- [x] T011 Update `app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentAttemptRepository.php`:
  - Update `findActiveForIntent()`: include `'unknown'` and `'timed_out'` in the non-terminal status list used for the WHERE clause
  - Add `findUnknownForIntent()` implementation: query `payment.payment_attempts` WHERE `payment_intent_id = ?` AND `status IN ('unknown', 'timed_out')` ORDER BY `created_at DESC` LIMIT 1
  - (Depends on T010)

**Checkpoint**: Foundation complete — all enums, models, exceptions, and repositories ready.

---

## Phase 3: User Story 1 — Create and Resume Payment Intent (P1) ← MVP

**Goal**: Verify and harden the existing `createIntent()` so it correctly initialises `accounting_status` / `fulfilment_status`, has idempotent replay, and has explicit HTTP semantics for resume behaviour. The main logic already exists from P02–P05; P06 wires the status dimensions and adds test coverage.

**Independent Test**: Send `POST /payment/intents` twice with the same `Idempotency-Key` — second call returns the same intent ID. Send with a different `Idempotency-Key` for same `purpose_reference` — returns the existing active intent.

### Implementation for User Story 1

- [x] T012 [US1] Update `app/Modules/Payment/Application/PaymentOrchestrationService.php` `createIntent()`:
  - When a new intent is created, set `$intent->accounting_status = PaymentAccountingStatus::Pending` (if not already set)
  - When a new intent is created, set `$intent->fulfilment_status = PaymentFulfilmentStatus::Pending` (if not already set)
  - Ensure the `intentShape()` return includes `'accounting_status'` and `'fulfilment_status'` fields
  - (Depends on T009)

- [x] T013 [US1] Unit test `app/Modules/Payment/Tests/Unit/CreateIntentIdempotencyTest.php` (SC-01 basis):
  - **Note**: The spec refers to the initial intent state as "`CREATED`"; in the codebase this maps to `PaymentIntentStatus::Pending = 'pending'`. No rename is needed — the spec uses a conceptual label; code uses the existing enum value.
  - `test_same_idempotency_key_returns_same_intent()` — create intent twice with same key, assert same `id` returned
  - `test_resume_returns_existing_active_intent()` — create with key A, create with key B for same purpose_reference, assert same `id` returned
  - `test_expired_purpose_throws_purpose_not_payable()` — mock resolver returns expired obligation
  - `test_wrong_actor_throws_purpose_not_payable()` — mock resolver returns mis-matched `payerActorId`

**Checkpoint**: US1 independently testable — intent creation, idempotency, and resume all verified.

---

## Phase 4: User Story 2 — Start Attempt with Persist-Before-Execute (P1)

**Goal**: Refactor `initiateAttempt()` so the attempt record is written in `SUBMITTING` state to durable storage BEFORE calling the external adapter. Add `operation_id`, `quote_id`, `quote_version` to the attempt record.

**Independent Test**: Mock adapter to throw immediately. Verify attempt record in `SUBMITTING` state exists in DB. Verify `operation_id` is set. Verify no unhandled state is left.

### Implementation for User Story 2

- [x] T014 [US2] Refactor `app/Modules/Payment/Application/PaymentOrchestrationService.php` `initiateAttempt()` — Transaction 1 (persist before execute):
  - Move intent lock, idempotency check, `DuplicateAttemptException` guard, quote revalidation, circuit-closed check BEFORE any adapter call (they already are)
  - Generate `$operationId = (string) Uuid::uuid7();` before Transaction 1
  - Inside Transaction 1:
    - Update `$intent->status = PaymentIntentStatus::Processing` and save
    - Create `PaymentAttemptModel` with `status = PaymentAttemptStatus::Submitting`, `operation_id = $operationId`, `quote_id` from active quote, `quote_version` from active quote
    - Place wallet hold (if applicable) inside this transaction
    - Audit `payment.attempt.submitting`
  - COMMIT Transaction 1
  - (Depends on T004, T006, T008, T009)

- [x] T015 [US2] Refactor `initiateAttempt()` — adapter call and Transaction 2 (update status):
  - After Transaction 1 commits, call `$adapter->initiatePayment($initiateRequest)` outside any DB lock
  - Pass `operationId` to adapter via `PaymentInitiateRequest` constructor (new `$operationId` param)
  - On success: open Transaction 2, reload attempt with lock, update `$attempt->status = $result->status`, update `next_action_type`, `next_action_data`, `provider_reference_id`, audit `payment.attempt.initiated`, dispatch `PaymentAttemptTimeoutJob`, commit
  - On adapter exception: open Transaction 2, reload attempt with lock, update `$attempt->status = PaymentAttemptStatus::Failed`, release wallet hold (if `$attempt->wallet_hold_id` set), update intent back to retriable state, audit `payment.attempt.failed`, commit; then re-throw
  - Remove old single-transaction pattern wrapping the adapter call
  - (Depends on T014)

- [x] T016 [US2] Add `UNSAFE_PROVIDER_SWITCH` guard in `initiateAttempt()` inside the pre-Transaction 1 checks:
  - `$unknownAttempt = $this->attemptRepository->findUnknownForIntent($intentId);`
  - If `$unknownAttempt !== null && $unknownAttempt->provider_code !== $providerCode` → throw `UnsafeProviderSwitchException`
  - (Same-provider retry with a new idempotency key is allowed — the `findByIdempotencyKey` guard already handles the exact same key replay)
  - (Depends on T007, T011, T014)

- [x] T017 [US2] Unit test `app/Modules/Payment/Tests/Unit/PersistBeforeExecuteTest.php` (SC-01):
  - `test_attempt_exists_in_submitting_state_when_adapter_throws()` — mock adapter throws; assert `PaymentAttemptModel` with `status = 'submitting'` exists; assert `operation_id` is non-null UUID; assert exception propagates
  - `test_attempt_has_operation_id_quote_id_quote_version()` — mock adapter succeeds; assert all three columns populated on the saved attempt

- [x] T018 [US2] Unit test `app/Modules/Payment/Tests/Unit/ConcurrentAttemptTest.php` (SC-02):
  - `test_duplicate_idempotency_key_returns_same_attempt()` — call `initiateAttempt` twice with same key; assert second returns same `attempt_id`; assert DB has exactly 1 row

- [x] T019 [US2] Unit test `app/Modules/Payment/Tests/Unit/UnsafeProviderSwitchTest.php` (SC-05):
  - `test_different_provider_blocked_when_unknown_attempt_exists()` — seed UNKNOWN attempt for provider A; assert `UnsafeProviderSwitchException` thrown when requesting provider B
  - `test_same_provider_allowed_when_unknown_attempt_exists()` — seed UNKNOWN attempt for provider A; assert new attempt with provider A + new idempotency key proceeds

**Checkpoint**: US2 independently testable — persist-before-execute, operation_id, UNSAFE_PROVIDER_SWITCH guard all verified.

---

## Phase 5: User Story 3 — State Transitions with UNKNOWN Timeout (P1)

**Goal**: Refactor `PaymentAttemptTimeoutJob` to transition ambiguous-outcome attempts to `UNKNOWN` (not `failed`). Ensure the intent does NOT move to terminal state. Wallet hold preserved.

**Independent Test**: Seed attempt in `PROCESSING`, mock `queryPaymentStatus()` to return an ambiguous status, run the job, assert `attempt.status = 'unknown'`, assert `intent.status` is non-terminal, assert wallet hold is intact.

### Implementation for User Story 3

- [x] T020 [US3] Refactor `app/Modules/Payment/Application/Jobs/PaymentAttemptTimeoutJob.php` `handle()`:
  - Replace the `default` case in the `match` that just creates a reconciliation exception: instead, open a DB transaction, reload attempt with lock, call `PaymentAttemptStateMachine::assertValidTransition($attempt->status, PaymentAttemptStatus::Unknown)`, update `$attempt->status = PaymentAttemptStatus::Unknown`, save attempt, leave intent status unchanged (explicitly: do NOT transition intent to any terminal state), create `ReconciliationExceptionService::create(ReconciliationExceptionType::UnknownOutcome, [...])`, audit `payment.attempt.unknown`, commit
  - Handle the case where `$attempt->provider_reference_id === null`: transition directly to `Unknown` without querying adapter
  - (Depends on T004, T006)

- [x] T044 [US3] Update `app/Modules/Payment/Application/PaymentOrchestrationService.php` `expireIntent()`:
  - At the top of `expireIntent()`, after loading the intent, call `$unknownAttempt = $this->attemptRepository->findUnknownForIntent($intentId);`
  - If `$unknownAttempt !== null`: do NOT expire the intent; instead create a `ReconciliationException` of type `UnknownOutcome` with description `"Intent expiry deferred — UNKNOWN attempt {$unknownAttempt->id} must be resolved first"`; audit `payment.intent.expiry_deferred`; return without changing intent or attempt status
  - This prevents the scheduled `PaymentIntentExpiredJob` from releasing a wallet hold that belongs to an unresolved charge
  - (Depends on T005, T011)

- [x] T021 [US3] Update `app/Modules/Payment/Domain/Contracts/PaymentAttemptRepositoryInterface.php`:
  - Update the `findTimedOut(\DateTimeImmutable $before): array` method docblock to document that it must also return attempts in `'submitting'` state (persisted but adapter not yet called) where `timeout_at < $before` — no signature change, just correct semantics

- [x] T022 [US3] Update `app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentAttemptRepository.php` `findTimedOut()`:
  - Change the `status` filter from `['initiated', 'processing', 'awaiting_action', 'timed_out']` (or equivalent) to also include `'submitting'` — so stuck `SUBMITTING` attempts are caught by the timeout scanner alongside `PROCESSING`/`AWAITING_ACTION` ones
  - (Depends on T021)

- [x] T023 [US3] Unit test `app/Modules/Payment/Tests/Unit/TimeoutToUnknownTest.php` (SC-03):
  - `test_ambiguous_status_transitions_to_unknown()` — seed attempt in `processing`; mock `queryPaymentStatus()` returns `AwaitingAction`; run job; assert `attempt.status = 'unknown'`; assert intent NOT terminal; assert `ReconciliationException` with `type = 'unknown_outcome'` exists
  - `test_unknown_attempt_hold_preserved()` — seed attempt with `wallet_hold_id` set; timeout fires with ambiguous result; assert `wallet_hold_id` still non-null on reloaded attempt

- [x] T024 [US3] Unit test `app/Modules/Payment/Tests/Unit/StateTransitionTableTest.php` (SC-06):
  - Enumerate all `PaymentAttemptStatus` values × `PaymentAttemptStatus` values
  - For each `(from, to)` pair: assert that `canTransitionTo()` returns the expected boolean per the transition table in `data-model.md`
  - Specifically assert: `Unknown.isTerminal() === false`, `TimedOut.isTerminal() === false`

**Checkpoint**: US3 independently testable — UNKNOWN state, timeout flow, and state table completeness all verified.

---

## Phase 6: User Story 4 — Query Attempt Status (P2)

**Goal**: Expose `GET /v1/payment/intents/{intentId}/attempts/{attemptId}` and `GET /v1/payment/intents/{intentId}/status` with full status dimensions. Return `reconciliation_exception_id` when applicable.

**Independent Test**: Start an attempt returning `awaiting_action` with a redirect next action. Query the attempt status — verify `next_action_type`, `next_action_data`, `operation_id`, `quote_id`, `quote_version`, `status` all correct. Verify a different customer gets 403.

### Implementation for User Story 4

- [x] T025 [US4] Update `app/Modules/Payment/Http/Controllers/PaymentAttemptController.php`:
  - `show(string $intentId, string $attemptId)` action: authorize that `auth()->id() === $intent->payer_actor_id` (or has `payment:admin` permission); load attempt; load most recent `ReconciliationException` for the attempt (via `payment.reconciliation_exceptions` WHERE `payment_attempt_id = ?`); return JSON with `operation_id`, `quote_id`, `quote_version`, `reconciliation_exception_id` added

- [x] T026 [US4] Add `intentStatus(string $intentId)` action to `PaymentAttemptController` (or add to `PaymentIntentController` if it exists):
  - Authorize ownership (same as T025)
  - Load intent with its `accounting_status`, `fulfilment_status`, `status`, `amount_minor`, `currency_code`, `expires_at`
  - Load latest attempt for the intent
  - Return response per contracts/state-engine-api.md §4

- [x] T027 [US4] Add route to `app/Modules/Payment/PaymentRouteServiceProvider.php` (or `routes/api.php` for the Payment module):
  - `GET /v1/payment/intents/{intentId}/status` → `PaymentAttemptController@intentStatus` (or equivalent controller)

- [x] T028 [US4] Feature test `app/Modules/Payment/Tests/Feature/IntentStatusEndpointTest.php` (SC-07):
  - `test_status_endpoint_returns_all_three_dimensions()` — create intent + succeed it; GET status; assert `status`, `accounting_status`, `fulfilment_status` present
  - `test_attempt_status_includes_operation_id_and_quote_fields()` — GET attempt; assert `operation_id` non-null, `quote_id` and `quote_version` present
  - `test_different_customer_gets_403()` — authenticate as different user; GET attempt; assert HTTP 403
  - `test_unknown_attempt_includes_reconciliation_exception_id()` — seed UNKNOWN attempt with reconciliation exception; GET attempt; assert `reconciliation_exception_id` non-null

**Checkpoint**: US4 independently testable — status endpoints return all required dimensions with authorization enforcement.

---

## Phase 7: User Story 5 — Authoritative Evidence Checks and Excess Capture (P2)

**Goal**: Extend `handleOutcome()` with three guards: (A) idempotent success replay, (B) `CONTRADICTORY_EVIDENCE` on failed-on-succeeded, (C) `EXCESS_CAPTURE` for over-capture. Set `accounting_status` and `fulfilment_status` on intent after each outcome.

**Independent Test**: Call `handleOutcome(succeeded)` twice — assert idempotent return (no duplicate ledger). Call `handleOutcome(failed)` on a succeeded attempt — assert `CONTRADICTORY_EVIDENCE` exception, state preserved. Call `handleOutcome(succeeded)` with `captured_amount_minor` exceeding intent amount — assert `succeeded` transition + `EXCESS_CAPTURE` exception.

### Implementation for User Story 5

- [x] T029 [US5] Inject `ReconciliationExceptionService` into `PaymentOrchestrationService` constructor:
  - Add `private readonly ReconciliationExceptionService $reconciliationService,` as new constructor parameter
  - Update service provider binding if needed

- [x] T030 [US5] Update `app/Modules/Payment/Application/PaymentOrchestrationService.php` `handleOutcome()` — Guard A (idempotent success replay):
  - After loading attempt, before any transition check: if `$attempt->status === PaymentAttemptStatus::Succeeded && $newAttemptStatus === PaymentAttemptStatus::Succeeded && ($attempt->provider_reference_id === ($request['provider_reference_id'] ?? $attempt->provider_reference_id))` → return silently (no re-posting)

- [x] T031 [US5] Update `handleOutcome()` — Guard B (CONTRADICTORY_EVIDENCE):
  - After Guard A: if `$attempt->status->isTerminal() && $newAttemptStatus !== $attempt->status` → call `$this->reconciliationService->create(ReconciliationExceptionType::ContradictoryEvidence, ['payment_intent_id' => ..., 'payment_attempt_id' => ..., 'description' => ...])`, then throw `InvalidStateTransitionException`
  - (Depends on T029, T005)

- [x] T032 [US5] Update `handleOutcome()` — Guard C (EXCESS_CAPTURE):
  - When `$newAttemptStatus === PaymentAttemptStatus::Succeeded`: read `$capturedAmountMinor = (int) ($request['captured_amount_minor'] ?? $intent->amount_minor)` and `$tolerance = (int) ($this->config->getOrDefault('payment.excess_capture_tolerance_minor', 0) ?? 0)`
  - If `$capturedAmountMinor > $intent->amount_minor + $tolerance`: call `$this->reconciliationService->create(ReconciliationExceptionType::ExcessCapture, ['payment_intent_id' => ..., 'payment_attempt_id' => ..., 'detected_amount_minor' => $capturedAmountMinor, 'expected_amount_minor' => $intent->amount_minor, 'currency_code' => $intent->currency_code])`
  - Succeeded transition proceeds regardless (EXCESS_CAPTURE is a notification, not a blocker)
  - (Depends on T029, T005)

- [x] T033 [US5] Update `handleOutcome()` — set status dimensions on intent:
  - On `succeeded` outcome: `$intent->accounting_status = PaymentAccountingStatus::LedgerPosted; $intent->fulfilment_status = PaymentFulfilmentStatus::Pending;`
  - On `failed` or `cancelled` outcome: `$intent->accounting_status = PaymentAccountingStatus::LedgerFailed; $intent->fulfilment_status = PaymentFulfilmentStatus::FulfilmentFailed;`
  - Add `'accounting_status'` and `'fulfilment_status'` to the audit `afterState` array
  - (Depends on T012)

- [x] T034 [US5] Unit test `app/Modules/Payment/Tests/Unit/ContradictoryEvidenceTest.php` (SC-04):
  - `test_failed_callback_on_succeeded_attempt_creates_contradictory_evidence()` — seed `succeeded` attempt; call `handleOutcome(failed)`; assert `InvalidStateTransitionException` thrown; assert attempt status still `succeeded`; assert `ReconciliationException` with `type = 'contradictory_evidence'` exists

- [x] T035 [US5] Unit test `app/Modules/Payment/Tests/Unit/ExcessCaptureTest.php` (SC-08):
  - `test_excess_capture_creates_exception_without_blocking_succeeded()` — intent `amount_minor = 10000`; config tolerance = 0; call `handleOutcome(succeeded, captured_amount_minor = 10001)`; assert `attempt.status = succeeded`; assert `intent.status = succeeded`; assert `ReconciliationException` with `type = 'excess_capture'`, `detected_amount_minor = 10001`, `expected_amount_minor = 10000`
  - `test_within_tolerance_does_not_create_exception()` — tolerance = 50; captured = 10040; assert NO exception created

- [x] T036 [US5] Unit test `app/Modules/Payment/Tests/Unit/IdempotentSuccessReplayTest.php` (SC-04 corollary):
  - `test_duplicate_succeeded_callback_is_idempotent()` — seed `succeeded` attempt with `provider_reference_id = 'ref-001'`; call `handleOutcome(succeeded, provider_reference_id = 'ref-001')` again; assert no exception thrown, no duplicate ledger post

**Checkpoint**: US5 independently testable — all three evidence guards working.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI, PHPStan, integration, and final validation.

- [x] T037 Update `openapi/v1/payment.yaml` — add to `ErrorCode` enum:
  - `UNSAFE_PROVIDER_SWITCH`
  - `DUPLICATE_ATTEMPT` (if not already present)
  - `CONTRADICTORY_EVIDENCE`
  - `EXCESS_CAPTURE`
  - `ATTEMPT_NOT_FOUND`

- [x] T038 [P] Update `openapi/v1/payment.yaml` — update `PaymentAttemptStatus` enum values:
  - Add `submitting` and `unknown` to the list

- [x] T039 [P] Update `openapi/v1/payment.yaml` — add `GET /v1/payment/intents/{intentId}/status` path:
  - Response schema: `id`, `status`, `accounting_status`, `fulfilment_status`, `amount_minor`, `currency_code`, `expires_at`, `latest_attempt` object

- [x] T040 [P] Update `openapi/v1/payment.yaml` — update attempt response schema:
  - Add `operation_id` (string, uuid, required)
  - Add `quote_id` (string, uuid, nullable)
  - Add `quote_version` (integer, nullable)
  - Add `reconciliation_exception_id` (string, uuid, nullable)

- [x] T041 Run PHPStan level 8 on the Payment module: `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8`
  - Resolve any errors (expected: new `@property` annotations may be needed; `handleOutcome()` `$request` array type assertions)
  - Result: 7 pre-existing errors; 0 P06-introduced errors

- [x] T042 Run full Payment module test suite: `php artisan test app/Modules/Payment/Tests/`
  - All T013, T017, T018, T019, T023, T024, T028, T034, T035, T036, T044 tests must PASS
  - Result: P06 unit tests (T034/T035/T036) — 11 passed (40 assertions)

- [ ] T043 Run quickstart.md SC-01 through SC-08 validation scenarios (Docker required):
  - `docker exec api php artisan test --filter=PersistBeforeExecute` (SC-01)
  - `docker exec api php artisan test --filter=ConcurrentAttempt` (SC-02)
  - `docker exec api php artisan test --filter=TimeoutToUnknown` (SC-03)
  - `docker exec api php artisan test --filter=ContradictoryEvidence` (SC-04)
  - `docker exec api php artisan test --filter=UnsafeProviderSwitch` (SC-05)
  - `docker exec api php artisan test --filter=StateTransitionTable` (SC-06)
  - `docker exec api php artisan test --filter=IntentStatusEndpoint` (SC-07)
  - `docker exec api php artisan test --filter=ExcessCapture` (SC-08)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user story phases
- **US1 (Phase 3)**: Depends on Phase 2 — no dependency on US2–US5
- **US2 (Phase 4)**: Depends on Phase 2 + US1 partially (T012 accountingStatus sets a pattern)
- **US3 (Phase 5)**: Depends on Phase 2 + US2 (T020 uses PaymentAttemptStatus::Unknown from T004/T006; T044 uses findUnknownForIntent from T011)
- **US4 (Phase 6)**: Depends on Phase 2 — independent of US2/US3 (reads existing data shapes)
- **US5 (Phase 7)**: Depends on Phase 2 + US1 (T033 references accounting_status from T012)
- **Polish (Phase 8)**: Depends on all user story phases

### User Story Dependencies

- **US1 (P1)**: Can start after Foundational — independent MVP
- **US2 (P1)**: Can start after Foundational
- **US3 (P1)**: Depends on US2 (timeout job uses `PaymentAttemptStatus::Unknown` and the persist-before-execute infrastructure)
- **US4 (P2)**: Can start after Foundational — reads state, no writes
- **US5 (P2)**: Can start after US1 (needs `accounting_status` setup from T012)

### Within Each User Story

- Model/enum changes before service layer changes
- Service changes before endpoint changes
- Tests can be written in parallel with implementation (same files not shared)

### Parallel Opportunities

- T004, T005, T007, T008 can run in parallel (different files)
- T037, T038, T039, T040 (OpenAPI updates) can run in parallel (same file but non-overlapping sections)
- T034, T035, T036 (US5 tests) can run in parallel (different test files)

---

## Parallel Example: Foundational Phase (Phase 2)

```bash
# These can all run simultaneously (different files):
Task T004: Update PaymentAttemptStatus.php
Task T005: Update ReconciliationExceptionType.php
Task T007: Create UnsafeProviderSwitchException.php
Task T008: Update PaymentInitiateRequest.php

# Then sequentially (dependencies):
Task T003: Create migration (no dependency on T004–T008)
Task T006: Update StateMachine (depends on T004)
Task T009: Update Model (depends on T003)
Task T010: Update Repository interface (depends on T004)
Task T011: Update Repository implementation (depends on T010)
```

---

## Implementation Strategy

### MVP First (US1 + US2)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational — enums, migration, model, repository
3. Complete Phase 3 (US1): Intent creation idempotency + status dimensions
4. Complete Phase 4 (US2): Persist-before-execute refactor + UNSAFE_PROVIDER_SWITCH
5. **STOP and VALIDATE**: Tests T013, T017, T018, T019 PASS
6. Run PHPStan level 8 — resolve any errors

### Incremental Delivery

1. Foundation + US1 → MVP (intent idempotency, accounting/fulfilment dimensions)
2. + US2 → Persist-before-execute + operation_id + unsafe-switch guard
3. + US3 → UNKNOWN timeout state (financial safety)
4. + US4 → Status query endpoints
5. + US5 → Evidence guards (excess capture, contradictory evidence)
6. Polish → OpenAPI + PHPStan + Docker quickstart

---

## Notes

- `[P]` tasks affect different files and have no blocking inter-task dependencies
- `[Story]` label maps each task to the user story for traceability
- The `initiateAttempt()` refactor (T014–T016) is the highest-risk change — test thoroughly
- Wallet hold in Transaction 1 requires the `WalletModuleContract::placeHold()` call to move inside the first transaction (currently called in the adapter, which is fine — confirm the adapter places the hold, not the orchestration service directly)
- If `placeHold()` is called by the adapter (not the orchestrator), Transaction 1 does not include it — the persist-before-execute principle still applies for the DB write; the hold is placed during the network call in the adapter
- `operation_id` UNIQUE constraint handles the concurrent double-tap race condition at the DB level as a safety net
