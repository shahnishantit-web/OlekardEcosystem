# Feature Specification: P06 — Intent, Attempt and Payment State Engine

**Feature Branch**: `055-payment-state-engine`

**Created**: 2026-10-21

**Status**: Draft

**Input**: P06 — Intent attempt and payment state engine. Mode: Implement.

**Predecessors**: P02, P03, P04, P05

**Source references**: FD04; LC02, LC03; section 6 (OleKard Shared Payment Architecture v1.1)

---

## Context

P02–P05 established the schema, API contracts, tenant isolation, purpose validation, quote/fee snapshots, and provider eligibility pipeline. P06 wires these into a live, durable payment lifecycle: from intent creation through attempt orchestration to authoritative outcome recording — including the `UNKNOWN` intermediate state that protects financial integrity when a network timeout occurs before a provider outcome is confirmed.

The primary financial safety rule: **an unknown outcome must never be silently treated as a failure or success**. This prevents both duplicate charges (retrying on a provider that may already have charged) and missed fulfilment (ignoring a charge that succeeded but was not reported).

---

## User Scenarios & Testing

### User Story 1 — Create and Resume Payment Intent (Priority: P1)

A customer initiates a payment for a business purpose. If an active intent already exists for the same purpose and organisation, the system resumes it rather than creating a duplicate. The intent captures the obligation identity (purpose type + reference), the server-resolved amount, and a durable idempotency key.

**Why this priority**: Foundation for all payment flows. Without a durable intent, no attempt can be started.

**Independent Test**: Send `POST /payment/intents` twice with the same Idempotency-Key — second call returns the same intent ID. Send with a different Idempotency-Key for the same purpose_reference — returns the existing active intent (resume).

**Acceptance Scenarios**:

1. **Given** a payable business obligation exists, **When** a customer POSTs `/payment/intents` with a valid purpose reference and Idempotency-Key, **Then** a new intent is created in `CREATED` state with the server-resolved amount and a quote snapshot, and the intent ID is returned.
2. **Given** the same Idempotency-Key is used twice, **When** the second request arrives, **Then** the same intent is returned idempotently with HTTP 201.
3. **Given** an active intent already exists for the same purpose_reference and organisation, **When** a new create request arrives with a different Idempotency-Key, **Then** the existing active intent is returned (resume behaviour, no duplicate created).
4. **Given** the obligation has expired, **When** the create intent request is processed, **Then** the request is rejected with `PURPOSE_NOT_PAYABLE`.
5. **Given** the actor does not own the obligation, **When** the create intent request is processed, **Then** the request is rejected with `PURPOSE_NOT_PAYABLE`.

---

### User Story 2 — Start a Payment Attempt with Persist-Before-Execute (Priority: P1)

A customer selects a provider and starts a payment attempt. The attempt record is written to durable storage **before** the adapter is called, ensuring no charge can be lost even if the server crashes between the network call and the DB write. The attempt stores the quote version and a durable `operation_id` for adapter deduplication.

**Why this priority**: Core safety property for financial integrity — persist-before-execute is non-negotiable per constitution Principle 4.

**Independent Test**: Simulate a server crash after the adapter succeeds but before the DB write would have completed in the old order. Verify that the durable attempt record exists and is in `SUBMITTING` state, allowing the system to detect and recover the outcome.

**Acceptance Scenarios**:

1. **Given** an intent in `CREATED` state, **When** a customer POSTs `/payment/intents/{id}/attempts` with a valid provider and Idempotency-Key, **Then** an attempt record is created in `SUBMITTING` state before the adapter is called; the attempt stores `quote_id`, `quote_version`, and a stable `operation_id`.
2. **Given** the same Idempotency-Key is used twice, **When** the second request arrives, **Then** the same attempt is returned idempotently with HTTP 201.
3. **Given** an active attempt already exists for the intent, **When** a new attempt is started for the same provider, **Then** the request is rejected with `DUPLICATE_ATTEMPT`.
4. **Given** an active attempt is in `UNKNOWN` state for the intent, **When** a new attempt is requested for a **different** provider, **Then** the request is rejected with `UNSAFE_PROVIDER_SWITCH` — prior attempt may have charged.
5. **Given** an attempt remains `UNKNOWN`, **When** a fresh Idempotency-Key requests a replacement with the same provider, **Then** reject with `DUPLICATE_ATTEMPT`; original-key replay/query remains available. Replacement requires verified resolution under normal state rules; provider identity alone is not proof of safe retry.
6. **Given** the intent has expired, **When** an attempt is initiated, **Then** the request is rejected with `INVALID_STATE_TRANSITION`.

