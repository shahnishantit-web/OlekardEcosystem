# Implementation Plan: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Branch**: `057-ledger-ack-fulfilment` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/057-ledger-ack-fulfilment/spec.md`

## Summary

Decouples payment capture from ledger acknowledgement in `PaymentOrchestrationService::handleOutcome()`. The current implementation (P06) posts an unbalanced one-line journal inside the capture transaction using the attempt ID as idempotency key — all three of which violate Principles 4 and 9. P08 corrects this by: (1) posting a balanced two-line journal after the capture transaction commits, using `payment:{payment_intent_id}:ack` as the idempotency key; (2) preserving capture on any ledger failure via `pending_ledger` status; (3) repairing pending accounting via `RepairPendingAccountingJob` without recharging; (4) deferring `payment.confirmed` publication until the journal ID is written; and (5) adding `allocation_reference` to the event payload. Bank payment accounting and the wallet credit account are deferred on AD-09 and AD-08 respectively, with explicit config-key placeholders that activate journal posting without code changes once those decisions are confirmed.

## Technical Context

**Language/Version**: PHP 8.4+ / Laravel 11

**Primary Dependencies**:
- `LedgerModuleContract` — sole journal posting interface
- `WalletModuleContract::getPocketLedgerAccountRef()` — wallet debit account reference
- `OutboxEventPublisher` — atomically inserts `platform.outbox_events` inside a transaction
- `ConfigurationServiceInterface` — runtime config lookup (`getOrDefault()`)
- `ReconciliationExceptionService` — creates reconciliation exceptions
- Laravel `DB::connection('payment')` / `DB::connection('platform')` — dual-connection DB access
- Laravel Horizon + Queue — job dispatching

**Storage**:
- `payment.payment_intents` — add `ledger_repair_at` column (nullable timestamp)
- `platform.outbox_events` — existing; `payment.confirmed` inserts go here
- `payment.reconciliation_exceptions` — existing; `AccountingFailure` inserts go here

**Testing**: PHPUnit — unit tests (mock LedgerModuleContract, WalletModuleContract)

**Target Platform**: Backend API — `wallet-ecosystem/services/api/`

**Project Type**: Backend domain service modification (Payment module)

**Performance Goals**:
- Ledger acknowledgement P95 < 500ms (existing SLA)
- `RepairPendingAccountingJob` processes up to 500 pending intents per run

**Constraints**:
- Ledger `postEntry()` call MUST occur outside the capture DB transaction
- Journal MUST have min 2 lines (debit + credit) that balance exactly
- `payment.confirmed` event MUST NOT be published before journal ID is written
- `allocation_reference` MUST equal `payment_intent_id`
- Do NOT invent credit account references for AD-08 or AD-09

**Scale/Scope**: Payment module only — `app/Modules/Payment/`; no cross-module model changes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Status | Notes |
|-----------|------|--------|-------|
| P4 — Financial Integrity (balanced journal) | GATE | **FAILS in current P06 code** | Current code posts one credit line only. P08 fixes to two-line balanced journal. |
| P4 — Financial Integrity (idempotency key) | GATE | **FAILS in current P06 code** | Current key = `payment:{attemptId}:debit`. P08 changes to `payment:{intentId}:ack`. |
| P4 — Financial Integrity (capture independence) | GATE | **FAILS in current P06 code** | Ledger call is inside capture transaction — a ledger exception rolls back capture. P08 decouples them. |
| P9 — Event Reliability (outbox before confirmed) | GATE | **FAILS in current P06 code** | `payment.confirmed` is published in same transaction as the (incorrect) ledger call. P08 defers event publication until journal is durably written. |
| P2 — TBD Governance | PASS | No values invented for AD-08, AD-09 | Uses config key placeholders; documents blockers explicitly. |
| P4 — No direct balance mutation | PASS | Uses `LedgerModuleContract::postEntry()` | Never directly edits wallet balance. |
| P7 — Domain boundaries | PASS | All cross-module access via contracts | No direct Eloquent access to Ledger or Wallet models. |
| P8 — API contracts (idempotency) | PASS | `Idempotency-Key` already on outcome endpoint | No new mutations without idempotency. |
| P13 — Observability | PASS | Structured logs + metrics required | FR-011, FR-013 mandate structured logs and metrics. |

**Gate resolution**: All four GATE failures are defects introduced in P06. P08 is explicitly scoped to correct them. No constitution violations block implementation.

## Project Structure

### Documentation (this feature)

```text
specs/057-ledger-ack-fulfilment/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/           ← Phase 1 output
│   └── pending-accounting.yaml
└── tasks.md             ← /speckit-tasks output (not created here)
```

### Source Code

