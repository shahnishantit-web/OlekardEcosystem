# Feature Specification: P15 — Shared Refund Engine and Original Method Return

**Feature Branch**: `062-shared-refund-engine`

**Created**: 2026-10-24

**Status**: Draft

**Work Package**: P15 (Shared Payment Architecture delivery register)

**Authority**: Constitution Principles 1–15; ADR-E48-003; `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`

**Required Predecessors**: P08 (COMPLETE), P09 (COMPLETE), P10 (COMPLETE), P11 (COMPLETE)

**Source References**: FD08 (refunds, reversals and disputes); LC11 (full or partial refund lifecycle); AD07 (dual ProviderCapabilityEnum — UNRESOLVED, affects bank adapter capability check); ADR-E48-003 AD-06 (compensating entry rule), AD-16 (fee refund policy — UNRESOLVED for bank payments).

**Source document verified**: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`, internal Version 1.1, SHA256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`.

---

## Predecessor Evidence Summary

**P08 (Ledger Ack and Fulfilment Handoff)** — `LedgerModuleContract::postEntry()` confirmed; `payment.confirmed` outbox event confirmed; `RepairPendingAccountingJob` confirmed; accounting status enum (`pending_ledger`, `ledger_posted`, `ledger_failed`) confirmed.

**P09 (Wallet Payment Adapter)** — `WalletPaymentAdapter::initiateRefund()` returns `PaymentRefundResult::Succeeded`; adapter does NOT call `releaseHold()` for post-capture refunds; idempotency confirmed; `RefundsPayment` contract confirmed at `app/Modules/Payment/Domain/Contracts/RefundsPayment.php`.

**P10 (E25 Callback Ingress)** — inbox dispatcher confirmed; bank callback routing to `Payments` inbox via verified event receipts confirmed; `UnknownProviderOutcomeException` handling confirmed.

**P11 (Outbox/Inbox Delivery)** — `OutboxEventPublisher` confirmed; `RefundConfirmedEvent` outbox confirmed; inbox consumer deduplication confirmed.

**Existing partial implementation** (created before P15 was formally specified):
- `RefundService` with `request()`, `approveRefund()`, `rejectRefund()`, `initiate()`, `confirm()`, `fail()`
- `PaymentRefundModel`, `PaymentRefundStatus` enum, two migrations (`2026_10_18_000006`, `2026_10_21_000005`)
- `PaymentRefundController` (POST `/v1/payment/intents/{intentId}/refunds`)
- `RefundApprovalAdminController` (list/approve/reject endpoints)
- Unit tests: `RefundApprovalGateTest`, `PaymentRefundStatusTest`, `StepUpEnforcementTest`

**Gaps P15 must close** (verified against source spec and existing code):
1. In-flight reservation: `sumConfirmedRefunds` excludes `pending_approval` and `processing` amounts — over-refund race possible.
2. `Pending` and `Unknown` execution states missing from `PaymentRefundStatus` enum and state machine.
3. No ledger compensating entry posted on wallet refund confirmation (AD-06).
4. No domain eligibility hook before refund request (LC11 step 2).
5. No async bank refund callback handler.
6. No mobile read API for refund status.
7. Fee policy handling deferred (AD-16 UNRESOLVED for bank; AD-03 confirms wallet fee = 0).
8. `Requested` status exists in enum but service skips it — inconsistency to be resolved.

---

## User Scenarios & Testing

### User Story 1 — Domain Eligibility Gate and Exact Refundable Balance (Priority: P1)

Before a refund request is accepted, the system confirms with the originating business module that the entitlement is eligible for financial return (e.g., the ticket has not been admitted, the contribution qualifies for cancellation policy). Separately, the system computes the exact refundable balance by summing all confirmed AND in-flight (pending approval, submitted, unknown) refunds atomically, so that concurrent requests cannot collectively exceed the original captured amount.

**Why this priority**: Without the eligibility gate, a financial refund can be processed even after the consumer has already used the entitlement (attended the event, been admitted). Without including in-flight amounts in the reservation, two concurrent partial refund requests can both pass the balance check and together exceed the original payment — a financial integrity violation.

