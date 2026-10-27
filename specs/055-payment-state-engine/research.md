# Research: P06 — Intent, Attempt and Payment State Engine

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md)

---

## Decision 1 — Persist-Before-Execute Transaction Boundary

**Decision**: Write the attempt record in `SUBMITTING` state inside a DB transaction, commit it, then call the adapter outside any open DB lock.

**Rationale**: The current `initiateAttempt()` in `PaymentOrchestrationService` calls the adapter first, then writes the attempt in a DB transaction. If the server crashes between the adapter returning and the DB write committing, the charge is lost — the attempt record never exists and there is nothing to reconcile.

Persist-before-execute guarantees: if the server crashes after commit, the `SUBMITTING` record exists and a recovery job can detect it. The wallet hold (if any) is placed inside the first transaction so it is atomically paired with the attempt row.

**Implementation pattern**:
```
Transaction 1: intent.status → PROCESSING, attempt(SUBMITTING) created, wallet hold placed
COMMIT
Network call: adapter.initiatePayment()
Transaction 2: attempt.status updated with adapter result
```

**Alternatives considered**:
- Keep existing order (adapter first, DB second): Rejected — violates constitution Principle 4 and FR-03/FR-16.
- Single transaction wrapping adapter call: Rejected — holds DB lock during network I/O, violating FR-16 and creating deadlock risk.

---

## Decision 2 — `SUBMITTING` State (Rename of `Initiated`)

**Decision**: Add `Submitting = 'submitting'` as a new enum case. Keep `Initiated = 'initiated'` as a deprecated legacy value for any existing DB rows. `Submitting` is the status written before the adapter call; `Initiated` should be treated as an alias for `Processing` for existing rows.

**Rationale**: `Initiated` semantically implied "adapter was called and returned an initial status". `Submitting` means "attempt record durably persisted; adapter not yet called". These are different states. Renaming avoids breaking queries against existing `initiated` rows in staging/dev.

**Transition graph for `submitting`**:
- `submitting` → `processing` | `awaiting_action` | `succeeded` | `failed` | `unknown`
- On adapter error (exception): `submitting` → `failed`
- On adapter success, provider async: `submitting` → `processing`
- On adapter success, provider sync: `submitting` → `succeeded` | `awaiting_action`

---

## Decision 3 — `UNKNOWN` State (Replaces `timed_out`)

**Decision**: Add `Unknown = 'unknown'` as a new enum case. Retain `TimedOut = 'timed_out'` for backward compatibility with existing rows, treating it as equivalent to `Unknown` for all business logic. `Unknown` is NOT terminal.

**Rationale**: `timed_out` implies the outcome is "failure due to timeout", which would allow a retry and risk double-charging. `Unknown` explicitly signals "outcome unresolved — do not retry with a different provider, do not cancel, do not release hold".

**`isTerminal()` for `Unknown`**: returns `false` — intent must not move to terminal state while an UNKNOWN attempt exists.

**Allowed inbound transitions to `unknown`**: from `submitting`, `processing`, `awaiting_action`.

**Allowed outbound transitions from `unknown`**: `succeeded`, `failed`, `expired` (only via operator-confirmed evidence or verified provider callback).

---

## Decision 4 — `operation_id` vs Reusing `idempotency_key`

**Decision**: Add a separate `operation_id` (UUID v7) column to `payment_attempts`. It is generated at attempt-create time (inside Transaction 1, before the adapter call) and passed to the adapter in `PaymentInitiateRequest`.

**Rationale**: `idempotency_key` is a client-supplied key for HTTP replay semantics. `operation_id` is a server-generated, durable identifier passed to the provider for provider-side deduplication. They serve different purposes: `idempotency_key` prevents creating duplicate attempt records; `operation_id` prevents the provider from processing the same payment twice if the network call is retried.

`PaymentInitiateRequest` gains `operationId` as a new field — this is a non-breaking additive change to the value object. Adapters that don't support operation IDs can ignore the field.

---

## Decision 5 — `UNSAFE_PROVIDER_SWITCH` Guard

**Decision**: Before creating a new attempt, check if any existing attempt for the intent is in `UNKNOWN` (or legacy `timed_out`) state. If yes and the requested provider differs from the UNKNOWN attempt's provider, throw `UnsafeProviderSwitchException` (HTTP 409, error code `UNSAFE_PROVIDER_SWITCH`). Same-provider retries are allowed with a fresh idempotency key.