---

### User Story 3 — State Transitions with UNKNOWN Timeout (Priority: P1)

When a payment attempt times out without a confirmed provider outcome, it transitions to `UNKNOWN` state — not `failed`. The intent remains non-terminal. The operator can then query the provider and resolve the attempt through manual reconciliation or an out-of-band callback.

**Why this priority**: Core financial safety rule. Marking a timed-out attempt as failed would allow a retry that could double-charge the customer.

**Independent Test**: Start an attempt, allow the timeout to fire without a provider callback. Verify the attempt is `UNKNOWN` (not `failed`), the intent is NOT in a terminal state, and a reconciliation exception is raised. Verify that a subsequent `succeeded` callback resolves the attempt correctly.

**Acceptance Scenarios**:

1. **Given** an attempt in `SUBMITTING` or `PROCESSING` state, **When** the timeout job fires and the provider query returns no definitive outcome, **Then** the attempt transitions to `UNKNOWN` and a reconciliation exception is created; the intent status does NOT move to `failed`.
2. **Given** an attempt in `UNKNOWN` state, **When** a `succeeded` callback arrives, **Then** the attempt transitions from `UNKNOWN` → `succeeded` and the intent transitions to `succeeded`; the accounting and fulfilment states are updated.
3. **Given** an attempt in `UNKNOWN` state, **When** a `failed` callback arrives, **Then** the attempt transitions from `UNKNOWN` → `failed` and the intent returns to a retriable state; the wallet hold is released.
4. **Given** an attempt in `succeeded` state, **When** a late `failed` callback arrives (out-of-order), **Then** the transition is rejected; the `succeeded` state is preserved (no regression).
5. **Given** an attempt has an active wallet hold and transitions to `UNKNOWN`, **Then** the wallet hold is NOT automatically released — it is preserved pending outcome resolution.

---

### User Story 4 — Query Attempt Status (Priority: P2)

A customer or operator queries the current status of a payment attempt, including the typed next action (for redirect/SDK flows) and the independent accounting and fulfilment states of the intent.

**Why this priority**: Required for polling-based payment flows where customers wait for confirmation.

**Independent Test**: Start an attempt that returns a `redirect` next action. Query the attempt status — verify `next_action_type`, `next_action_data`, and `status` are correct. Verify that a different customer cannot query someone else's attempt.

**Acceptance Scenarios**:

1. **Given** an attempt exists, **When** the owning customer queries `GET /payment/intents/{id}/attempts/{attemptId}`, **Then** the current status, next_action_type, next_action_data, and timestamps are returned.
2. **Given** an attempt exists, **When** a different customer queries the same attempt, **Then** a 403 is returned.
3. **Given** the intent exists, **When** the owning customer queries `GET /payment/intents/{id}/status`, **Then** both the payment status and independent `accounting_status` and `fulfilment_status` fields are returned.
4. **Given** an attempt is `UNKNOWN`, **When** the status is queried, **Then** the response includes `UNKNOWN` status and the reconciliation_exception_id if one exists.

---

### User Story 5 — Authoritative Evidence Checks and Excess Capture Guard (Priority: P2)

The `handleOutcome` path validates that: (a) the reported outcome does not contradict the current state, (b) the captured amount does not exceed the authorised amount, and (c) out-of-order callbacks do not regress a confirmed success.

**Why this priority**: Financial integrity — excess captures create liability; out-of-order regressions create duplicate fulfilment risk.

**Independent Test**: Trigger `handleOutcome` with `succeeded` for an already-`succeeded` attempt — verify idempotent return, no duplicate ledger posting. Trigger `handleOutcome` with `failed` for a `succeeded` attempt — verify rejection. Trigger with a capture amount exceeding the authorised amount — verify `EXCESS_CAPTURE` reconciliation exception is raised.

**Acceptance Scenarios**:

1. **Given** an attempt is `succeeded`, **When** a duplicate `succeeded` outcome arrives with the same provider reference, **Then** the call is treated as idempotent and returns without re-posting the ledger entry.
2. **Given** an attempt is `succeeded`, **When** a `failed` outcome arrives, **Then** the outcome is rejected; a `CONTRADICTORY_EVIDENCE` reconciliation exception is created.
3. **Given** a `succeeded` outcome arrives with a `captured_amount_minor` exceeding `intent.amount_minor + tolerance`, **Then** the `succeeded` transition completes but an `EXCESS_CAPTURE` reconciliation exception is created for operator review.
4. **Given** an attempt is in any terminal state, **When** any further outcome arrives, **Then** the system checks idempotency (same outcome = return silently) or raises `CONTRADICTORY_EVIDENCE`.

---

### Edge Cases

- What happens when the adapter throws an exception after the attempt record has been written in `SUBMITTING` state? → The attempt remains in `SUBMITTING`; a compensating state update to `failed` is applied within the error handler, and the wallet hold (if any) is released.
- What happens when two concurrent requests with the same Idempotency-Key arrive simultaneously? → One creates the attempt; the other replays the idempotent response. DB unique constraint on `idempotency_key` acts as a safety net.
- What happens when the intent expires while an attempt is in `UNKNOWN` state? → The intent expiry is deferred; `UNKNOWN` attempts are not forcibly expired — the outcome must be resolved first.
- What happens when there is no adapter registered for the requested provider? → The attempt record is NOT created; a 422 with `PROVIDER_NOT_FOUND` or `ADAPTER_NOT_REGISTERED` is returned before any state change.
- What happens when a callback arrives for an attempt that no longer exists (e.g., it was deleted via admin)? → The callback is acknowledged (to avoid provider retries) but a reconciliation exception is raised.

---

## Requirements

### Functional Requirements

- **FR-01**: When a customer creates a payment intent, the system MUST resolve the authoritative amount from the obligation — not from the caller's input — and record the obligation identity (purpose type and reference) durably.
- **FR-02**: The system MUST return an existing active intent for the same purpose reference and organisation when a create request arrives with a different Idempotency-Key (resume behaviour).
- **FR-03**: When a customer starts an attempt, the system MUST write the attempt record in `SUBMITTING` state to durable storage before calling the external provider adapter.
- **FR-04**: Each attempt MUST store a durable `operation_id` (UUID v7, separate from the Idempotency-Key) that is passed to the adapter for provider-side deduplication.
- **FR-05**: Each attempt MUST record the `quote_id` and `quote_version` that governed the fee calculation at the time of attempt initiation.
- **FR-06**: The system MUST block a new attempt with a **different** provider when an existing attempt for the same intent is in `UNKNOWN` state.
- **FR-07**: While a prior attempt remains `UNKNOWN`, the system MUST block a fresh operation, including with the same provider. It MUST preserve original-key replay and query of the original operation. Verified resolution is required before replacement under normal state rules. This corrects the earlier unsafe fresh-key wording under P10-R1 and architecture v1.1 FD06/LC03/LC05; no bank deduplication guarantee is assumed.
- **FR-08**: When an attempt timeout fires and the provider status query does not return a definitive outcome, the attempt MUST transition to `UNKNOWN` state — it MUST NOT be marked `failed`.
- **FR-09**: When an attempt is in `UNKNOWN` state, the intent MUST NOT transition to a terminal state — it remains non-terminal pending outcome resolution.
- **FR-10**: A `succeeded` transition MUST NOT be overwritten by a subsequent `failed` or any other non-terminal outcome. Out-of-order callbacks for a terminal attempt are either treated as idempotent (same outcome) or raise a `CONTRADICTORY_EVIDENCE` reconciliation exception.
- **FR-11**: When a `succeeded` outcome arrives with a captured amount that exceeds the authorised amount plus a platform-configurable tolerance, the system MUST create an `EXCESS_CAPTURE` reconciliation exception for operator review.
- **FR-12**: When an attempt has an active wallet hold and transitions to `UNKNOWN`, the hold MUST NOT be automatically released — it must be preserved until the outcome is resolved.
- **FR-13**: When an attempt in `SUBMITTING` state fails (adapter error), the system MUST update the attempt to `failed`, release any wallet hold, and leave the intent in a retriable state.
- **FR-14**: The system MUST expose `GET /payment/intents/{id}/attempts/{attemptId}` returning the attempt status, next_action_type, next_action_data, and the reconciliation exception ID if applicable.
- **FR-15**: The intent status endpoint MUST include independent `accounting_status` and `fulfilment_status` fields alongside the payment `status`.
- **FR-16**: Database locks MUST NOT be held during network calls to external adapters. The persist-before-execute pattern achieves this: write the `SUBMITTING` attempt first (in transaction), commit, then call the adapter outside any lock.