**Independent Test**: Submit two simultaneous partial refund requests, each for 60% of the original payment. Verify only one succeeds; the other is rejected with a refund-limit-exceeded error. Verify the accepted one includes its amount in the reservation immediately. Separately, submit a refund request for an entitlement whose domain module returns ineligible; verify the request is rejected with an eligibility error before any reservation.

**Acceptance Scenarios**:

1. **Given** a succeeded payment with original amount 100 BWP and no prior refunds, **when** two simultaneous refund requests are submitted for 60 BWP each, **then** exactly one is accepted and the other is rejected with `REFUND_LIMIT_EXCEEDED`; the accepted reservation includes the 60 BWP in-flight amount; the total reserved never exceeds 100 BWP.

2. **Given** a domain module that returns `ineligible` for the purpose reference (e.g., ticket already admitted), **when** a refund is requested for that payment intent, **then** the request is rejected with `DOMAIN_ELIGIBILITY_REJECTED` before any reservation is created; no `PaymentRefund` record is persisted.

3. **Given** two prior confirmed refunds totalling 80 BWP and one in-flight refund of 10 BWP (`processing` state), **when** a new refund request for 15 BWP is submitted, **then** it is rejected with `REFUND_LIMIT_EXCEEDED` (80 + 10 + 15 = 105 > 100); the in-flight 10 BWP is correctly included in the limit.

4. **Given** a domain module that is unavailable (timeout), **when** a refund is requested, **then** the request fails with a recoverable error; no `PaymentRefund` record is created; the error is logged at `error` level with correlation ID.

5. **Given** an intent whose domain module returns eligible and the refundable balance is sufficient, **when** the refund is requested, **then** a `PaymentRefund` record is created with the reserved amount; the cumulative reserved total reflects the new reservation immediately.

---

### User Story 2 — Complete Refund State Machine (Priority: P1)

The refund state machine covers the full lifecycle from request to terminal state: `requested → (pending_approval →) submitted → pending → confirmed` or `requested → (pending_approval →) submitted → unknown → confirmed | definitive_failure`. The `Pending` state means the provider has the request and a response is expected. The `Unknown` state means the provider's response was ambiguous. An `Unknown` refund keeps its reservation — it is never released for use by another refund without explicit operator action. A `DefinitiveFailure` state means the provider has permanently declined.

**Why this priority**: The current state machine is missing `Submitted`, `Pending`, and `Unknown` — critical states for asynchronous bank refunds where the provider does not confirm synchronously. Without these states, async bank refunds have no valid representation and unknown outcomes could incorrectly release reserved amounts.

**Independent Test**: Drive a refund through each state transition programmatically (no external provider required); verify `canTransitionTo()` allows valid transitions and rejects invalid ones; verify that a refund in `Unknown` state cannot be reassigned for another refund request; verify `DefinitiveFailure` releases the reservation exactly once.

**Acceptance Scenarios**:

1. **Given** a refund record in `processing` state, **when** `submit()` is called with a stable provider refund ID, **then** the record transitions to `submitted`; the provider refund ID is persisted; the state transition is audited.

2. **Given** a submitted refund, **when** the provider acknowledges receipt but has not completed processing, **then** the record transitions to `pending`; the reserved amount remains locked; the mobile status API returns `pending` with no funds-returned message.

3. **Given** a submitted refund, **when** the provider response cannot be conclusively interpreted (ambiguous code), **then** the record transitions to `unknown`; the reserved amount is NOT released; an operator alert is emitted; the mobile status API returns a non-committal status.

4. **Given** a refund in `unknown` state, **when** a subsequent status query resolves to confirmed success, **then** the record transitions to `confirmed`; the reserved amount is released; compensating ledger entry is posted (wallet) or recorded (bank pending AD-16); `refund.confirmed` outbox event is published.

5. **Given** a refund in `unknown` state, **when** an operator attempts to request another refund covering the same funds, **then** the request is rejected because the `unknown` amount is included in the in-flight total; the error indicates funds are under active investigation.

