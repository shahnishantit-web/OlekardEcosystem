# Feature Specification: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Feature Branch**: `057-ledger-ack-fulfilment`

**Created**: 2026-10-21

**Status**: Draft

**Input**: P08 — Ledger acknowledgement and fulfilment handoff. Predecessors: P01, P06, P07. Source refs: FD07; LC03 LC04 LC05; section 6. ADR-E48-003.

**Predecessor evidence verified**:
- P01 COMPLETE — `LedgerModuleContract` interface confirmed at `app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php`; `WalletModuleContract::getPocketLedgerAccountRef()` confirmed present; ADR-E48-003 accounting decisions reviewed
- P06 COMPLETE — `PaymentAttemptStatus::Succeeded`, `PaymentIntent.accounting_status` (LedgerPosted/LedgerFailed/PendingLedger), `PaymentIntent.fulfilment_status` confirmed
- P07 COMPLETE — `PaymentIdempotencyService`, `DeadlockRetryTrait`, Guard D all confirmed present

**Accounting decision status** (from ADR-E48-003 — reviewed 2026-10-21):
- AD-01 CONFIRMED — Wallet balance owned exclusively by `WalletModuleContract`
- AD-02 CONFIRMED — `LedgerModuleContract::postEntry()` is the sole financial record mechanism
- AD-03 CONFIRMED — Wallet payment fee = 0
- AD-05 CONFIRMED (partial) — Debit side = consumer pocket (`WalletModuleContract::getPocketLedgerAccountRef()`); credit side PENDING AD-08
- AD-06 CONFIRMED — Refunds use compensating entries; original journals immutable
- **AD-08 UNRESOLVED** — Credit account for wallet payment journal (organisation suspense/liability/pocket) — AWAITING Finance Lead + arch doc v1.1; **BLOCKS full journal posting**
- **AD-09 UNRESOLVED** — Bank payment chart of accounts — AWAITING Finance Lead + arch doc v1.1; **BLOCKS bank payment accounting**
- **AD-13 UNRESOLVED** — Suspense account for ledger-unavailable captures — P08 lead to propose design

---

## User Scenarios & Testing

### User Story 1 — Wallet Payment Accounting Acknowledgement (Priority: P1)

When a consumer's wallet payment is confirmed (captured), the system records a balanced double-entry journal entry using the consumer's wallet pocket as the debit side and a configurable credit account reference as the credit side. The journal is posted idempotently — a replayed confirmation does not create a second journal. The payment intent's accounting status advances from `pending_ledger` to `ledger_posted` only after the journal is durably written.

**Why this priority**: Without an acknowledged ledger entry the payment is financially unverified. This is the minimum viable accounting step for any wallet payment. All subsequent domain actions (fulfilment, settlement, reconciliation) depend on a posted journal existing.

**Independent Test**: Trigger a wallet payment confirmation; verify `LedgerModuleContract::entryExists(idempotencyKey)` returns true; trigger the same confirmation again with the same idempotency key; verify only one journal entry exists; verify `payment_intents.accounting_status = ledger_posted`.

**Acceptance Scenarios**:

1. **Given** a wallet payment attempt has reached `status = succeeded` and `ledger_journal_id IS NULL`, **When** `handleOutcome()` runs, **Then** a balanced two-line journal is posted via `LedgerModuleContract::postEntry()` with debit = consumer pocket account and credit = configured organisation credit account; `payment_attempts.ledger_journal_id` is set to the returned journal ID; `payment_intents.accounting_status` is set to `ledger_posted`.

2. **Given** the same payment confirmation is replayed (same idempotency key), **When** `handleOutcome()` runs again, **Then** `LedgerModuleContract::postEntry()` returns the existing journal ID (idempotency replay); no second journal entry is created; no state change occurs.

3. **Given** the credit account reference (AD-08) has not yet been configured, **When** `handleOutcome()` runs for a wallet payment, **Then** the journal posting is skipped; `payment_intents.accounting_status` is set to `pending_ledger`; capture (attempt `status = succeeded`) is preserved; a structured log entry is emitted at `warning` level indicating accounting is pending an open accounting decision.

4. **Given** a bank payment attempt reaches `status = succeeded`, **When** `handleOutcome()` runs, **Then** no journal entry is attempted (AD-09 is UNRESOLVED); `payment_intents.accounting_status` is set to `pending_ledger`; capture is preserved; a structured log at `warning` level records that bank accounting is pending AD-09 resolution.

---

### User Story 2 — Capture-Accounting Decoupling (Priority: P1)

A ledger service outage or error does not reverse a confirmed capture. When the ledger call fails, the system preserves the `PaymentAttempt` in `succeeded` state and records the intent's accounting status as `pending_ledger`. An automated repair job periodically retries accounting for intents with `pending_ledger` status without re-initiating any payment or re-charging the consumer.

