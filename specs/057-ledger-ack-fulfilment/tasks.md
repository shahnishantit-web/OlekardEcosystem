# Tasks: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Input**: Design documents from `specs/057-ledger-ack-fulfilment/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Format**: `[ID] [P?] [Story] Description with file path`
- `[P]` = parallelisable (different files, no dependencies on incomplete tasks)
- `[US1/US2/US3/US4]` = user story label (required for Phase 3+)

---

## Phase 1: Setup (Schema Changes)

**Purpose**: Apply the database migration that adds the two new columns required by all user stories.

- [x] T001 Create migration `app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000011_add_payment_intents_ledger_repair_at.php` — add `ledger_repair_at TIMESTAMP WITH TIME ZONE NULL` and `accounting_repair_attempts SMALLINT NOT NULL DEFAULT 0` to `payment.payment_intents` using `DB::connection('payment')`; match the existing migration style from `2026_10_21_000001_add_multi_state_to_payment_intents.php`; add `down()` that drops both columns

**Checkpoint**: Migration file exists; `php artisan migrate` applies it cleanly.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Prerequisite code additions that US1–US4 depend on. None changes behaviour yet.

- [x] T002 [P] Add `AccountingFailure = 'accounting_failure'` enum case to `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php` — insert after the existing `DuplicateCapture` case added in P07; no other changes to the file
- [x] T003 [P] Add `allocation_reference` field to `app/Modules/Payment/Domain/Events/PaymentConfirmedEvent.php` — add `private string $allocationReference` as the last constructor parameter; add `'allocation_reference' => $this->allocationReference` as the last entry in `toArray()`; update all existing call-sites in `PaymentOrchestrationService.php` to pass `allocationReference: $intentId` (value = `$intent->id`)
- [x] T004 [P] Add casts for the two new columns to `app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php` — add `'ledger_repair_at' => 'datetime'` and `'accounting_repair_attempts' => 'integer'` to the `$casts` array; add both column names to `$fillable`
- [x] T005 Add `findPendingAccounting(int $limit = 500): \Illuminate\Support\Collection` signature to `app/Modules/Payment/Domain/Contracts/PaymentIntentRepositoryInterface.php` — returns a collection of `PaymentIntent` domain objects with `accounting_status = pending_ledger`, ordered by `succeeded_at` ASC, limited to `$limit`; also add `findAllUnresolvedAccounting(?string $statusFilter, int $limit): \Illuminate\Support\Collection` for US4 (I1 fix)
- [x] T006 Implement `findPendingAccounting(int $limit = 500): \Illuminate\Support\Collection` in `app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentIntentRepository.php` — query `payment.payment_intents` where `accounting_status = 'pending_ledger'`, `status = 'succeeded'`, order by `succeeded_at` ASC, limit `$limit`; also implement `findAllUnresolvedAccounting()` (I1 fix)

**Checkpoint**: PHPStan reports no new errors. No runtime behaviour changed.

---

## Phase 3: User Story 1 — Wallet Payment Accounting Acknowledgement (Priority: P1) 🎯 MVP

**Goal**: Fix `handleOutcome()` to post a balanced, idempotent, two-line journal for wallet payment captures, decoupled from the capture transaction.

**Independent Test**: Call `handleOutcome()` with a wallet payment; verify `LedgerModuleContract::entryExists('payment:{intentId}:ack')` returns true; verify `accounting_status = ledger_posted`; replay the same call; verify only one journal entry exists (spec SC-01, SC-03).

- [x] T007 [US1] In `app/Modules/Payment/Application/PaymentOrchestrationService.php::handleOutcome()`, remove the existing incorrect ledger block from inside the capture DB transaction; change `$intent->accounting_status = PaymentAccountingStatus::LedgerPosted` to `PaymentAccountingStatus::PendingLedger`; remove `$this->events->publish(new PaymentConfirmedEvent(...))` from inside the capture transaction; capture `$capturedIntent`/`$capturedAttempt` by reference for post-commit use