```text
wallet-ecosystem/services/api/
├── app/
│   ├── Console/
│   │   └── Kernel.php                                    [MODIFY] schedule RepairPendingAccountingJob
│   └── Modules/
│       └── Payment/
│           ├── Application/
│           │   ├── PaymentOrchestrationService.php       [MODIFY] decouple capture from ledger
│           │   └── Jobs/
│           │       └── RepairPendingAccountingJob.php    [CREATE] retry pending accounting
│           ├── Domain/
│           │   ├── Enums/
│           │   │   ├── PaymentAccountingStatus.php       [MODIFY] allow PendingLedger→LedgerPosted repair
│           │   │   └── ReconciliationExceptionType.php   [MODIFY] add AccountingFailure case
│           │   └── Events/
│           │       └── PaymentConfirmedEvent.php         [MODIFY] add allocation_reference field
│           ├── Http/
│           │   ├── Controllers/
│           │   │   └── PendingAccountingController.php  [CREATE] admin list endpoint
│           │   └── routes.php                           [MODIFY] add pending-accounting route
│           ├── Infrastructure/
│           │   ├── Migrations/
│           │   │   └── 2026_10_21_000011_add_payment_intents_ledger_repair_at.php  [CREATE]
│           │   └── Models/
│           │       └── PaymentIntentModel.php           [MODIFY] add ledger_repair_at cast
│           └── Tests/
│               └── Unit/
│                   ├── LedgerAcknowledgementTest.php     [CREATE]
│                   └── RepairPendingAccountingJobTest.php [CREATE]
└── openapi/
    └── v1/
        └── payment.yaml                                 [MODIFY] add pending-accounting endpoint
```

## Complexity Tracking

No constitution violations — no justification required.

---

## Phase 0: Research

*All questions resolved from existing codebase inspection. No external unknowns remain.*

**Research file**: [research.md](research.md)

Decisions resolved:

1. **How does the existing outbox pattern work?** `OutboxEventPublisher::publish()` INSERTs into `platform.outbox_events` inside an active DB transaction. It throws `LogicException` if called outside a transaction. P08 must call it inside the post-capture accounting transaction.

2. **What is the correct `postEntry()` input for a balanced wallet payment journal?** Two lines: `debit` direction against the consumer pocket reference from `getPocketLedgerAccountRef()`; `credit` direction against the configured org credit account. Both lines use the same `amount_minor`. Entry type = `'debit'` (from the payment perspective — money leaves the pocket). Idempotency key = `payment:{intentId}:ack`.

3. **How is the debit ledger account reference obtained?** `WalletModuleContract::getPocketLedgerAccountRef(string $walletId, string $currencyCode): ?string` — returns null if the pocket isn't found.

4. **How should the credit account reference be obtained?** Read from `ConfigurationServiceInterface` with key `payment.credit_account_{currency_code}` (pending AD-08). If not configured (null), skip posting → `pending_ledger`.

5. **How does `PaymentAttemptCrashRecoveryJob` pattern the repair job?** Scan job with `uniqueId()` that queries a repository method returning a collection, dispatches per-record single-recovery jobs. `RepairPendingAccountingJob` follows the same pattern — scan dispatches per-intent `RepairSingleAccountingJob`.

6. **How are metrics emitted?** The codebase uses Laravel's `Log::` for structured logging and relies on log-based metric scraping (no Prometheus SDK imports found in Payment module). For P08, metric logging uses `Log::info()` with a structured payload that includes metric names as keys — consistent with the existing pattern in `PaymentAttemptCrashRecoveryJob`.

7. **What config key holds max repair retries?** `payment.accounting_repair_max_retries` (default 5) — follows the same naming pattern as `payment.crash_recovery_threshold_seconds`.

8. **How should `PaymentAccountingStatus` transitions accommodate repair?** The repair job transitions `pending_ledger → ledger_posted` (success) or `pending_ledger → ledger_failed` (exhaustion). The current state machine already supports these transitions. No change needed to `PaymentAccountingStatus.php`.

9. **Where is `ReconciliationExceptionType` and what format does it use?** `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php` — string-backed enum. P07 added `DuplicateCapture`. P08 adds `AccountingFailure = 'accounting_failure'`.

10. **What is the `payment.confirmed` event's existing payload?** 12 fields (see `PaymentConfirmedEvent::toArray()`). P08 adds `allocation_reference = payment_intent_id` as the 13th field.

---

## Phase 1: Design

*See generated artifacts below.*

**Data model**: [data-model.md](data-model.md)

**Contracts**: [contracts/pending-accounting.yaml](contracts/pending-accounting.yaml)

**Quickstart**: [quickstart.md](quickstart.md)

---

## Post-Design Constitution Check

| Principle | Status after design |
|-----------|---------------------|
| P4 — Balanced journal | PASS — Two-line journal enforced; debit + credit amounts must match |
| P4 — Idempotency key | PASS — `payment:{intentId}:ack` survives attempt retries and crash recovery |
| P4 — Capture independence | PASS — Ledger call in separate post-commit phase; capture preserved on any ledger exception |
| P9 — Event reliability | PASS — `payment.confirmed` published in same DB transaction that writes journal ID |
| P2 — TBD governance | PASS — Config keys are placeholders; `pending_ledger` is the safe default |
| P13 — Observability | PASS — `Log::warning` on `pending_ledger` set; `Log::critical` on `AccountingFailure`; metric logs after repair run |