6. **Given** a refund in `submitted` or `pending` state, **when** the provider definitively declines (e.g., original payment already reversed by bank), **then** the record transitions to `definitive_failure`; the reserved amount is released; a `ReconciliationException(DefinitiveRefundFailure)` is created; `refund.failed` outbox event is published.

---

### User Story 3 — Wallet Refund with Compensating Ledger Entry (Priority: P1)

When a wallet refund is confirmed, the system posts a compensating credit journal entry via `LedgerModuleContract::postEntry()` — crediting the consumer's wallet pocket account and debiting the organisation's account (reversing the original payment journal). The journal uses the `refundId` as its idempotency key. The compensating entry is posted atomically with the state transition in the same DB transaction. A replayed confirmation does not create a second journal entry.

**Why this priority**: AD-06 confirms that refunds use compensating entries; original journals must not be altered. The current `RefundService::confirm()` publishes `RefundConfirmedEvent` but does NOT post a compensating journal — this is an AD-06 violation that P15 must close for wallet refunds.

**Independent Test**: Call `RefundService::confirm()` for a wallet refund; verify `LedgerModuleContract::postEntry()` is called with debit = org account and credit = consumer pocket account; call `confirm()` again with the same `refundId`; verify only one journal entry exists (idempotency); verify `RefundConfirmedEvent` is published once.

**Acceptance Scenarios**:

1. **Given** a wallet refund in `submitted` state, **when** the adapter returns `Succeeded`, **then** `LedgerModuleContract::postEntry()` is called with: debit = organisation account reference, credit = consumer wallet pocket ledger account ref, amount = refund amount, idempotency key = `refund:{refundId}`; the refund record transitions to `confirmed`; `RefundConfirmedEvent` is published via outbox — all in the same transaction.

2. **Given** the same `confirm()` call is replayed (same `refundId`), **when** `LedgerModuleContract::postEntry()` is called, **then** it returns the existing journal ID (idempotency); no second journal entry is created; no state change occurs (already `confirmed`); no second `RefundConfirmedEvent` is emitted.

3. **Given** `LedgerModuleContract::postEntry()` throws during wallet refund confirmation, **when** the exception is caught, **then** the refund state transitions to `confirmed` (wallet debit happened); `accounting_status` on the related intent is set to `pending_ledger_refund`; a repair job will retry the ledger entry using the same idempotency key; the refund record is NOT rolled back to a prior state.

4. **Given** the ledger repair job processes a `pending_ledger_refund` intent, **when** it posts the compensating entry, **then** `accounting_status` advances to `ledger_refund_posted`; `RefundConfirmedEvent` is published (once); the repair is audited.

5. **Given** a partial refund is confirmed for 40 BWP of a 100 BWP payment, **when** the compensating entry is posted, **then** it reflects exactly 40 BWP (not the full 100 BWP original); the remaining 60 BWP refundable balance is computed correctly; `isFullRefund = false` in the outbox event payload.

---

### User Story 4 — Async Bank Refund via Callback (Priority: P2)

When a bank refund adapter does not confirm synchronously, the refund transitions to `submitted` then `pending`. The bank's eventual callback arrives via E25's verified ingress (P10) and is dispatched to the Payments inbox. The inbox handler advances the refund to `confirmed` or `definitive_failure` using the provider refund reference from the callback. The refund operation ID is stable — the callback can be re-delivered without creating a duplicate confirmation. Bank refund accounting is deferred pending AD-16 resolution.

**Why this priority**: Bank refunds are inherently asynchronous; the adapter call returns a submitted or pending status. Without the callback handler, bank refunds are permanently stuck in `pending` state with no resolution path.

**Independent Test**: Enqueue a mock verified bank callback for a refund in `pending` state; verify the inbox handler advances it to `confirmed`; enqueue the same callback again; verify the second delivery is a no-op (idempotency); verify the reconciliation exception is NOT created for a clean confirmation.

**Acceptance Scenarios**:

1. **Given** a bank refund in `submitted` state, **when** the bank adapter returns `Pending` (async acknowledgement), **then** the refund transitions to `pending`; the provider's submitted reference is stored; a scheduled `QueryRefundStatusJob` is registered for the refund.