**Rationale**: An UNKNOWN attempt may have already charged the customer via the current provider. Starting a fresh attempt with a different provider risks double-charging the customer on two different providers. The guard blocks this until the operator resolves the UNKNOWN attempt.

**Same-provider retry semantics**: `findActiveForIntent()` currently returns a single non-terminal attempt. The guard must specifically check for `UNKNOWN` status; if the existing UNKNOWN attempt is for the same provider, the retry is allowed (the provider can use `operation_id` to deduplicate on its side).

---

## Decision 6 — `CONTRADICTORY_EVIDENCE` and `EXCESS_CAPTURE`

**Decision**: Add two new cases to `ReconciliationExceptionType`:
- `ContradictoryEvidence = 'contradictory_evidence'`: raised when a `failed` callback arrives for an already-`succeeded` attempt (or any conflicting terminal-to-terminal transition).
- `ExcessCapture = 'excess_capture'`: raised when the captured amount exceeds `intent.amount_minor + tolerance`. The `succeeded` transition STILL completes — the excess capture is flagged for operator review, not blocked.

**Rationale**: `CONTRADICTORY_EVIDENCE` prevents a `succeeded` state from being overwritten by a late `failed` callback. `EXCESS_CAPTURE` is a notification/review flag — blocking the `succeeded` transition on excess capture would be worse (the money was taken; not marking it succeeded prevents proper fulfilment).

**Tolerance config key**: `payment.excess_capture_tolerance_minor` (default 0). Stored in the platform configuration system (Principle 3).

---

## Decision 7 — `accounting_status` and `fulfilment_status` Update

**Decision**: Update both fields in `handleOutcome()` when the outcome is `succeeded`:
- `accounting_status` → `PaymentAccountingStatus::Posted` (ledger entry confirmed)
- `fulfilment_status` → `PaymentFulfilmentStatus::Pending` (awaiting downstream fulfilment confirmation)

When outcome is `failed` or `cancelled`:
- `accounting_status` → `PaymentAccountingStatus::Void`
- `fulfilment_status` → `PaymentFulfilmentStatus::NotRequired`

When attempt transitions to `UNKNOWN`:
- Both statuses remain unchanged — outcome is not known yet.

**Rationale**: The intent model already has `accounting_status` and `fulfilment_status` columns (from P02), but `handleOutcome()` does not set them. P06 wires this so the status endpoint returns authoritative independent state dimensions (FR-15).

---

## Decision 8 — `findActiveForIntent()` and UNKNOWN State

**Decision**: Expand `findActiveForIntent()` in `EloquentPaymentAttemptRepository` to include `unknown` (and `timed_out`) as non-terminal "active" states, in addition to the current active statuses.

**Rationale**: An UNKNOWN attempt must block new attempts for a different provider. If `findActiveForIntent()` excludes `unknown` attempts from its results, the `UNSAFE_PROVIDER_SWITCH` guard cannot read them.

The repository also needs a new method `findUnknownForIntent(string $intentId): ?PaymentAttemptModel` for the explicit guard check (or the guard can inspect the status of the result from `findActiveForIntent()`).

---

## Decision 9 — `quote_id` and `quote_version` on Attempt

**Decision**: Add `quote_id` (UUID, nullable FK to `payment_quotes.id`) and `quote_version` (INT, nullable) to `payment_attempts`. These are populated in Transaction 1 from the active quote at attempt-initiation time.

**Rationale**: FR-05 requires durable recording of which quote governed the fee calculation at attempt time. If a quote is later revised or replaced, the attempt record still holds the authoritative version that was in effect.

**Source**: `intent->active_quote_id` + active quote model at initiation time.

---

## Decision 10 — Migration Strategy

**Decision**: Create a single additive migration `2026_10_21_000001_p06_payment_attempt_state_engine.php` that:
1. Adds `operation_id` UUID column to `payment_attempts` (unique index).
2. Adds `quote_id` UUID column to `payment_attempts` (nullable, no FK enforced — cross-table in same schema).
3. Adds `quote_version` INT column to `payment_attempts` (nullable).
4. Does NOT rename `initiated` or `timed_out` enum values (kept for backward compat).
5. Adds `contradictory_evidence` and `excess_capture` columns to the reconciliation exceptions type if it uses a CHECK constraint or just extends the application-level enum.
6. Populates `operation_id` for any existing `initiated`/`processing` rows with `gen_random_uuid()` (backfill).

**Rationale**: PostgreSQL enum renaming requires creating a new type and migrating the column — high blast radius. Since the column is cast in PHP and the old values remain valid, keeping them avoids a risky migration.