**Why this priority**: The consumer has already paid. A ledger failure must not undo that fact. Atomic coupling of capture and accounting acknowledgement would create a false negative if the ledger is temporarily unavailable — money moved but the system would show payment as failed.

**Independent Test**: Simulate a ledger service unavailability during `handleOutcome()`; verify `payment_attempts.status = succeeded` is preserved; verify `payment_intents.accounting_status = pending_ledger`; restore the ledger service; run the accounting repair job; verify `accounting_status` advances to `ledger_posted` and a journal entry now exists.

**Acceptance Scenarios**:

1. **Given** `LedgerModuleContract::postEntry()` throws any exception during `handleOutcome()`, **When** the outcome processing continues, **Then** the exception is caught; `PaymentAttempt.status` remains `succeeded` (capture preserved); `PaymentIntent.accounting_status` is set to `pending_ledger`; the exception is logged at `error` level with the intent ID, attempt ID, and error message for operator visibility.

2. **Given** `PaymentIntent.accounting_status = pending_ledger` and the ledger service is now available, **When** the `RepairPendingAccountingJob` runs, **Then** it calls `LedgerModuleContract::postEntry()` with the same idempotency key; on success, `accounting_status` advances to `ledger_posted`; `payment_intents.ledger_repair_at` is recorded.

3. **Given** `RepairPendingAccountingJob` is called for an intent whose journal was already posted (idempotency replay), **When** `postEntry()` returns the existing journal ID, **Then** `accounting_status` advances to `ledger_posted` without error; no second journal is created.

4. **Given** `RepairPendingAccountingJob` has retried the maximum configured attempts and the ledger is still unavailable, **When** the max retries are exhausted, **Then** the intent is marked `accounting_status = ledger_failed`; a `ReconciliationException(AccountingFailure)` is created for operator review; an alert is emitted at `critical` log level.

---

### User Story 3 — Durable Payment.Confirmed Publication (Priority: P2)

The `payment.confirmed` integration event is published durably via the outbox pattern, after AND ONLY AFTER the ledger journal is durably posted. The event payload includes a stable `allocation_reference` (= `payment_intent_id`) that consuming domain modules use as their idempotency key for domain allocation. A replayed `payment.confirmed` event cannot trigger a second allocation of the same domain entitlement.

**Why this priority**: Consuming modules (funeral, events, raffle) must not fulfil an obligation before it is financially acknowledged. Publishing `payment.confirmed` before the ledger entry is posted creates a state inconsistency — fulfilment delivered, money not confirmed. Additionally, duplicate events must not create duplicate allocations.

**Independent Test**: Post a wallet payment confirmation; verify `payment.confirmed` event is published with `allocation_reference = payment_intent_id`; publish the same event a second time; verify the consuming module's idempotency check prevents a second allocation; verify `payment_intents.fulfilment_status` advances on first allocation and remains unchanged on second.

**Acceptance Scenarios**:

1. **Given** `LedgerModuleContract::postEntry()` succeeds, **When** the DB transaction commits, **Then** a `payment.confirmed` outbox record is created atomically in the same transaction; the event payload includes `allocation_reference` = `payment_intent_id`, `amount_minor`, `currency_code`, `purpose_type`, `purpose_reference`, `organisation_id`, `payer_actor_id`, `provider_code`, `fee_minor`, `occurred_at`.

2. **Given** `payment_intents.accounting_status = pending_ledger` (ledger not yet posted), **When** the DB transaction commits, **Then** no `payment.confirmed` event is published; the event publication is deferred until the accounting repair job successfully posts the journal.

3. **Given** `RepairPendingAccountingJob` successfully posts the journal for a previously-pending intent, **When** the repair completes, **Then** `payment.confirmed` is published (via outbox); the event's `occurred_at` reflects the original capture time, not the repair time.

4. **Given** a consuming module receives `payment.confirmed`, **When** it attempts domain allocation using `allocation_reference` as its idempotency key, **Then** the first allocation succeeds and advances the domain record (contribution, ticket, raffle entry); a second attempt with the same `allocation_reference` returns a no-op (idempotent replay); no duplicate entitlement is created.

---

### User Story 4 — Pending Accounting Visibility and Operator Runbook (Priority: P3)

Operators can identify all payment intents with unresolved accounting status (`pending_ledger` or `ledger_failed`) via the admin portal and system metrics. A documented operator runbook covers: the current BLOCKED items (AD-08 bank credit account, AD-09), the expected recovery path when those decisions are resolved, and the manual accounting repair procedure.

