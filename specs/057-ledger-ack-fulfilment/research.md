# Research: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Date**: 2026-10-21
**Status**: Complete — all decisions resolved from codebase inspection; no external unknowns

---

## Decision 1: Outbox Pattern

**Decision**: Use `OutboxEventPublisher::publish()` (already wired in the container as `EventPublisherInterface`). Call it inside a DB transaction with `DB::connection('platform')`.

**Rationale**: `OutboxEventPublisher` enforces transactional safety — it throws `LogicException` if called outside a transaction. This is the canonical implementation of Principle 9 (event reliability/outbox). The `platform.outbox_events` table already exists.

**Alternatives considered**: Dispatching a Laravel `ShouldQueue` job — rejected because job dispatch is not atomic with the DB write; a crash between the DB commit and the job dispatch would lose the event.

---

## Decision 2: Capture-Accounting Decoupling

**Decision**: Restructure `handleOutcome()` so the capture transaction (`PaymentAttempt.status = succeeded` + intent `status = Succeeded` + hold release) commits first. After the commit, run ledger posting in a separate phase (not inside any DB transaction from the capture phase). If ledger posting succeeds, open a new platform+payment transaction to write `journal_id`, update `accounting_status = ledger_posted`, and publish `payment.confirmed` via outbox.

**Rationale**: Principle 4 — "the consumer has already paid; a ledger failure must not undo that fact." The capture DB commit is the financial truth; the ledger acknowledgement is the accounting record of that truth. These must be independent.

**Alternatives considered**: Two-phase commit — rejected; overkill for a modular monolith. Saga pattern — rejected; over-engineered for a single-service scenario. Compensating transaction — rejected; there is nothing to compensate once capture is committed.

---

## Decision 3: Idempotency Key for Ledger

**Decision**: `payment:{payment_intent_id}:ack` — the intent ID, not the attempt ID.

**Rationale**: An intent can have multiple attempts (e.g., retry after crash recovery). The ledger entry must be exactly one per intent, regardless of how many attempts it took to achieve `succeeded`. Using the attempt ID would allow a second attempt (after crash recovery) to post a duplicate journal.

**Alternatives considered**: Attempt ID — rejected; breaks idempotency across attempt retries. Correlation ID — rejected; a new correlation ID is generated per API call, making replay unsafe.

---

## Decision 4: Credit Account Reference (AD-08 Deferral)

**Decision**: Read from config key `payment.credit_account_{currency_code}` (e.g., `payment.credit_account_BWP`) via `ConfigurationServiceInterface::getOrDefault()`. If null/missing: skip journal posting, set `accounting_status = pending_ledger`, log warning with `{reason: 'ad-08-unresolved'}`.

**Rationale**: Principle 2 (TBD Governance) — do not invent accounting decisions. The config key is a placeholder that Finance Lead activates by setting the value. No code change required to activate wallet accounting once AD-08 is resolved.

**Alternatives considered**: Hardcode a suspense account — rejected; violates Principle 2. Use `null` as credit reference and let the ledger reject it — rejected; a `postEntry()` exception would be indistinguishable from a ledger outage.

---

## Decision 5: Bank Payment Deferral (AD-09)

**Decision**: Bank payments are identified by `$attempt->wallet_hold_id === null`. When this is true, skip journal posting entirely, set `accounting_status = pending_ledger`, log warning with `{reason: 'ad-09-unresolved'}`.

**Rationale**: AD-09 (bank payment chart of accounts) is UNRESOLVED. The debit account for a bank payment is not known. Attempting to post a partial journal would violate Principle 4 (balanced journals). The `pending_ledger` path preserves capture and queues accounting for when AD-09 is resolved.

**Alternatives considered**: Post debit-only journal for bank payments — rejected; violates Principle 4 (partial debit/credit outcomes are prohibited).

---

## Decision 6: Repair Job Architecture

**Decision**: Two-job pattern: `RepairPendingAccountingJob` (scan) dispatches `RepairSingleAccountingJob` per intent. The scan job has a `uniqueId()` to prevent overlapping scans.

**Rationale**: Matches the existing `PaymentAttemptCrashRecoveryJob` + `PaymentAttemptSingleRecoveryJob` pattern already in the codebase. Scan jobs are cheap and fast; per-intent jobs are independently retried by Horizon.

**Alternatives considered**: Single job that processes all pending intents inline — rejected; a failure in one intent's processing would prevent others from being retried independently.

---

## Decision 7: Repair Retry Logic and Exhaustion

**Decision**: Track retry count in `payment_intents.accounting_repair_attempts` (new column). Max retries from `payment.accounting_repair_max_retries` (default 5). On exhaustion: set `accounting_status = ledger_failed`, create `ReconciliationException(AccountingFailure)`, emit `Log::critical`.

**Rationale**: The spec (FR-014) requires exhaustion → `AccountingFailure` reconciliation exception + critical log. Storing the count on the intent row is consistent with the existing `ledger_repair_at` approach and avoids a separate attempts tracking table.

**Alternatives considered**: Track retries in Redis — rejected; not durable; a Redis flush would reset retry counts and allow infinite retries. Use job `attempts()` from Horizon — rejected; each dispatch of `RepairSingleAccountingJob` starts a new job with `attempts()=1`; the intent-level count needs to persist across dispatches.

---

## Decision 8: `allocation_reference` in `payment.confirmed`

**Decision**: Add `allocation_reference = payment_intent_id` as a new field in `PaymentConfirmedEvent`. This is a non-breaking addition to the event payload.

**Rationale**: FR-008 requires this field so consuming modules can use it as their domain allocation idempotency key. Using `payment_intent_id` directly (not a new UUID) avoids a separate mapping table and is stable across event retries.

**Alternatives considered**: Use `payment_attempt_id` — rejected; a consumer receiving a retry from a different attempt would use a different allocation key, enabling duplicate allocation. Use a separate `allocation_id` UUID — rejected; introduces an extra step with no benefit since `payment_intent_id` is already a UUID v7.

---

## Decision 9: Metrics Pattern

**Decision**: Use structured `Log::info()` with metric-named keys following the existing codebase pattern (no Prometheus SDK found in the Payment module). The repair scan job logs `{payment.accounting.pending_count: N, payment.accounting.repair_success_count: M}` after each run.

**Rationale**: The existing `PaymentAttemptCrashRecoveryJob` uses `Log::info()` with structured arrays. A log-based metrics approach is consistent with the rest of the module. Adding a Prometheus SDK would require an ADR (Principle 14 — no silent architecture changes).

**Alternatives considered**: Add `prometheus/client_php` — rejected; requires an ADR and is out of scope for P08.