2. **Given** a bank refund in `pending` state, **when** a verified bank callback arrives via E25 confirming the refund, **then** the inbox handler transitions the refund to `confirmed`; `refund.confirmed` outbox event is published; the provider refund reference is stored; bank refund accounting is recorded as `pending_ledger_refund` (BLOCKED: AD-16) with a structured log at `warning` level noting the open decision.

3. **Given** a bank refund in `pending` state, **when** the same confirmed callback is received a second time (duplicate delivery), **then** the inbox handler detects the refund is already `confirmed`; no second state change occurs; no second `refund.confirmed` event is published.

4. **Given** a bank refund in `pending` state and `QueryRefundStatusJob` polls the provider, **when** the provider returns a pending-no-change status, **then** the refund remains `pending`; the job reschedules; no state change occurs.

5. **Given** a bank refund in `pending` state, **when** the provider definitively declines via callback, **then** the inbox handler transitions the refund to `definitive_failure`; the reserved amount is released; `ReconciliationException(DefinitiveRefundFailure)` is created; `refund.failed` outbox event is published.

---

### User Story 5 — Unsupported Method and Fee Policy Exception Handling (Priority: P2)

When the provider used for the original payment does not support refunds (capability absent), the refund request raises an approved exception routed to finance review — never silently credits the consumer's wallet. When the original provider supports refunds but fee policy is ambiguous (AD-16 unresolved for bank payments), the refund execution is blocked with an explicit BLOCKED status referencing the open accounting decision — not silently processed with an incorrect journal.

**Why this priority**: FD08 and LC11 explicitly state: unsupported original-method refunds require an approved exception, not silent wallet credit. This boundary must be enforced at the application layer and tested explicitly.

**Independent Test**: Configure a provider with no refund capability; attempt a refund via that provider; verify `CapabilityUnsupportedException` is raised and a `ReconciliationException(UnsupportedRefundMethod)` is created; verify no wallet credit is issued; verify no `PaymentRefund` record is in `confirmed` state.

**Acceptance Scenarios**:

1. **Given** a succeeded payment via a bank provider without refund capability, **when** a refund is requested, **then** `CapabilityUnsupportedException` is thrown; a `ReconciliationException(UnsupportedRefundMethod)` is created for finance review; no `PaymentRefund` record is created; the requester receives a clear error with a reference number.

2. **Given** a bank refund confirmed by the provider, **when** the compensating ledger entry would require fee policy (AD-16 is UNRESOLVED), **then** the journal posting is blocked; `accounting_status = pending_ledger_refund_fee_blocked`; a structured warning log references AD-16; the refund itself is `confirmed`; a finance operator alert is emitted.

3. **Given** a wallet refund (AD-03 confirms fee = 0), **when** the compensating entry is posted, **then** no fee calculation is attempted; the full refund amount is returned to the consumer's wallet pocket; the journal entry does not reference any fee account.

4. **Given** an admin attempting to manually mark a refund as succeeded without provider evidence, **when** the attempt is made, **then** no API endpoint permits this action; all state transitions require either adapter evidence (callback) or an authorised force-close with audit trail under operator runbook conditions only.

---

### User Story 6 — Mobile Refund Status API (Priority: P2)

The consumer mobile app can check the status of a refund against their payment without mutating any state. The API returns the refund status in human-meaningful terms, the reserved/returned amount, and the applicable next action. A `pending` or `unknown` refund is never described as "returned" money. The API is read-only; mobile never drives refund state transitions.

**Why this priority**: Mobile consumers need to track refund progress. Without a read API, the mobile app has no way to show refund status beyond the payment intent's top-level state — resulting in poor UX and false "returned" impressions.

**Independent Test**: Create a refund in each state (`requested`, `pending_approval`, `submitted`, `pending`, `unknown`, `confirmed`, `definitive_failure`); call the mobile status endpoint for each; verify the response label and `funds_returned` boolean are correct for each state; verify `confirmed` is the only state where `funds_returned = true`.

**Acceptance Scenarios**:

1. **Given** an authenticated consumer with a payment intent, **when** `GET /v1/customer/payments/{intentId}/refunds` is called, **then** the response lists all refunds for that intent with: refund ID, status label, amount, currency, requested_at, and `funds_returned` boolean.

