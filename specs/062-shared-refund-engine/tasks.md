# Tasks: P15 — Shared Refund Engine and Original Method Return

**Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Date**: 2026-10-24

**Prerequisite phases complete**: P08, P09, P10, P11 ✓

**Format**: `[ID] [P?] [Story?] Description — file path`
- `[P]` = parallel-safe (different files, no dependency on concurrent tasks)
- `[US#]` = user story this task belongs to

---

## Phase 1: Setup (Migration)

**Purpose**: Extend the `payment_refunds` table with new columns, updated CHECK constraint, and indexes required by all subsequent phases.

- [x] T001 Create migration `extend_payment_refunds_p15` — add columns `provider_submitted_ref`, `submitted_at`, `pending_at`, `unknown_at`, `refund_accounting_status`, `refund_ledger_journal_id`, `query_attempts`; update CHECK constraint to include `submitted`, `pending`, `unknown`, `definitive_failure`; add partial index `payment_refunds_attempt_inflight_idx`; add unique index `payment_refunds_provider_submitted_ref_idx` — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_24_000001_extend_payment_refunds_p15.php`

**Checkpoint**: `php artisan migrate` runs without error; `payment_refunds` schema matches data-model.md.

---

## Phase 2: Foundational (Domain Contracts — Blocks All US Phases)

**Purpose**: Domain contracts, enums, and exceptions that every user story depends on. All tasks here must complete before any user story phase begins.

**⚠️ CRITICAL**: No user story implementation can begin until this phase is complete.

- [x] T002 Extend `PaymentRefundStatus` enum — add `Submitted`, `Pending`, `Unknown`, `DefinitiveFailure` cases; **retain `Requested` as the momentary initial state** (written first, then immediately transitioned to `PendingApproval` or `Processing` within the same DB transaction — per research.md Decision 5 and data-model.md; this overrides the Assumption in spec.md L288-289); update `canTransitionTo()` to enforce all valid transitions per data-model.md transition table — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentRefundStatus.php`
- [x] T003 [P] Create `RefundEligibilityHandlerInterface` — define `supportedPurposeType(): string` and `checkRefundEligibility(string $purposeReference, string $actorId, int $requestedAmountMinor, string $correlationId): bool`; include `@throws RefundEligibilityDeniedException` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/RefundEligibilityHandlerInterface.php`
- [x] T004 [P] Create `RefundEligibilityDeniedException` — extends `\RuntimeException`; carries `purposeType`, `purposeReference`, and human-readable `reason` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/RefundEligibilityDeniedException.php`
- [x] T005 [P] Create `RefundFailedEvent` — outbox integration event for `definitive_failure`; payload: `payment_intent_id`, `payment_refund_id`, `purpose_type`, `organisation_id`, `failed_amount_minor`, `currency_code`, `reason`, `is_definitive`, `occurred_at`; idempotency key `refund.failed:{refundId}`; implements `IntegrationEventInterface` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/RefundFailedEvent.php`
- [x] T006 Add `sumReservedRefundsForAttempt(string $attemptId): int` method signature to `PaymentRefundRepositoryInterface` — sums `amount_minor` for all non-terminal refund statuses for the attempt — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentRefundRepositoryInterface.php`
- [x] T007 Implement `sumReservedRefundsForAttempt()` in `EloquentPaymentRefundRepository` — use `SELECT FOR UPDATE SKIP LOCKED` on `payment_refunds` rows where `payment_attempt_id = ?` AND `status NOT IN ('confirmed','failed','cancelled','rejected','definitive_failure')`; return `0` if no rows — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentRefundRepository.php`
- [x] T008 Update `PaymentRefundModel` — add new columns to `$fillable` and `$casts` (`provider_submitted_ref`, `submitted_at`, `pending_at`, `unknown_at`, `refund_accounting_status`, `refund_ledger_journal_id`, `query_attempts`); cast timestamps as `datetime`; cast `query_attempts` as `int` — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentRefundModel.php`

**Checkpoint**: Foundational phase complete. All US phases may now begin.

---

## Phase 3: User Story 1 — Domain Eligibility Gate and Exact Refundable Balance (Priority: P1) 🎯 MVP

**Goal**: Refund requests include in-flight amounts in the cumulative balance check; domain modules can veto refund requests via a registered eligibility handler.

**Independent Test**: Submit two simultaneous 60% refund requests against a 100-unit payment; verify only one succeeds; verify ineligible-domain case returns `DOMAIN_ELIGIBILITY_REJECTED` before reservation.