**Why this priority**: Finance and operations teams need visibility into unacknowledged payments. Without this, ledger-unavailability incidents and architectural blockers are invisible to operators until customer complaints surface them.

**Independent Test**: Create a payment intent with `accounting_status = pending_ledger`; verify it appears in the admin pending-accounting list endpoint; verify a Prometheus/StatsD metric `payment.accounting.pending_count` is emitted.

**Acceptance Scenarios**:

1. **Given** there are payment intents with `accounting_status IN (pending_ledger, ledger_failed)`, **When** an admin with `payment.intents.admin` permission queries the pending-accounting list, **Then** the response includes all such intents with their intent ID, amount, currency, provider code, accounting status, and time since capture.

2. **Given** the `RepairPendingAccountingJob` runs, **When** it completes, **Then** a metric `payment.accounting.pending_count` is emitted with the count of intents still in `pending_ledger` state; a metric `payment.accounting.repair_success_count` increments for each successfully repaired intent.

3. **Given** a new accounting decision (AD-08 or AD-09) is confirmed and the credit account reference is configured, **When** the `RepairPendingAccountingJob` runs next, **Then** all previously-pending intents for the affected provider type are re-processed with the newly available credit account.

---

### Edge Cases

- What happens when `WalletModuleContract::getPocketLedgerAccountRef()` returns null (pocket not found)? → Journal posting is skipped; accounting status = `pending_ledger`; structured log at `error` level; no capture rollback.
- What happens when the same `payment.confirmed` event is delivered twice to a consuming module? → The consuming module MUST use `allocation_reference` as its idempotency key; the second delivery is a no-op.
- What happens when the repair job runs for an intent that was already repaired by a concurrent repair job? → `LedgerModuleContract::postEntry()` returns the same journal ID (idempotency); accounting_status is set to `ledger_posted`; the job completes without error.
- What happens when a bank payment is captured but AD-09 is still unresolved? → Capture is preserved; accounting_status = `pending_ledger`; no journal entry attempted; a structured log documents the blocked decision reference (AD-09).
- What happens when `payment.confirmed` is published but the consuming module's fulfilment handler is down? → The event is durably stored in the outbox; Horizon/queue retry delivers it when the handler recovers; idempotency key on the allocation prevents duplicate fulfilment.
- What happens when a wallet payment's pocket is closed or the wallet is deactivated before accounting repair runs? → The repair job calls `getPocketLedgerAccountRef()` which returns null; accounting cannot be posted; a `ReconciliationException(AccountingBlockedPocketClosed)` is created; operator resolution required.

---

## Requirements

### Functional Requirements

- **FR-001**: The system MUST post a balanced double-entry journal for every wallet payment capture using `LedgerModuleContract::postEntry()` with an idempotency key of `payment:{payment_intent_id}:ack`.
- **FR-002**: The debit side of the journal MUST use the consumer's wallet pocket ledger account reference obtained via `WalletModuleContract::getPocketLedgerAccountRef(walletId, currencyCode)`.
- **FR-003**: The credit side of the journal MUST use a configurable organisation credit account reference (pending AD-08 resolution); when not configured, the journal MUST NOT be posted and `accounting_status` MUST be set to `pending_ledger`.
- **FR-004**: A failed `LedgerModuleContract::postEntry()` call MUST NOT roll back the `PaymentAttempt.status = succeeded` (capture must survive ledger outage).
- **FR-005**: The system MUST decouple the DB transaction that records capture (`PaymentAttempt.status = succeeded`) from the ledger posting call; the ledger call MUST occur outside the capture transaction or in a separate, independent transaction.
- **FR-006**: The `RepairPendingAccountingJob` MUST retry ledger acknowledgement for all intents with `accounting_status = pending_ledger` using the original idempotency key, without re-initiating payment or re-charging the consumer.
- **FR-007**: The `payment.confirmed` integration event MUST be published via the outbox pattern atomically with the ledger journal posting (within the same DB transaction that writes the journal ID); the event MUST NOT be published before the journal is durably posted.
- **FR-008**: The `payment.confirmed` event payload MUST include `allocation_reference` (= `payment_intent_id`) so consuming modules can use it as their idempotency key for domain allocation.
- **FR-009**: A consuming module that receives `payment.confirmed` and uses `allocation_reference` as its domain allocation idempotency key MUST receive a no-op on a second delivery of the same event — no duplicate entitlement may be created.
- **FR-010**: Bank payment captures (no `wallet_hold_id`) MUST have `accounting_status = pending_ledger` until AD-09 is resolved; no journal entry MUST be attempted for bank payments in this package.
- **FR-011**: The system MUST emit a structured log at `warning` level whenever `accounting_status = pending_ledger` is set, including the intent ID, reason, and the blocking decision reference (AD-08 or AD-09 as applicable).
- **FR-012**: An admin endpoint MUST list payment intents with `accounting_status IN (pending_ledger, ledger_failed)` for operators with `payment.intents.admin` permission.
- **FR-013**: The repair job MUST emit a metric `payment.accounting.pending_count` after each run; a metric `payment.accounting.repair_success_count` MUST increment for each successfully repaired intent.
- **FR-014**: After `RepairPendingAccountingJob` exhausts maximum retries on an intent, it MUST create a `ReconciliationException(AccountingFailure)` and emit a `critical` log.
- **FR-015**: The idempotency key for `LedgerModuleContract::postEntry()` MUST be `payment:{payment_intent_id}:ack` (using the intent ID, not the attempt ID) to survive attempt retries.