2. **Given** a refund in `pending` or `unknown` state, **when** the mobile app calls the status endpoint, **then** `funds_returned = false`; the status label is a non-committal message (e.g., "being processed"); no "returned to account" message is shown.

3. **Given** a refund in `confirmed` state, **when** the mobile app calls the status endpoint, **then** `funds_returned = true`; the amount and confirmation timestamp are included.

4. **Given** a consumer requesting the refund status for a payment intent they do not own, **when** the API is called, **then** a 403 response is returned; no refund data is exposed.

5. **Given** a payment intent with multiple partial refunds (some confirmed, some pending), **when** the status endpoint is called, **then** all refunds are listed; the confirmed total and remaining refundable balance are computed and returned.

---

### User Story 7 — Admin Refund Management (Priority: P2)

Administrators with the `payment.refunds.view` permission can view all refunds across the platform, filtered by status, date range, organisation, and provider. Beyond the existing pending-approval queue, admins can view the full refund history for any payment intent, see UNKNOWN refunds with their reservation status, and trigger a manual status query for ambiguous refunds. They cannot manually mark a refund as confirmed or failed without an audit-logged force-close.

**Why this priority**: Operations teams need to monitor the full refund pipeline, not just the approval queue. UNKNOWN refunds in particular require operator visibility to avoid permanently locked reservations.

**Independent Test**: Create refunds in various states; verify they appear in the admin list with correct status and filtering; verify a finance operator can trigger `query_status` on a `pending` refund without modifying its state; verify only `payment.refunds.approve` can advance approval states.

**Acceptance Scenarios**:

1. **Given** an admin with `payment.refunds.view`, **when** `GET /v1/admin/payment/refunds` is called with `status=unknown`, **then** all `unknown` refunds are returned with their reserved amount, intent ID, and time since last state change.

2. **Given** an admin with `payment.refunds.manage`, **when** `POST /v1/admin/payment/refunds/{id}/query-status` is called, **then** `QueryRefundStatusJob` is dispatched immediately; the endpoint returns 202 Accepted; no state change occurs until the job completes.

3. **Given** a `definitive_failure` refund with a `ReconciliationException` linked, **when** the admin views the refund detail, **then** the exception reference and reason are included in the response; no state mutation endpoint is available to the admin (operator runbook only).

4. **Given** an admin without `payment.refunds.approve`, **when** attempting to call approve or reject endpoints, **then** a 403 response is returned; the audit log records the attempt.

---

### User Story 8 — Observability, Idempotency Hardening, and Operator Runbook (Priority: P3)

Metrics are emitted for refund state transitions, reservation changes, and accounting lag. The `confirm()` method is hardened against idempotent replay (currently re-saves unconditionally). An operator runbook documents: how to investigate an UNKNOWN refund, how to handle AD-16 blocked bank refund accounting once resolved, and how to safely force-close a permanently ambiguous refund.

**Why this priority**: Without idempotency hardening, a replayed `confirm()` could double-post a ledger entry. Without metrics, refund SLA and accounting lag are invisible. Without the runbook, operations teams cannot safely resolve ambiguous states.

**Independent Test**: Call `confirm()` twice for the same refund; verify only one ledger journal entry exists and only one `RefundConfirmedEvent` is emitted; verify a `payment.refund.confirmed` metric is emitted once.

**Acceptance Scenarios**:

1. **Given** `RefundService::confirm()` is called for a refund already in `confirmed` state, **when** the call is made, **then** it returns the existing refund record immediately without calling `LedgerModuleContract::postEntry()` again; no second outbox event is written.

2. **Given** a refund transitions from any non-terminal state to a terminal state, **when** the transition is committed, **then** a metric `payment.refund.state_transition` is emitted with tags: `from_state`, `to_state`, `provider_code`, `is_wallet`.

3. **Given** there are refunds in `unknown` state older than a configurable threshold (default 24 hours), **when** the monitoring job runs, **then** an alert is emitted at `warning` level listing the affected refund IDs and their reserved amounts; the metric `payment.refund.unknown_count` is emitted.