- [x] T009 [P] [US1] Write `RefundInFlightReservationTest` — unit tests: (a) in-flight amounts included in limit, (b) concurrent requests sum to ≤ original, (c) processing/unknown rows counted, (d) confirmed-only rows counted by existing `sumConfirmedRefunds`; verify `sumReservedRefundsForAttempt()` returns correct total — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundInFlightReservationTest.php`
- [x] T010 [P] [US1] Write `RefundEligibilityGateTest` — unit tests: (a) eligible handler returns true → refund proceeds, (b) handler throws `RefundEligibilityDeniedException` → request rejected, no PaymentRefund created, (c) no handler registered → refund permitted by default, (d) handler times out → request fails with recoverable error, no PaymentRefund created — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundEligibilityGateTest.php`
- [x] T011 [US1] Extend `RefundService::request()` — (1) resolve `RefundEligibilityHandlerInterface` from service container by `payment.refund_eligibility.{purpose_type}`; call `checkRefundEligibility()` if registered; catch `RefundEligibilityDeniedException` and return rejection without creating a record; (2) replace `sumConfirmedRefundsForAttempt()` call with `sumReservedRefundsForAttempt()` for the balance limit check — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T012 [US1] Write `ConcurrentPartialRefundTest` feature test — uses two concurrent DB connections; submits 60% refund requests simultaneously; asserts one accepted, one rejected with `REFUND_LIMIT_EXCEEDED`; asserts DB total never exceeds original amount — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/ConcurrentPartialRefundTest.php`

**Checkpoint**: US1 independently testable. `sumReservedRefundsForAttempt()` live; eligibility gate wired.

---

## Phase 4: User Story 2 — Complete Refund State Machine (Priority: P1)

**Goal**: `PaymentRefundStatus` state machine extended to cover `Submitted`, `Pending`, `Unknown`, `DefinitiveFailure`; `canTransitionTo()` enforces all valid transitions; service methods for each new state.

**Independent Test**: Drive programmatic state transitions through all paths; verify invalid transitions are rejected; verify `Unknown` reservation is never released without explicit resolve.

- [x] T013 [P] [US2] Write `RefundStateTransitionTest` — unit tests for every valid and invalid transition in the transition table; verify `Unknown` amount stays in `sumReservedRefundsForAttempt()`; verify `DefinitiveFailure` releases reservation — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundStateTransitionTest.php`
- [x] T014 [P] [US2] Add `submit(string $refundId, string $providerSubmittedRef): PaymentRefundModel` to `RefundService` — transitions `Processing → Submitted`; persists `provider_submitted_ref` and `submitted_at`; audits transition; dispatches `QueryRefundStatusJob` with configurable delay (`payment.refund_query_interval_seconds`, default 300s) — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T015 [P] [US2] Add `markPending(string $refundId): PaymentRefundModel` to `RefundService` — transitions `Submitted → Pending`; persists `pending_at`; audits transition — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T016 [P] [US2] Add `markUnknown(string $refundId): PaymentRefundModel` to `RefundService` — transitions `Submitted|Pending → Unknown`; persists `unknown_at`; increments `query_attempts`; emits operator alert via `AuditServiceInterface`; does NOT release reservation — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T017 [US2] Add `definitivelyFail(string $refundId, string $reason): PaymentRefundModel` to `RefundService` — transitions `Submitted|Pending|Unknown → DefinitiveFailure`; releases reservation (no sum reduction needed — status moves to terminal); creates `ReconciliationException(DefinitiveRefundFailure)` via `ReconciliationModuleContract`; publishes `RefundFailedEvent` via `OutboxEventPublisher`; audits transition — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`

- [x] T045 [US2] Write `RefundAuditIntegrationTest` — integration test verifying `AuditServiceInterface::record()` is called with correct fields (`actor_id`, `refund_id`, `from_state`, `to_state`, `correlation_id`) for all 5 transition methods: `submit()`, `markPending()`, `markUnknown()`, `confirm()`, `definitivelyFail()`; assert audit record is persisted within 1 second of DB commit (SC-005) — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/RefundAuditIntegrationTest.php`

**Checkpoint**: US2 independently testable. All state machine transitions reachable and tested.

---

## Phase 5: User Story 3 — Wallet Refund with Compensating Ledger Entry (Priority: P1)

**Goal**: `RefundService::confirm()` posts a compensating journal via `LedgerModuleContract::postEntry()` for wallet refunds; idempotent replay does not double-post; accounting failures set `pending_ledger_refund` without rolling back `confirmed`.