### Key Entities

- **PaymentIntent**: Extended with `ledger_repair_at` (nullable timestamp recording when the accounting repair job last attempted posting).
- **PaymentIdempotencyKey**: `payment:{payment_intent_id}:ack` — the stable ledger idempotency key that survives attempt retries and crash recovery.
- **RepairPendingAccountingJob**: Background queue job that scans for `pending_ledger` intents and retries `LedgerModuleContract::postEntry()` for each.
- **AccountingFailureReconciliationException**: A new `ReconciliationExceptionType` case for when accounting repair is exhausted.
- **payment.confirmed event** (outbox): Integration event published after ledger is posted; payload includes `allocation_reference` for idempotent consuming module allocation.
- **Pending Accounting Admin Endpoint**: Read-only endpoint listing intents with unresolved accounting status.

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: A single wallet payment confirmation produces exactly one ledger journal entry regardless of how many times the confirmation callback arrives (verified by `LedgerModuleContract::entryExists()` returning true after any number of replays).
- **SC-02**: A ledger service outage lasting up to the repair job's retry window does not result in any payment showing `failed` status — all captures remain `succeeded` with `accounting_status = pending_ledger`.
- **SC-03**: The `RepairPendingAccountingJob` restores accounting for all `pending_ledger` intents within one repair run cycle after the ledger service recovers — zero pending intents remain after a complete successful repair sweep.
- **SC-04**: A `payment.confirmed` event with the same `allocation_reference` delivered twice to a consuming module results in exactly one domain allocation — no duplicate entitlements are created.
- **SC-05**: The admin pending-accounting list returns all `pending_ledger` and `ledger_failed` intents within one second, with correct accounting status, for operators with `payment.intents.admin` permission.
- **SC-06**: `payment.accounting.pending_count` metric is emitted within 5 minutes of any payment capture whose accounting could not be posted.
- **SC-07**: The `payment.confirmed` event is published in the same DB transaction that writes the journal ID — observers never see the journal ID without a corresponding outbox event or vice versa.

---

## Assumptions

- **A1**: P01's `WalletModuleContract::getPocketLedgerAccountRef(walletId, currencyCode)` is implemented and returns a non-null value for any active wallet pocket. If null is returned, accounting is deferred (US1 AC-3).
- **A2**: The configured `payment.pending_credit_account_{currency}` key will be provided by Finance Lead when AD-08 is resolved. Until then, accounting for wallet payments is `pending_ledger`. This is an architectural placeholder — not an invented accounting decision.
- **A3**: Bank payment accounting is explicitly BLOCKED on AD-09. P08 implements the deferral path (capture preserved, `pending_ledger` status, repair job ready) so that when AD-09 is resolved, bank accounting can be activated by adding the credit account config without code changes to the core journal-posting logic.
- **A4**: The `PaymentConfirmedEvent` class already exists (confirmed in codebase). P08 replaces the direct `events->publish()` call with an outbox insert.
- **A5**: Consuming modules (funeral, events, raffle) are responsible for their own idempotency using `allocation_reference`. P08 delivers the correct `allocation_reference` in the event payload; it does not implement consuming module idempotency.
- **A6**: `RepairPendingAccountingJob` is triggered on a schedule (every minute) — same pattern as `PaymentAttemptCrashRecoveryJob`. Maximum repair retries defaults to 5, configurable via `payment.accounting_repair_max_retries`.
- **A7**: The `payment_intents` table already has `accounting_status` (from P06). P08 adds `ledger_repair_at` column for tracking repair attempts.
- **A8**: The split of capture transaction from accounting transaction means the DB commit order is: (1) capture commit, then (2) accounting attempt. If accounting fails, the repair job handles it. This is a deliberate design choice to honour the principle that money moved cannot be denied.
- **A9**: `ReconciliationExceptionType::AccountingFailure` is a new enum case added in P08. It is distinct from `UnknownOutcome` — this is a known financial outcome (payment captured) with an administrative/technical failure to post it.