### Key Entities

- **PaymentIntent**: Represents a customer's intention to pay a specific obligation. States: `CREATED` → `PROCESSING` → `AWAITING_ACTION` | `REQUIRES_CONFIRMATION` → `succeeded`/`failed`/`cancelled`/`expired`. Has independent `accounting_status` and `fulfilment_status` dimensions.
- **PaymentAttempt**: A single attempt to execute a payment via a specific provider. States: `SUBMITTING` → `PROCESSING` → `AWAITING_ACTION` → `succeeded`/`failed`/`cancelled`/`expired`/`UNKNOWN`. Stores `operation_id`, `quote_id`, `quote_version`.
- **ReconciliationException**: Created when an unknown outcome, contradictory evidence, or excess capture is detected. Types: `unknown_outcome`, `contradictory_evidence`, `excess_capture`.
- **OperationId**: A durable UUID v7 generated when the attempt is first created, passed to the adapter as the idempotency handle for provider-side deduplication. Survives server restarts.

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: A payment attempt record exists in durable storage before any external network call — verifiable by unit test simulating an adapter exception immediately on call.
- **SC-02**: Two concurrent attempts with the same Idempotency-Key for the same intent result in exactly one attempt record — verifiable via concurrent unit test.
- **SC-03**: An attempt that times out without a provider response is always in `UNKNOWN` state — no attempt may transition from `SUBMITTING`/`PROCESSING` to `failed` via the timeout path alone.
- **SC-04**: A `succeeded` attempt receiving a subsequent `failed` callback produces a `CONTRADICTORY_EVIDENCE` exception and no state change — verifiable via unit test.
- **SC-05**: A new attempt for a different provider is blocked when the current attempt is `UNKNOWN` — verifiable via unit test, 409 `UNSAFE_PROVIDER_SWITCH`.
- **SC-06**: All state transitions not listed in the allowed transition table are rejected with `INVALID_STATE_TRANSITION` — verifiable via unit test across all enum value pairs.
- **SC-07**: The intent and attempt status endpoints return authoritative state with independent accounting and fulfilment dimensions — verifiable via integration test.
- **SC-08**: Excess captures trigger a reconciliation exception without blocking the `succeeded` transition — verifiable via unit test.

---

## Assumptions

- **A-01**: `timed_out` in the existing `PaymentAttemptStatus` enum will be formally replaced by `unknown` with the same allowed inbound transitions (`succeeded`, `failed`, `expired` from `unknown`) plus a new disallowed regress protection.
- **A-02**: "Persist before external execution" means the attempt record is written inside a DB transaction that commits BEFORE the adapter network call. The adapter call runs outside any open DB lock.
- **A-03**: `operation_id` is a new UUID v7 column on `payment_attempts`. It is generated at attempt-create time and is immutable thereafter.
- **A-04**: `quote_id` and `quote_version` are new columns on `payment_attempts`; they reference the `payment_quotes` table and are populated from the active quote at attempt initiation time.
- **A-05**: The tolerance for `EXCESS_CAPTURE` detection is stored in the `payment.excess_capture_tolerance_minor` platform config key (default 0 = no tolerance).
- **A-06**: FD04 and LC02/LC03 remain AWAITING_DOCUMENT. Their content is not used here; all state machine rules are derived from the existing codebase and this specification.
- **A-07**: The "resume intent" behaviour described in FR-02 is already partially implemented in `createIntent()` via `findActiveForPurpose()`. P06 extends this with explicit HTTP semantics and tests.
- **A-08**: No new provider adapter interface changes are required — `operation_id` is passed through the existing `PaymentInitiateRequest` value object.
