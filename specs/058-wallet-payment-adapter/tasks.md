# Tasks: P09 — OleKard Wallet Payment Adapter

**Input**: Design documents from `specs/058-wallet-payment-adapter/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Format**: `[ID] [P?] [Story] Description with file path`
- `[P]` = parallelisable (different files, no dependencies on incomplete tasks)
- `[US1]–[US5]` = user story label (required for Phase 3+)

---

## Phase 1: Setup (Prerequisite Verification)

**Purpose**: Confirm P06/P07/P08 predecessor tasks are complete before any implementation begins.

- [x] T001 VERIFY predecessor completion — read `specs/055-payment-state-engine/tasks.md` (confirm T001–T041 ✓), `specs/056-durable-idempotency-recovery/tasks.md` (confirm T001–T027 ✓), and `specs/057-ledger-ack-fulfilment/tasks.md` (confirm T001–T025 ✓; T026–T028 Docker-gated is acceptable) to ensure no blocking gaps before proceeding

**Checkpoint**: All three predecessor task files confirm implementation-complete. Proceed.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: New exception type and test infrastructure fix that all user story phases depend on.

**⚠️ CRITICAL**: Both tasks must complete before any user story test or implementation task.

- [x] T002 [P] Create `app/Modules/Payment/Domain/Exceptions/TransactionLimitExceededException.php` — `final class TransactionLimitExceededException extends \DomainException`; constructor `(public readonly int $amountMinor, public readonly int $limitMinor, public readonly string $currencyCode)`; message format `"Payment amount {$amountMinor} {$currencyCode} exceeds per-transaction limit of {$limitMinor} {$currencyCode}"`; no other logic

- [x] T003 Fix constructor call in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` — add `use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;` import at top; add `/** @var MockObject&ActorStepUpVerifierContract */ private MockObject $stepUpVerifier;` property; in `setUp()`, add `$this->stepUpVerifier = $this->createMock(ActorStepUpVerifierContract::class);` and pass `stepUpVerifier: $this->stepUpVerifier` as the fifth named argument to `new WalletPaymentAdapter(...)` (fixes pre-existing `ArgumentCountError` on PHP 8.4)

**Checkpoint**: PHPStan reports no errors on the two new/modified files. Existing 8 unit tests still pass.

---

## Phase 3: User Story 1 — Complete Wallet Validation Gate (Priority: P1) 🎯 MVP

**Goal**: `initiatePayment()` validates `isActive()`, `pocketExists()`, and per-currency transaction limit before placing any hold. Each failure returns a specific typed exception with no wallet side-effects.

**Independent Test**: Run `./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` — all US1 tests (inactive wallet, missing pocket, over limit, null walletId, audit record) must PASS before moving to US2.

- [x] T004 [US1] Add `isActive()` gate to `app/Modules/Payment/Infrastructure/Adapters/WalletPaymentAdapter.php::initiatePayment()` — insert as second check (after `$walletId === null` guard, before ownership check): `if (!$this->wallet->isActive($walletId)) { throw new WalletNotTransactableException($walletId); }`; add `use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;` import at top of file

- [x] T005 [US1] Add `pocketExists()` gate to `app/Modules/Payment/Infrastructure/Adapters/WalletPaymentAdapter.php::initiatePayment()` — insert as fourth check (after ownership check `ownerActorId`, before `availableBalance` check): `if (!$this->wallet->pocketExists($walletId, $request->currencyCode)) { throw new UnsupportedCurrencyException($walletId, $request->currencyCode); }`; add `use App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException;` import at top of file

- [x] T006 [US1] Add transaction limit gate to `app/Modules/Payment/Infrastructure/Adapters/WalletPaymentAdapter.php::initiatePayment()` — insert as sixth check (after `availableBalance` optimistic check, before step-up check): `$limit = $this->config->getOrDefault('payment.wallet_transaction_limit_'.$request->currencyCode, null); if ($limit !== null && $request->amountMinor > (int) $limit) { throw new TransactionLimitExceededException($request->amountMinor, (int) $limit, $request->currencyCode); }`; add `use App\Modules\Payment\Domain\Exceptions\TransactionLimitExceededException;` import; do not add a default limit — null means no limit