- [x] T008 [US1] Add private method `postLedgerAcknowledgement(PaymentIntentModel $intent, PaymentAttemptModel $attempt, string $correlationId): void` to `app/Modules/Payment/Application/PaymentOrchestrationService.php` — AD-09 guard (no wallet_hold_id → skip), AD-08 guard (no credit account config → throw), wallet ID resolution via `findPrimaryWalletId()`, balanced two-line journal via `postEntry()` with key `payment:{intentId}:ack`, atomically updates `accounting_status = LedgerPosted` and publishes `PaymentConfirmedEvent` inside platform transaction

- [x] T009 [US1] In `PaymentOrchestrationService::handleOutcome()`, call `$this->postLedgerAcknowledgement($capturedIntent, $capturedAttempt, $correlationId)` AFTER the capture transaction commits; wrap in try/catch — on exception: log warning (`payment.ledger_ack_deferred`), do NOT rethrow (capture stays succeeded, repair job picks up)

- [x] T010 [US1] Write feature tests in `tests/Feature/Modules/Payment/LedgerAcknowledgementTest.php` covering: (1) wallet payment posts balanced journal + publishes PaymentConfirmedEvent, (2) no wallet hold skips posting, (3) ledger outage defers without failing capture, (4) missing credit config defers, (5) idempotent replay no-op, (6) failed outcome → LedgerFailed + PaymentFailedEvent

**Checkpoint**: US1 tests pass; `accounting_status` is never `ledger_posted` before journal ID is confirmed; no unbalanced journals; `payment.confirmed` not published before journal write.

---

## Phase 4: User Story 2 — Capture-Accounting Decoupling (Priority: P1)

**Goal**: Automated repair job retries ledger acknowledgement for `pending_ledger` intents without recharging. Exhaustion transitions to `ledger_failed` + reconciliation exception.

**Independent Test**: Create intent in `pending_ledger` state; run `RepairPendingAccountingJob`; process dispatched `RepairSingleAccountingJob`; verify `accounting_status = ledger_posted`; verify `payment.confirmed` outbox event exists (spec SC-04).

- [x] T011 [US2] Add public `repairAccountingForIntent(string $intentId, string $correlationId): void` to `app/Modules/Payment/Application/PaymentOrchestrationService.php` — increments `accounting_repair_attempts`, transitions to `ledger_failed` + creates `AccountingFailure` reconciliation exception on max retry exhaustion; loads succeeded attempt and calls `postLedgerAcknowledgement()`

- [x] T012 [US2] Add `findSucceededForIntent(string $intentId): ?PaymentAttemptModel` to `app/Modules/Payment/Domain/Contracts/PaymentAttemptRepositoryInterface.php` and implement in `app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentAttemptRepository.php`

- [x] T013 [US2] Create `app/Modules/Payment/Application/Jobs/RepairSingleAccountingJob.php` — `ShouldQueue`; `tries = 1`; `uniqueId() = 'payment_repair_accounting:{intentId}'`; `handle()` calls `$orchestration->repairAccountingForIntent($intentId, $correlationId)`

- [x] T014 [US2] Create `app/Modules/Payment/Application/Jobs/RepairPendingAccountingJob.php` (scan job) — `ShouldQueue`; `uniqueId() = 'payment_repair_accounting_scan'`; `handle()`: calls `$repo->findPendingAccounting(500)`, dispatches `RepairSingleAccountingJob::dispatch($intent->id)` for each

- [x] T015 [US2] Schedule `RepairPendingAccountingJob` in `app/Console/Kernel.php` — `->everyFiveMinutes()->withoutOverlapping()` immediately after `PaymentAttemptCrashRecoveryJob`

- [x] T016 [US2] Write unit tests in `tests/Unit/Modules/Payment/Jobs/RepairPendingAccountingJobTest.php` covering: (1) dispatches repair job per pending intent, (2) no jobs when empty, (3) unique ID prevents overlap, (4) limit=500 used, (5) RepairSingleAccountingJob unique ID uses intent ID