**Independent Test**: Call `confirm()` for wallet refund; verify one journal entry; replay → still one entry; ledger throws → `confirmed` state preserved; `accounting_status = pending_ledger_refund`.

- [x] T018 [P] [US3] Write `RefundLedgerEntryTest` — unit tests: (a) wallet confirm posts compensating journal with correct debit/credit accounts and idempotency key `refund:{refundId}`, (b) replay returns existing record, no second journal, (c) ledger throws → state = `confirmed`, `accounting_status = pending_ledger_refund`, no rollback, (d) partial refund posts exact refund amount not original, (e) bank refund confirm → `pending_ledger_refund_fee_blocked`, no journal posted — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundLedgerEntryTest.php`
- [x] T019 [US3] Extend `RefundService::confirm()` — (1) if already `confirmed`, return existing record immediately (idempotency gate — no second journal, no second event); (2) branch on provider type: **wallet provider** — obtain credit account ref via `WalletModuleContract::getPocketLedgerAccountRef(walletId)` (spec.md Assumptions L283-284), obtain debit account ref from original payment journal reference (AD-05), call `LedgerModuleContract::postEntry(debit=orgAccountRef, credit=consumerPocketRef, amount=refundAmountMinor, idempotencyKey='refund:{refundId}')`, on success set `refund_accounting_status = ledger_refund_posted`, on `LedgerModuleContract` exception set `refund_accounting_status = pending_ledger_refund` without rolling back the `confirmed` state; **bank provider (provider_code ≠ 'wallet')** — skip `postEntry()` entirely, set `refund_accounting_status = pending_ledger_refund_fee_blocked`, emit structured `warning` log at `warning` level with `["decision" => "AD-16", "refund_id" => $refundId]` context — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T020 [US3] Create `RefundAccountingRepairJob` — queued job; scans `payment_refunds` where `refund_accounting_status = pending_ledger_refund` (NOT `pending_ledger_refund_fee_blocked`); retries `LedgerModuleContract::postEntry()` with `refund:{refundId}` idempotency key; on success updates `refund_accounting_status = ledger_refund_posted`; on failure reschedules; logs at `info` with refund ID — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/RefundAccountingRepairJob.php`
- [x] T021 [P] [US3] Extend `EloquentPaymentRefundRepository` — add `listFiltered(array $filters, int $page, int $perPage): LengthAwarePaginator` method supporting filters: `status`, `provider_code`, `organisation_id`, `from` (date), `to` (date) — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentRefundRepository.php`
- [x] T022 [P] [US3] Add `listFiltered()` signature to `PaymentRefundRepositoryInterface` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentRefundRepositoryInterface.php`

**Checkpoint**: US3 independently testable. Wallet refund confirms post one journal; accounting repair recovers failures.

---

## Phase 6: User Story 4 — Async Bank Refund via Callback (Priority: P2)

**Goal**: `QueryRefundStatusJob` polls provider for submitted/pending/unknown refunds; `BankRefundCallbackHandler` advances refund state on verified bank callback; duplicate callbacks are no-ops.

**Independent Test**: Enqueue mock callback for pending refund → advances to `confirmed`; same callback again → no-op; `QueryRefundStatusJob` with pending-no-change → reschedules.