4. **Given** an open accounting decision (AD-16) for a bank refund, **when** the accounting repair job detects refunds in `pending_ledger_refund_fee_blocked` state, **then** it skips them (does NOT attempt posting); it logs at `info` level that AD-16 resolution is required; the count is included in `payment.refund.accounting_blocked_count` metric.

---

### Edge Cases

- What happens when a refund is requested for an intent in `partially_refunded` state? → Valid; the refundable balance check uses original amount minus all confirmed + in-flight refunds.
- What happens when the wallet pocket is closed before a confirmed refund's compensating ledger entry can be posted? → Ledger repair job logs the failure; `accounting_status = pending_ledger_refund`; operator alert; original `confirmed` state is preserved; no financial rollback.
- What happens when a bank refund callback arrives for a refund already in `confirmed` state (duplicate)? → Inbox handler detects terminal state; no state change; no second journal; delivery is acknowledged.
- What happens when the domain eligibility service is permanently unavailable? → Refund requests fail with a recoverable error; no silent bypass; circuit-breaker pattern with operator alert after threshold.
- What happens when an admin requests force-close of a `definitive_failure` refund whose reservation was already released? → Idempotent; no double-release; audit log records the operator action.
- What happens when `AD-07` dual ProviderCapabilityEnum conflict causes the wrong capability to be checked? → Refund capability check must explicitly use `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum` (the 8-case version); P15 documents this as a known risk pending architect resolution; P15 adds a test that explicitly checks both enums are consistent for `ReversePriorPayment`.
- What happens when a partial refund confirmed amount plus a later confirmed refund would exceed the original? → DB-level `CHECK` constraint and application-level `SELECT FOR UPDATE` guard together prevent this; both must be tested.

---

## Requirements

### Functional Requirements

- **FR-001**: The system MUST include all in-flight refund amounts (`pending_approval`, `submitted`, `pending`, `unknown`) in the cumulative refundable balance check, not only confirmed amounts.
- **FR-002**: The system MUST invoke a domain eligibility check (via a registered purpose handler or domain contract) before creating a `PaymentRefund` record; an ineligible entitlement MUST result in a rejected request, not a deferred exception.
- **FR-003**: `PaymentRefundStatus` MUST include `submitted`, `pending`, and `unknown` states in addition to existing states; `canTransitionTo()` MUST enforce valid transitions.
- **FR-004**: On wallet refund confirmation, the system MUST post a compensating credit journal entry via `LedgerModuleContract::postEntry()` using `refund:{refundId}` as the idempotency key; failure to post MUST set `accounting_status = pending_ledger_refund` without rolling back the `confirmed` state.
- **FR-005**: `RefundService::confirm()` MUST be idempotent; a second call for an already-`confirmed` refund MUST return the existing record without posting a second journal or emitting a second event.
- **FR-006**: Bank refund callbacks received via the E25/P10 inbox MUST be handled by a `BankRefundCallbackHandler` that advances the refund state to `confirmed` or `definitive_failure` using the provider refund reference as the deduplication key.
- **FR-007**: When a provider does not support refunds (capability absent), the system MUST raise `CapabilityUnsupportedException` and create a `ReconciliationException(UnsupportedRefundMethod)`; it MUST NOT issue a silent wallet credit.
- **FR-008**: Bank refund accounting MUST be recorded as `pending_ledger_refund_fee_blocked` when AD-16 is unresolved; the system MUST NOT post an incomplete or incorrect compensating journal for bank payments.
- **FR-009**: A `GET /v1/customer/payments/{intentId}/refunds` endpoint MUST be available to authenticated consumers; it MUST return only refunds for intents owned by the requesting consumer; `funds_returned` MUST be `false` for any non-`confirmed` state.
- **FR-010**: A `GET /v1/admin/payment/refunds` endpoint with status/date/org filtering MUST be available to admins with `payment.refunds.view` permission.
- **FR-011**: `QueryRefundStatusJob` MUST be dispatchable for any refund in `submitted`, `pending`, or `unknown` state; it MUST NOT change state directly — only the provider response drives state transitions.
- **FR-012**: All refund state transitions MUST be audited via `AuditServiceInterface` with actor ID, refund ID, from/to state, and correlation ID.
- **FR-013**: Metrics MUST be emitted on state transitions (`payment.refund.state_transition`) and for `unknown` refund counts (`payment.refund.unknown_count`).
- **FR-014**: An `unknown` refund's reserved amount MUST NOT be released without explicit operator confirmation or a confirmed provider status; `definitive_failure` MAY release it under policy.