- [x] T007 [US1] Write 6 unit tests in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` covering:
  1. `test_inactive_wallet_throws_wallet_not_transactable` — mock `isActive()` returns `false`; assert `WalletNotTransactableException` thrown, `createHold` never called
  2. `test_missing_pocket_throws_unsupported_currency` — mock `isActive()` true, `ownerActorId()` matches, `pocketExists()` returns `false`; assert `UnsupportedCurrencyException` thrown, `createHold` never called
  3. `test_amount_above_transaction_limit_throws_exception` — mock `isActive()` true, `ownerActorId()` matches, `pocketExists()` true, `availableBalance()` returns sufficient funds, `config->getOrDefault('payment.wallet_transaction_limit_BWP', null)` returns `5000`; attempt `amountMinor = 6000`; assert `TransactionLimitExceededException` thrown, `createHold` never called
  4. `test_no_limit_configured_allows_any_amount` — mock config `getOrDefault` returns `null`; with sufficient balance; assert hold placed successfully
  5. `test_null_wallet_id_throws_invalid_argument_exception` — `walletId: null`; assert `\InvalidArgumentException` thrown immediately
  6. `test_audit_record_emitted_on_successful_hold_placement` — mock all validations pass, `createHold` returns holdId; assert `AuditServiceInterface::record()` called once with `action = 'payment.wallet.hold_placed'`

**Checkpoint**: 6 new tests + 8 existing tests = 14+ unit tests pass. No `createHold` is called on any validation failure path.

---

## Phase 4: User Story 2 — Debit Idempotency and Concurrent Spend Safety (Priority: P1)

**Goal**: Same `paymentAttemptId` on retry returns the same hold without a second `createHold` call. Concurrent overspend is prevented by wallet-side SELECT FOR UPDATE.

**Independent Test**: Unit test for idempotency (no Docker). Feature test for concurrency (Docker required for real DB contention).

- [x] T008 [US2] Write unit test `test_idempotent_retry_does_not_call_create_hold_twice` in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` — use `$this->wallet->expects(self::once())->method('createHold')->willReturn($holdId)` (expects exactly ONE call); mock all other validations to pass; call `initiatePayment()` twice with identical `paymentAttemptId`; assert both calls return `PaymentAttemptResult` with the same `walletHoldId`; assert `createHold` was called exactly once (wallet module's idempotency guarantee means the second call returns the existing hold)

- [x] T009 [US2] Add feature test `test_concurrent_overspend_does_not_drain_balance_below_zero` to `tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php` — using `DB::connection('wallet')`, create a wallet + BWP pocket with `available_balance = 10000`; simulate two sequential hold insertions each for `amount = 8000` (combined 16,000 > 10,000); assert that the second insert fails with a balance constraint violation OR the SELECT FOR UPDATE prevents it; assert final `available_balance >= 0` (Docker-gated — add `$this->markTestSkipped('Docker required')` comment as the first line with instructions to remove when running in Docker)

**Checkpoint**: T008 (unit) passes without Docker. T009 documents the concurrency scenario; full validation is Docker-gated.

---

## Phase 5: User Story 3 — Crash-After-Hold Recovery (Priority: P1)

**Goal**: Retrying `initiatePayment()` with the same `paymentAttemptId` after a simulated crash returns the original hold without placing a second hold.

**Independent Test**: Unit test only — no implementation changes needed (wallet module's `createHold` idempotency provides automatic recovery per research Decision 4).

**Independent Test**: Run `./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php --filter test_crash_after_hold_recovery` — PASS, one `createHold` call.

- [x] T010 [US3] Write unit test `test_crash_after_hold_recovery_returns_same_hold_on_retry` in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` — configure mocks so all validations pass; use `$this->wallet->expects(self::once())->method('createHold')->willReturn($holdId)`; call `initiatePayment()` twice with the same `paymentAttemptId` (second call simulates retry after crash); assert both return `walletHoldId === $holdId`; assert `createHold` is invoked exactly once (confirms automatic crash recovery via wallet-side idempotency — no extra adapter code needed). Note: expired hold detection is explicitly out of P09 scope (see FR-016 and Out of Scope section).

**Checkpoint**: T010 passes. `createHold` count = 1 across both calls.

---

## Phase 6: User Story 4 — Wallet Refund Contract Completeness (Priority: P2)

**Goal**: `initiateRefund()` returns `Succeeded` without calling `releaseHold()`. Partial refunds accepted. Audit record emitted. `cancelPayment()` also never calls `releaseHold()` (orchestration releases the hold before calling cancel) and is idempotent.

**Independent Test**: Unit tests only — no implementation changes needed (existing `initiateRefund()` is contractually correct per research Decision 5).

**Independent Test**: Run `./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php --filter 'refund|cancel_payment'` — all 5 tests PASS.

- [x] T011 [US4] Write 5 unit tests for refund/cancel contract in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php`:
  1. `test_initiate_refund_does_not_call_release_hold` — use `$this->wallet->expects(self::never())->method('releaseHold')`; call `initiateRefund(...)` with a valid `PaymentRefundRequest`; assert `PaymentRefundStatus::Succeeded` returned and `releaseHold` never called
  2. `test_initiate_refund_returns_succeeded_for_partial_amount` — set `amountMinor = 3000` (partial); assert `PaymentRefundStatus::Succeeded` returned
  3. `test_initiate_refund_emits_audit_record` — assert `AuditServiceInterface::record()` called exactly once with `action = 'payment.wallet.refund_initiated'`
  4. `test_cancel_payment_does_not_call_release_hold` — use `$this->wallet->expects(self::never())->method('releaseHold')`; call `cancelPayment($paymentAttemptId)` (FR-011: orchestration already released the hold before calling the adapter); assert a cancel result with `cancelled: true` returned
  5. `test_cancel_payment_is_idempotent` — call `cancelPayment($paymentAttemptId)` twice with the same `providerReferenceId`; assert `cancelled: true` returned on both calls with no exception thrown (FR-013: cancelPayment must be idempotent)

**Checkpoint**: 5 new refund/cancel tests pass. `releaseHold` is never called during refund or cancel. `cancelPayment` is idempotent.

---

## Phase 7: User Story 5 — PIN and Step-Up Authentication Gate (Priority: P2)

**Goal**: Step-up threshold is configurable; token must be bound to actor + session; threshold change takes effect without restart.

**Independent Test**: Unit tests only — implementation already correct; tests verify threshold is read from config, not hard-coded.

**Independent Test**: Run `./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php --filter step_up` — all 4 tests PASS.

- [x] T012 [US5] Write 4 unit tests for configurable step-up in `tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php`:
  1. `test_payment_below_threshold_does_not_require_step_up` — configure `$this->config->method('getOrDefault')->with('auth.step_up_threshold', self::anything())->willReturn(10000)`; set `amountMinor = 9999`, no `stepUpToken`; mock all other validations pass; assert `Succeeded`
  2. `test_payment_at_threshold_without_token_throws_step_up_required` — `amountMinor = 10000`, no `stepUpToken`; assert `StepUpRequiredException` thrown
  3. `test_payment_above_threshold_with_valid_token_places_hold` — `amountMinor = 15000`, `stepUpToken = 'valid-token'`; mock `$this->stepUpVerifier->method('verifyStepUpForActor')->willReturn(true)`; assert `Succeeded` and hold placed
  4. `test_payment_above_threshold_with_wrong_actor_token_throws` — `amountMinor = 15000`, `stepUpToken = 'bad-token'`; mock `$this->stepUpVerifier->method('verifyStepUpForActor')->willReturn(false)`; assert `StepUpRequiredException` thrown, `createHold` never called

**Checkpoint**: 4 new step-up tests pass. No hard-coded threshold values in test assertions (read from config mock).

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T013 Run PHPStan level 8 on the Payment module — `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8` from `wallet-ecosystem/services/api/`; fix all errors; expected: 0 errors (Docker-gated — requires local Composer install or Docker)

- [ ] T014 Run full P09 test suite — `php artisan test tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php --stop-on-failure` from `wallet-ecosystem/services/api/`; confirm all unit tests pass without Docker; confirm feature tests pass with Docker (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 (predecessor verification); T002 ∥ T003 can run in parallel
- **Phase 3 (US1)**: Depends on Phase 2 (needs `TransactionLimitExceededException` from T002 and fixed test from T003)
- **Phase 4 (US2)**: Depends on Phase 3 (concurrency test builds on same test file context)
- **Phase 5 (US3)**: Depends on Phase 3 (crash recovery test uses same mock setup pattern)
- **Phase 6 (US4)**: Depends on Phase 3 (refund test uses T003 fix); independent of US2/US3
- **Phase 7 (US5)**: Depends on Phase 3 (step-up test uses T003 fix and T004–T006 ordering); independent of US2/US3/US4
- **Phase 8 (Polish)**: Depends on all user story phases

### User Story Dependencies

- **US1 (P1)**: Depends only on Foundation — no US dependencies
- **US2 (P1)**: Depends on US1 (shares same test file; idempotency test reuses mock setup)
- **US3 (P1)**: Depends on US1 (shares same test file; crash recovery test reuses mock setup)
- **US4 (P2)**: Depends on Foundation (T003 fix); independent of US1–US3 implementation
- **US5 (P2)**: Depends on Foundation (T003 fix); independent of US1–US4 implementation

### Within-Phase Parallelism

- Phase 2: T002 ∥ T003 (different files)
- Phase 3: T004 → T005 → T006 → T007 (sequential — same method in same file)
- Phase 4: T008 ∥ T009 (different test files; T009 can be written while T008 runs)
- Phase 5: T010 (single task)
- Phase 6: T011 (single task)
- Phase 7: T012 (single task)
- Phase 8: T013 → T014 (sequential)

---

## Parallel Execution Example: Phase 2

```text
Task T002: Create TransactionLimitExceededException.php  (new file)
Task T003: Fix WalletPaymentAdapterTest constructor       (existing file)
```

Both can run simultaneously since they touch different files.

---

## Implementation Strategy

### MVP First (US1 Only — minimum viable safety gate)

1. Complete Phase 1 (verification)
2. Complete Phase 2 (T002 + T003 in parallel)
3. Complete Phase 3 (US1): T004 → T005 → T006 → T007
4. **STOP and VALIDATE**: `./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php` — all 14+ tests pass
5. `isActive()`, `pocketExists()`, and transaction limit are now enforced — financial validation gate is complete

### Incremental Delivery

1. Phase 1 + Phase 2 → exception + test fix ready
2. Phase 3 (US1) → validation gate complete; tests pass
3. Phase 4 (US2) → idempotency proven; overspend documented
4. Phases 5–7 (US3/US4/US5) → remaining contracts and edge cases tested
5. Phase 8 (Polish) → PHPStan clean; full suite confirmed

---

## Notes

- No new database migrations — all storage owned by existing Wallet/Payment domain
- `WalletModuleContract` MUST NOT be modified (ADR-E48-002) — verified by reading existing interface
- T009 is Docker-gated; write with `markTestSkipped` guard so unit test pass run is unaffected
- T004–T006 are sequential (same method, same file); do not attempt in parallel
- T002 and T003 are fully parallel (different files, no shared dependencies)
- All test tasks improve existing test files — no new test files created
- PHPStan gate (T013) must run after all implementation tasks complete