- [x] T046 [US4] Extend `RefundsPayment` interface — add `queryRefundStatus(string $refundId, string $providerRef): RefundStatusResult` method signature — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/RefundsPayment.php`; create `RefundStatusResult` value object with: `status` (string enum: `pending` | `confirmed` | `definitive_failure`), `providerRef` (string, nullable), `rawProviderCode` (string, for logging) — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/RefundStatusResult.php`
- [x] T023 [P] [US4] Write `BankRefundCallbackHandlerTest` — unit/feature tests: (a) confirmed callback advances `pending → confirmed`, (b) declined callback advances `pending → definitive_failure`, (c) duplicate confirmed callback is a no-op, (d) callback for unknown refundId is logged and rejected — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/BankRefundCallbackHandlerTest.php`
- [x] T024 [US4] Create `QueryRefundStatusJob` — dispatched with `refundId`; resolves provider adapter via `PaymentProviderRegistryInterface`; calls `adapter->queryRefundStatus(refundId, providerRef)`; on definitive success → calls `RefundService::confirm()`; on definitive failure → calls `RefundService::definitivelyFail()`; on pending-no-change → reschedules via `dispatch()->delay()`; increments `query_attempts`; after max retries (config `payment.refund_max_query_attempts`, default 24) → calls `RefundService::markUnknown()`; log each attempt at `debug` level — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/QueryRefundStatusJob.php`
- [x] T025 [US4] Create `BankRefundCallbackHandler` — P11 inbox consumer for event type `bank.refund.callback`; matches refund by `provider_submitted_ref`; if refund terminal state → ack and return (idempotency); if confirmed → calls `RefundService::confirm()`; if declined → calls `RefundService::definitivelyFail()`; sets `refund_accounting_status = pending_ledger_refund_fee_blocked` for bank provider (AD-16); logs at `warning` level referencing AD-16 — `wallet-ecosystem/services/api/app/Modules/Payment/Application/EventHandlers/BankRefundCallbackHandler.php`
- [x] T026 [US4] Register `BankRefundCallbackHandler` in `PaymentServiceProvider` as inbox consumer bound to event type `bank.refund.callback` — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Providers/PaymentServiceProvider.php`

**Checkpoint**: US4 independently testable. Bank callbacks processed; duplicate delivery idempotent; polling job self-reschedules.

---

## Phase 7: User Story 5 — Unsupported Method and Fee Policy Exception Handling (Priority: P2)

**Goal**: Providers lacking refund capability produce `CapabilityUnsupportedException` + `ReconciliationException(UnsupportedRefundMethod)` with no wallet credit. AD-16 bank accounting explicitly blocked.

**Independent Test**: Provider with no `FullRefund`/`PartialRefund` capability → exception raised; no `PaymentRefund` in `confirmed`; no wallet credit.

- [x] T027 [P] [US5] Write `UnsupportedRefundMethodTest` — unit tests: (a) provider missing `FullRefund` capability → `CapabilityUnsupportedException`, `ReconciliationException` created, no confirmed record; (b) provider has `PartialRefund` only → partial refund proceeds; (c) wallet refund zero fee test (AD-03 confirmed); (d) bank refund → `pending_ledger_refund_fee_blocked` without journal — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/UnsupportedRefundMethodTest.php`
- [x] T028 [US5] Extend `RefundService::initiate()` — before calling adapter, check `adapter->supports(ProviderCapabilityEnum::FullRefund)` or `PartialRefund` using `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum`; if absent, throw `CapabilityUnsupportedException`; catch it in the caller and create `ReconciliationException(UnsupportedRefundMethod)` via reconciliation contract; do NOT create or update any `PaymentRefund` record; log at `error` level — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`

**Checkpoint**: US5 independently testable. Unsupported provider raises proper exception; no silent wallet credits.

---

## Phase 8: User Story 6 — Mobile Refund Status API (Priority: P2)

**Goal**: Authenticated consumer can GET their refund list for a payment intent. `funds_returned` is `false` for any non-`confirmed` state. Endpoint is read-only.

**Independent Test**: Create refund in each state; call endpoint; verify correct `status_label` and `funds_returned` per state; 403 for non-owner.

- [x] T029 [P] [US6] Write `CustomerRefundStatusControllerTest` feature test — tests: (a) consumer fetches own refunds → 200 with correct payload, (b) `confirmed` refund → `funds_returned = true`, (c) `pending`/`unknown` → `funds_returned = false`, `status_label` non-committal, (d) non-owner → 403, (e) multiple partial refunds → `total_confirmed_minor` and `remaining_refundable_minor` correct — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/CustomerRefundStatusControllerTest.php`
- [x] T030 [US6] Create `CustomerRefundListResource` — transforms `PaymentRefundModel` to `CustomerRefundListItem` shape; `status_label` map: `confirmed → "Funds returned"`, `pending → "Being processed"`, `unknown → "Under review"`, `definitive_failure → "Refund declined"`, other → "Processing"; `funds_returned = (status === 'confirmed')` — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Resources/CustomerRefundListResource.php`
- [x] T031 [US6] Create `CustomerRefundStatusController` — `GET /v1/payment/intents/{intentId}/refunds`; validates intent ownership via authenticated consumer claim; returns paginated `CustomerRefundListResource` collection + `RefundSummaryMeta` (`total_confirmed_minor`, `remaining_refundable_minor`, `has_pending_refunds`) — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Customer/CustomerRefundStatusController.php`
- [x] T032 [US6] Register customer refund route — `GET /v1/payment/intents/{intentId}/refunds` under `identity.auth` + `payment.refunds.view` middleware in `wallet-ecosystem/services/api/routes/modules/payment.php`

**Checkpoint**: US6 independently testable. Mobile consumer can read refund statuses.