### Key Entities

- **PaymentRefund**: Represents one refund execution against a captured payment attempt; has its own status, reservation, and provider reference; linked to `PaymentIntent` and `PaymentAttempt`.
- **RefundReservation**: The sum of confirmed + in-flight refund amounts; computed from `PaymentRefund` rows with lock; enforced by application guard and DB constraint.
- **DomainEligibilityResult**: The response from the business module's purpose handler confirming whether the entitlement may be financially reversed.
- **BankRefundCallbackPayload**: Verified callback from E25 containing provider refund reference, status, and amount; deduplication key = provider refund reference.
- **RefundAccountingStatus**: Accounting sub-status on `PaymentRefund` (or on `PaymentIntent`) tracking `pending_ledger_refund`, `ledger_refund_posted`, `pending_ledger_refund_fee_blocked`.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: Two simultaneous partial refund requests totalling more than the original payment amount — exactly one is accepted and one is rejected; the wallet or bank balance is never over-refunded; verified by concurrent test.
- **SC-002**: A wallet refund confirmation produces exactly one compensating ledger journal entry even when `confirm()` is replayed; verified by replay test.
- **SC-003**: A refund in `unknown` state is never shown as "funds returned" to the mobile consumer; verified by API test covering each status.
- **SC-004**: A provider without refund capability triggers a `ReconciliationException` and no wallet credit; verified by unit test.
- **SC-005**: All refund state transitions are present in the audit log within 1 second of commitment; verified by integration test.
- **SC-006**: Bank refund callbacks delivered more than once produce exactly one confirmed refund and one `refund.confirmed` event; verified by duplicate-delivery test.
- **SC-007**: Refunds with unresolved AD-16 fee policy produce a `pending_ledger_refund_fee_blocked` status — NOT a failed journal; verified by unit test with bank adapter.
- **SC-008**: An admin with `payment.refunds.view` can list all `unknown` refunds and their reserved amounts; verified by API test.

---

## Assumptions

- The domain eligibility interface is a method on the existing `PurposeHandlerRegistryInterface` (or equivalent registered handler) that accepts a `purpose_type` and `purpose_reference` and returns an eligibility result. If no handler is registered, the refund is permitted by default (domain modules opt-in to eligibility control).
- `LedgerModuleContract` exists and is callable from `RefundService`; the debit/credit account references for wallet refund compensating entries follow the same account reference pattern as the original wallet payment journal (AD-05/AD-06).
- The wallet refund compensating entry uses `WalletModuleContract::getPocketLedgerAccountRef()` for the credit side; the debit side is the same organisation account reference used in the original payment (AD-05). This assumption must be confirmed by Finance Lead before implementation.
- AD-16 (bank refund fee policy) remains UNRESOLVED; all bank refund accounting is explicitly deferred; this is not a P15 blocker for the refund execution itself.
- AD-07 (dual ProviderCapabilityEnum) affects which capability enum the refund capability check uses; P15 will use `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum` and document the risk; resolution is the architect's responsibility pre-P13/P14 bank adapters.
- The mobile API uses the same JWT authentication as other `/v1/customer/` endpoints; organisation scope is derived server-side from the JWT.
- No admin portal Next.js pages are in scope for P15 beyond API endpoints; the existing `RefundApprovalAdminController` and planned `GET /v1/admin/payment/refunds` endpoint serve as the admin interface; a dedicated Admin UI page is out of scope for this work package (may be a follow-on).
- The `Requested` status inconsistency (enum case exists but service skips it) will be resolved by removing `Requested` from the state machine and starting directly at `PendingApproval` or `Processing`; the DB `CHECK` constraint must be updated accordingly.