**Checkpoint**: US2 tests pass; repair job repairs `pending_ledger` intent in one cycle; exhaustion produces `ledger_failed` + reconciliation exception; `payment.confirmed` published by repair path.

---

## Phase 5: User Story 3 — Durable Payment.Confirmed Publication (Priority: P2)

**Goal**: Verify `payment.confirmed` is published only after journal ID is written, with `allocation_reference = payment_intent_id` in the payload.

**Independent Test**: Post wallet payment → check outbox event payload contains `allocation_reference = payment_intent_id`; publish same event twice → consumer uses `allocation_reference` as idempotency key (spec SC-05, SC-07).

- [x] T017 [US3] VERIFIED: `PaymentConfirmedEvent` is only published inside `postLedgerAcknowledgement()` platform transaction, AFTER `$this->ledger->postEntry()` returns; `allocationReference` defaults to `$paymentIntentId`; no publication exists inside the capture transaction after T007

- [x] T018 [US3] VERIFIED: Repair path calls `postLedgerAcknowledgement()` which has `PendingLedger` guard — if intent already `LedgerPosted`, returns early; no duplicate event publication possible

- [x] T019 [US3] Add US3 test to `tests/Feature/Modules/Payment/LedgerAcknowledgementTest.php` — test_confirmed_event_carries_intent_id_as_allocation_reference verifies `payload['allocation_reference'] === $intentId` and `!== $attemptId`

**Checkpoint**: US3 tests pass; no `payment.confirmed` event published before journal ID exists; `allocation_reference` always equals `payment_intent_id`.

---

## Phase 6: User Story 4 — Pending Accounting Visibility (Priority: P3)

**Goal**: Admin endpoint lists unresolved accounting intents; repair job emits metrics.

**Independent Test**: Create intent with `accounting_status = pending_ledger`; call `GET /v1/admin/payment/intents/pending-accounting` with admin token; verify intent appears in response with correct fields; verify 403 without `payment.intents.admin` permission (spec SC-06).

- [x] T020 [US4] Create `app/Modules/Payment/Http/Controllers/Admin/PendingAccountingController.php` — `index(Request $request): JsonResponse`; calls `findAllUnresolvedAccounting()` with optional `?accounting_status` filter; returns JSON with `data` (array of intent shapes with `seconds_since_capture`) + `meta`

- [x] T021 [US4] Register route in `routes/modules/payment.php` — `GET /admin/payment/intents/pending-accounting` with `identity.require-permission:payment.intents.admin` middleware

- [x] T022 [US4] Update `openapi/v1/payment.yaml` — add `PendingAccountingIntent` schema, add `GET /admin/payment/intents/pending-accounting` path, add `Admin — Pending Accounting` tag

- [x] T023 [US4] ABSORBED into T006 — `findAllUnresolvedAccounting(?string $statusFilter, int $limit)` already added to interface and repository as part of I1 fix

**Checkpoint**: US4 tests: admin endpoint returns 200 with correct shape; 403 without permission; repair job logs include `payment.accounting.pending_count`.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T024 [P] VERIFIED: `PaymentAccountingStatus::canTransitionTo()` allows `PendingLedger → LedgerPosted` and `PendingLedger → LedgerFailed`; both `LedgerPosted` and `LedgerFailed` are terminal; repair path guards on `!== PendingLedger` before proceeding — no changes needed
- [x] T025 [P] VERIFIED: Only one call-site of `new PaymentConfirmedEvent(...)` exists in `PaymentOrchestrationService.php::postLedgerAcknowledgement()`; `allocationReference` defaults to `$paymentIntentId` — correct
- [ ] T026 Run PHPStan level 8 on the Payment module — `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8` and fix all reported errors (Docker-gated)
- [ ] T027 Run P08 unit tests — `php artisan test tests/Feature/Modules/Payment/LedgerAcknowledgementTest.php tests/Unit/Modules/Payment/Jobs/RepairPendingAccountingJobTest.php --stop-on-failure` and confirm all pass (Docker-gated)
- [ ] T028 Execute quickstart.md SC-01 through SC-07 in Docker environment — `docker compose exec api php artisan migrate` then run each scenario from `specs/057-ledger-ack-fulfilment/quickstart.md`; record pass/fail per scenario (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundation)**: Depends on Phase 1 (migration must be applied before model casts work at runtime)
- **Phase 3 (US1)**: Depends on Phase 2 completion (needs `AccountingFailure` enum case, `allocation_reference` field, `findPendingAccounting()`)
- **Phase 4 (US2)**: Depends on Phase 3 (repair job calls `repairAccountingForIntent()` which calls `postLedgerAcknowledgement()`)
- **Phase 5 (US3)**: Depends on Phase 3 (verifies US1 implementation correctness for event publication)
- **Phase 6 (US4)**: Depends on Phase 2 (`findPendingAccounting()` must exist); can run in parallel with Phases 3–5
- **Phase 7 (Polish)**: Depends on all user story phases complete