---

## Phase 9: User Story 7 — Admin Refund Management (Priority: P2)

**Goal**: Admins with `payment.refunds.view` can filter/list/detail refunds. `payment.refunds.manage` triggers query-status job. No admin endpoint permits manual state mutation.

**Independent Test**: `unknown` refunds appear in filtered list; `query-status` returns 202 without state change; unauthorized → 403.

- [x] T033 [P] [US7] Write `AdminRefundListControllerTest` feature test — tests: (a) `?status=unknown` filter returns only unknown refunds, (b) date range filter, (c) `query-status` returns 202, dispatches job, (d) `payment.refunds.view` missing → 403 on list, (e) `payment.refunds.manage` missing → 403 on query-status, (f) detail endpoint returns `provider_submitted_ref`, `refund_ledger_journal_id`, `reconciliation_exception_id` — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/AdminRefundListControllerTest.php`
- [x] T034 [US7] Create `AdminRefundListController` with three actions: (1) `index()` — list with filters via `PaymentRefundRepositoryInterface::listFiltered()`; requires `payment.refunds.view`; (2) `show()` — single refund detail; requires `payment.refunds.view`; (3) `queryStatus()` — dispatches `QueryRefundStatusJob` immediately; requires `payment.refunds.manage`; returns 422 if refund in terminal state — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/AdminRefundListController.php`
- [x] T035 [US7] Create `AdminRefundListResource` and `AdminRefundDetailResource` — list item shape includes `reserved_since` (min of `submitted_at`/`pending_at`/`unknown_at`); detail adds approval, rejection, provider refs, journal ID, reconciliation exception ref, `query_attempts` — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Resources/AdminRefundListResource.php` and `AdminRefundDetailResource.php`
- [x] T036 [US7] Register admin refund routes — `GET /v1/admin/payment/refund-management`, `GET /v1/admin/payment/refund-management/{id}`, `POST /v1/admin/payment/refund-management/{id}/query-status` under `identity.auth` middleware in `wallet-ecosystem/services/api/routes/modules/payment.php`

**Checkpoint**: US7 independently testable. Admin can monitor unknown refunds and trigger status queries.

---

## Phase 10: User Story 8 — Observability, Idempotency Hardening, and Operator Runbook (Priority: P3)

**Goal**: `confirm()` idempotent at method entry; state transition metrics emitted; unknown-refund monitoring job; operator runbook documents UNKNOWN resolution, AD-16 resolution path, and force-close procedure.

**Independent Test**: Double `confirm()` call → one journal entry, one event; metric emitted once; PHPStan level 8 clean.

- [x] T037 [P] [US8] Write `RefundIdempotencyTest` — unit test: (a) `confirm()` called twice for same refund → `LedgerModuleContract::postEntry()` called once, `RefundConfirmedEvent` emitted once, second call returns same record; (b) `definitivelyFail()` called twice → `ReconciliationException` created once, reservation released once — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/RefundIdempotencyTest.php`
- [x] T038 [P] [US8] Add `payment.refund.state_transition` metric emission to `RefundService` — emit on each successful `DB::transaction()` commit containing a state change; tags: `from_state`, `to_state`, `provider_code`, `is_wallet` (bool) — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php`
- [x] T039 [P] [US8] Create `MonitorUnknownRefundsJob` — scheduled job; queries refunds in `unknown` state older than `payment.refund_unknown_alert_threshold_hours` (default 24); emits `payment.refund.unknown_count` metric with count; logs at `warning` level listing refund IDs and reserved amounts — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/MonitorUnknownRefundsJob.php`
- [x] T040 [P] [US8] Add `payment.refund.accounting_blocked_count` metric to `RefundAccountingRepairJob` — count of skipped `pending_ledger_refund_fee_blocked` rows; log at `info` level stating AD-16 resolution required — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/RefundAccountingRepairJob.php`
- [x] T041 [P] [US8] Write operator runbook — sections: (1) Investigate UNKNOWN refund (steps, required permissions, admin query-status trigger); (2) Resolve AD-16 blocked bank refund accounting (what to do once AD-16 is resolved, how to trigger repair job); (3) Force-close permanently ambiguous refund (audit requirements, what endpoint/command to use, idempotency check) — `docs/runbooks/refund-unknown-resolution.md`

**Checkpoint**: US8 independently verifiable. Idempotency hardened; metrics live; runbook written.

---

## Phase 11: Polish & Cross-Cutting Concerns

- [x] T042 [P] Update OpenAPI documentation with P15 endpoints — added `GET /payment/intents/{intentId}/refunds` (customer), `GET /admin/payment/refund-management`, `GET /admin/payment/refund-management/{id}`, `POST /admin/payment/refund-management/{id}/query-status`; added schemas `CustomerRefundListItem`, `RefundSummaryMeta`, `AdminRefundListItem`, `AdminRefundDetail`, `PaymentRefundStatus`, `PaginationMeta` to `openapi/v1/payment.yaml`
- [ ] T043 [Docker-gated] Run PHPStan level 8 on the Payment module and fix all errors — `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8` from `wallet-ecosystem/services/api/`
- [ ] T044 [Docker-gated] Run quickstart.md validation — execute scenarios SC-001 through SC-008 per `specs/062-shared-refund-engine/quickstart.md`; record pass/fail results

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Migration)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 (migration must run first)
- **Phases 3–10 (User Stories)**: ALL depend on Phase 2 completion; can then proceed in priority order
- **Phase 11 (Polish)**: Depends on all desired user story phases complete

### User Story Dependencies

| Story | Priority | Depends On | Can Parallelize With |
|-------|----------|-----------|----------------------|
| US1 (eligibility + reservation) | P1 | Phase 2 | — |
| US2 (state machine) | P1 | Phase 2 | US1 |
| US3 (ledger entry) | P1 | Phase 2, US2 (needs submit/confirm methods) | US1 |
| US4 (bank callback) | P2 | Phase 2, US2, T046 | US5, US6, US7 |
| US5 (unsupported method) | P2 | Phase 2 | US4, US6, US7 |
| US6 (mobile API) | P2 | Phase 2 | US4, US5, US7 |
| US7 (admin API) | P2 | Phase 2, US3 (needs listFiltered) | US4, US5, US6 |
| US8 (observability) | P3 | Phase 2, all P1 stories | — |

### Parallel Opportunities Within Phases

```bash
# Phase 2 — these four tasks are fully parallel:
T003 RefundEligibilityHandlerInterface
T004 RefundEligibilityDeniedException
T005 RefundFailedEvent
T006 PaymentRefundRepositoryInterface (signature only)

# Phase 3 — tests parallel with each other:
T009 RefundInFlightReservationTest
T010 RefundEligibilityGateTest

# Phase 4 — service method additions are parallel (different methods, same file):
T014 RefundService::submit()
T015 RefundService::markPending()
T016 RefundService::markUnknown()
```

---

## Implementation Strategy

### MVP First (US1 + US2 + US3 — P1 Stories Only)

1. Phase 1: Migration ✓
2. Phase 2: Foundational contracts ✓
3. Phase 3: US1 — In-flight reservation + eligibility gate
4. Phase 4: US2 — State machine extension
5. Phase 5: US3 — Wallet ledger compensating entry + idempotency
6. **STOP and VALIDATE**: SC-001, SC-002, SC-003 pass → financial integrity closed

### Full Delivery (All Stories)

7. Phase 6: US4 — Async bank callback + polling job
8. Phase 7: US5 — Unsupported method + fee policy guard
9. Phase 8: US6 — Mobile status API
10. Phase 9: US7 — Admin management API
11. Phase 10: US8 — Observability + hardening + runbook
12. Phase 11: Polish — PHPStan + OpenAPI + quickstart

### Parallel Team Strategy

With two developers after Phase 2 completes:
- **Dev A**: US1 (T009–T012) → US3 (T018–T022) → US7 (T033–T036)
- **Dev B**: US2 (T013–T017) → US4 (T023–T026) → US5 (T027–T028)
- **Dev C** (if available): US6 (T029–T032) → US8 (T037–T041)

---

## Notes

- All state-changing refund operations require `Idempotency-Key` header (Constitution P8)
- `LedgerModuleContract::postEntry()` is the ONLY permitted financial recording mechanism (Constitution P4)
- `RefundService` MUST NOT write wallet tables directly; all balance operations via `WalletModuleContract`
- AD-16 (bank refund fee policy) is UNRESOLVED — bank refund accounting MUST be recorded as `pending_ledger_refund_fee_blocked`, never posted with an incomplete journal
- AD-07 (dual `ProviderCapabilityEnum`) — use `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum` for all refund capability checks
- `SELECT FOR UPDATE SKIP LOCKED` is required for `sumReservedRefundsForAttempt()` to prevent lost-update races
- Tests marked [P] within a phase can be launched simultaneously; implementation tasks sharing the same service file must run sequentially