### Within-Phase Parallelism

- Phase 2: T002, T003, T004 can run in parallel (different files); T005 before T006
- Phase 3: T007 → T008 → T009 → T010 (strictly sequential, all touch same file)
- Phase 4: T012 ∥ T013 after T011; T014, T015 after T013; T016 after T013+T015
- Phase 6: T020 → T021 (sequential); T022 ∥ T023 (different files)
- Phase 7: T024, T025 can run in parallel

### User Story Dependencies

- **US1 (P1)**: Depends only on Foundation — no US dependencies
- **US2 (P1)**: Depends on US1 (`repairAccountingForIntent()` must exist)
- **US3 (P2)**: Depends on US1 (verifies correct publication order from `postLedgerAcknowledgement()`)
- **US4 (P3)**: Depends only on Foundation (admin query); independent of US1–US3

---

## Parallel Execution Examples

### Phase 2 Parallel Launch

```text
Task T002: Add AccountingFailure to ReconciliationExceptionType.php
Task T003: Add allocation_reference to PaymentConfirmedEvent.php
Task T004: Add casts to PaymentIntentModel.php
```
Then sequentially: T005 → T006

### Phase 4 Parallel Launch (after T011)

```text
Task T012: Create RepairSingleAccountingJob.php
Task T013: Create RepairPendingAccountingJob.php
```
Then sequentially: T014, T015, T016

---

## Implementation Strategy

### MVP First (US1 + US2 Only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundation)
2. Complete Phase 3 (US1) — fix `handleOutcome()`
3. Complete Phase 4 (US2) — repair job
4. **STOP and VALIDATE**: Unit tests pass; accounting is decoupled from capture
5. US3 and US4 are enhancements that can follow independently

### Incremental Delivery

1. Phase 1 + Phase 2 → schema + prerequisites ready
2. Phase 3 (US1) → balanced journal, correct key, `pending_ledger` on guard/failure
3. Phase 4 (US2) → automated repair, exhaustion detection
4. Phase 5 (US3) → confirm publication ordering + `allocation_reference` in payload
5. Phase 6 (US4) → admin visibility + metrics
6. Phase 7 (Polish) → quality gates

---

## Notes

- `[P]` tasks touch different files with no cross-task dependencies
- Phases 3 and 4 heavily modify `PaymentOrchestrationService.php` — run sequentially
- T003 adds `allocationReference` parameter to `PaymentConfirmedEvent`; T025 (Polish) audits all call-sites
- T026–T028 are Docker-gated and should be run after all implementation tasks are complete
- Constitution gates that P08 corrects: unbalanced journal (Principle 4), wrong idempotency key (Principle 4), ledger inside capture transaction (Principle 4), `payment.confirmed` before journal (Principle 9)
- **Analyze HIGH findings fixed**: C1 (wallet_id resolution via `findPrimaryWalletId()`), I1 (dual-use split into `findPendingAccounting` + `findAllUnresolvedAccounting`)
