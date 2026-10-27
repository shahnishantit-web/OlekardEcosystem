# Feature Specification: P16 — Reversals, Disputes and Chargebacks

**Feature Branch**: `063-reversals-disputes-chargebacks`

**Created**: 2026-10-24

**Status**: Draft

**Work Package**: P16 (Shared Payment Architecture delivery register)

**Authority**: Constitution Principles 1–15; `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt` Version 1.1 (SHA256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`).

**Required Predecessors**: P15 (COMPLETE — shared refund engine, in-flight reservation, compensating ledger entry, state machine)

**Source References**: FD08 (refunds, reversals and disputes); LC12 (provider reversal, dispute and chargeback lifecycle).

---

## Predecessor Evidence Summary

**P15 (Shared Refund Engine)** — `RefundService` with full state machine (Requested, PendingApproval, Processing, Submitted, Pending, Unknown, DefinitiveFailure, Confirmed, Succeeded, Failed, Cancelled, Rejected) confirmed; `LedgerModuleContract::postEntry()` with idempotency confirmed; `ReconciliationExceptionType` enum confirmed; `OutboxEventPublisher` confirmed; `sumReservedRefundsForAttempt()` using SELECT FOR UPDATE SKIP LOCKED confirmed; `BankRefundCallbackController` confirmed; admin and mobile refund APIs confirmed.

**Existing partial relevance** (from E25/P10 and support module E22):
- Bank callback ingress (`InboxDispatcher`) confirmed; can route dispute events similarly
- `ReconciliationExceptionType` includes `UnsupportedRefundMethod` — can be extended for dispute exceptions
- `SupportTicket` model in E22 provides dispute case tracking precedent but is NOT reused (dispute is a financial case, not a customer service case)

**Gaps P16 must close** (verified against LC12):
1. No `PaymentDisputeCase` entity or state machine.
2. No provider dispute/chargeback event ingestion or deduplication.
3. No financial hold mechanism against settled funds for dispute exposure.
4. No domain module coordination interface for unused vs consumed entitlement restriction.
5. No evidence tracking or deadline management.
6. No correcting journal/fee recording on dispute outcome.
7. No double-compensation guard (prior refunds vs dispute resolution).
8. No admin case-management API or mobile visibility API.
9. No runbook for settled-fund exposure or raffle/event liability exceptions.

---

## User Scenarios & Testing

### User Story 1 — Provider Event Ingestion and Deduplication (Priority: P1)

When a payment provider sends a reversal notice, dispute notification, or chargeback confirmation, the system authenticates the event, deduplicates it against known cases, links it to the original capture record, and creates a `PaymentDisputeCase` with the provider reference, disputed amount, reason code, and response deadline. No financial adjustments are made at ingestion — the case starts in `received` state.

**Why this priority**: Without authenticated, deduplicated ingestion, a replay attack or provider retry creates duplicate cases and duplicate financial adjustments — a direct financial integrity violation. The case record is the anchor for all subsequent steps.

**Independent Test**: Send the same dispute event twice; verify only one `PaymentDisputeCase` is created. Send a dispute referencing an unknown payment attempt; verify the event is rejected and logged. Send a valid dispute; verify it is linked to the correct `PaymentAttempt`, carries the provider reference and reason code, and is in `received` state.

**Acceptance Scenarios**:

1. **Given** a valid provider dispute event for a completed payment attempt, **when** the event is received and authenticated, **then** a `PaymentDisputeCase` record is created with `status = received`, the provider dispute reference, disputed amount, reason code, and response deadline; no ledger entry is posted; a `DisputeCaseReceivedEvent` is published to the outbox.

2. **Given** the same provider dispute event is received a second time (replay or retry), **when** the system processes it, **then** no duplicate `PaymentDisputeCase` is created; the event is acknowledged as a duplicate and logged; no financial side effects occur.

3. **Given** a dispute event referencing a payment attempt that does not exist in the system, **when** the event is received, **then** it is rejected; a `ReconciliationException` with type `UnmatchedDisputeEvent` is created; no `PaymentDisputeCase` is created.

4. **Given** a dispute event with an invalid or missing provider signature/authentication token, **when** the event is received, **then** it is rejected with a 401 response; no record is created; the rejection is logged at `warning` level with the provider code and correlation ID.

5. **Given** a dispute for a payment attempt that already has an open case, **when** a second dispute event for the same attempt arrives, **then** the second is linked to the existing case as an update (provider reference appended, deadline updated if earlier), not as a new case; no duplicate financial holds are created.

---

### User Story 2 — Financial Exposure Assessment and Authorised Hold (Priority: P1)

Once a dispute case is received, a finance or risk operator assesses the financial exposure and authorises a hold on eligible available funds. The hold is recorded against the case. The hold MUST NOT create a negative wallet balance or silently debit a consumer wallet — if funds are already settled or paid out, the system records a platform exposure (receivable) instead. The case moves to `under_review` state.

**Why this priority**: Without a controlled hold, disputed funds may be paid out to organisers while the case is open — creating an irrecoverable financial loss. The hold must be authorised (not automatic) because the appropriate response depends on settlement state, prior refunds, and domain context that requires human judgement.

**Independent Test**: Create a dispute case against a payment with available, unsettled funds; authorise a hold; verify the hold amount is recorded, the available funds are reduced accordingly, and no wallet debit occurs. Create a dispute case against fully-settled funds; authorise a hold; verify a platform exposure record is created instead of a wallet debit.

**Acceptance Scenarios**:

1. **Given** a dispute case in `received` state with available unsettled funds, **when** a finance operator authorises a hold for the disputed amount, **then** the hold is recorded against the case; available settlement funds are reduced by the held amount; the case moves to `under_review`; a `DisputeHoldAuthorisedEvent` is published.

2. **Given** a dispute case where the full disputed amount is already paid out, **when** a finance operator assesses and records platform exposure, **then** a receivable record is created for the outstanding amount; no wallet balance is debited; the case moves to `under_review` with `exposure_type = settled_funds_receivable`; the operator action is audited.

3. **Given** prior refunds exist that partially cover the disputed amount, **when** exposure is assessed, **then** the system calculates and displays the net unrefunded exposure (disputed amount minus confirmed prior refunds); the hold is limited to the net unrefunded amount; double-compensation is prevented.

4. **Given** the disputed amount exceeds the maximum authorised hold limit for the operator's role, **when** the hold is submitted, **then** it is rejected with an authorisation error; the case remains in `received` state; the attempt is audited.

   > _P16 scope note: Per-role hold limits require a hold-limit permission matrix that is not yet implemented. This acceptance criterion is deferred to a future enhancement. P16 enforces the platform-level net-exposure cap only (hold_amount_minor ≤ net_exposure_minor). Deferred work: add a `DisputeHoldLimitPolicy` keyed by operator permission tier._

5. **Given** a dispute case moves to `under_review`, **when** the response deadline is queried, **then** the deadline (from the provider event) is visible; a deadline-approaching notification is triggered when the deadline is within the configured warning window.

---

### User Story 3 — Domain Module Entitlement Coordination (Priority: P2)

When a dispute case is under review, the relevant business domain module applies protective actions to the entitlement: blocking an unused ticket or raffle entry from being used, flagging a contribution for potential adjustment, or recording evidence that a service was already consumed. The action is domain-specific and applies only where permitted — the system MUST NOT automatically revoke unrelated entitlements.

**Why this priority**: Without domain coordination, a disputed payment for an unused ticket could still grant entry during a live investigation, creating service-without-payment. Conversely, automatically revoking all entitlements for a consumed service would cause harm without legal basis. The domain module is the authority on what action is permissible.

**Independent Test**: Register a stub `DisputeEntitlementHandlerInterface` for a purpose type; trigger domain coordination for a dispute case referencing that purpose; verify the handler is called with the case ID, purpose reference, and entitlement action; verify the response (block/flag/consumed-evidence/no-action) is recorded on the case. Verify that no handler registered means no entitlement action with a logged note.

**Acceptance Scenarios**:

1. **Given** a dispute case under review for a purpose with a registered domain handler that returns `block_unused`, **when** entitlement coordination runs, **then** the domain handler is called; the entitlement is restricted for the duration of the dispute; the restriction is recorded with the case ID and correlation ID; the case's `entitlement_action` field is set to `blocked`.

2. **Given** a dispute case where the domain handler returns `consumed_evidence_recorded` (ticket was scanned, service was delivered), **when** entitlement coordination runs, **then** the case's `entitlement_action` is set to `consumed`; no restriction is applied to the entitlement; the evidence reference is recorded; this is surfaced to the case owner when preparing evidence.

3. **Given** no domain handler is registered for the purpose type, **when** entitlement coordination runs, **then** no entitlement action is taken; the case's `entitlement_action` is set to `no_handler`; a log entry is written at `info` level; the case proceeds normally.

4. **Given** a domain handler that times out or returns an error, **when** entitlement coordination runs, **then** the error is logged at `error` level; the case's `entitlement_action` is set to `coordination_failed`; the case remains in `under_review` for manual resolution; no entitlement action is assumed.

5. **Given** a dispute case for a raffle after the draw has occurred, **when** entitlement coordination runs and the handler returns `post_draw_liability`, **then** the case is flagged with `requires_finance_review = true`; the operator runbook guidance for post-draw liability is referenced in the case notes.

---

### User Story 4 — Provider Outcome, Correcting Journals and Hold Release (Priority: P2)

When the provider communicates the dispute outcome (won, lost, or reversed), the system records the outcome, posts any required correcting ledger journals and provider fees, releases or converts holds as appropriate, and accounts for any prior refunds to prevent double compensation. The case moves to a terminal state (`won`, `lost`, or `closed_reversed`).

**Why this priority**: The financial integrity of the platform depends on accurate correcting journals. A won dispute means the hold is released and funds remain; a lost dispute means the disputed amount is debited from platform funds or the consumer (under approved policy) and the correcting entry reduces the organiser's payable. Double-compensation — where both a refund and a chargeback recovery are applied — must be explicitly prevented.

**Independent Test**: Receive a `won` outcome; verify the hold is released, no debit is posted to the consumer wallet, and the case moves to `won`. Receive a `lost` outcome; verify a correcting chargeback journal is posted, the hold is converted to a debit against platform funds, prior refunds are credited against the chargeback exposure, and the case moves to `lost`. Receive a `lost` outcome where prior refunds fully cover the disputed amount; verify no additional consumer debit occurs.

**Acceptance Scenarios**:

1. **Given** a dispute case in `under_review` with an authorised hold, **when** the provider communicates a `won` outcome, **then** the hold is released; no debits are posted to consumer or organiser; the case moves to `won`; a `DisputeWonEvent` is published; an `AuditLog` entry is created with the provider decision reference.

2. **Given** a dispute case in `under_review`, **when** the provider communicates a `lost` outcome for the full disputed amount, **then** a correcting chargeback journal entry (debit platform exposure account, credit the disputed asset account) is posted via the ledger with idempotency key `dispute:{caseId}:chargeback`; any provider chargeback fee is posted separately with idempotency key `dispute:{caseId}:fee`; the case moves to `lost`; a `DisputeLostEvent` is published.

3. **Given** a dispute case where a prior refund of 50% of the disputed amount was confirmed before the dispute was raised, **when** the `lost` outcome is recorded, **then** the correcting journal is for the net amount only (disputed amount minus prior confirmed refunds); the double-compensation calculation and amounts are recorded on the case for audit.

4. **Given** a dispute case where the provider reverses their own capture (reversal, not chargeback), **when** the reversal outcome is received, **then** a compensating reversal journal entry is posted via the ledger; the case moves to `closed_reversed`; the payment attempt's `outcome` field is updated to `reversed`; the original capture record is NOT modified.

5. **Given** a `lost` outcome arrives after a previous `won` outcome for the same case (contradictory), **when** the system processes it, **then** the late outcome is recorded as a `LateContradictoryOutcomeEvent`; the case is flagged for manual investigation; no automatic financial adjustment is made; an operator alert is triggered.

---

### User Story 5 — Case Management APIs and Closure (Priority: P2)

Finance and admin operators can list dispute cases, view case timelines, record evidence submissions, and close cases. Mobile/customer-facing users can view a read-only summary of any dispute affecting their payment. The system provides auditable records of all case actions. Raffle and event edge cases are explicitly exposed via case flags, not silently suppressed.

**Why this priority**: Without management APIs, operators have no visibility into open cases, deadlines, or financial exposure. Without mobile visibility, customers receive no explanation for payment holds affecting their account view.

**Independent Test**: Create a dispute case; submit evidence via the admin API; verify the evidence reference is recorded on the case timeline; close the case; verify it cannot receive further financial adjustments. Retrieve the customer-facing summary; verify it shows the case status and amount but does not expose provider-internal details.

**Acceptance Scenarios**:

1. **Given** an admin operator with `payment.disputes.manage` permission, **when** they request the list of open dispute cases, **then** they receive cases filtered by status, deadline proximity, and exposure type; each case shows case ID, provider reference, disputed amount, net exposure, entitlement action, deadline, and current status.

2. **Given** an authorised case owner, **when** they submit an evidence reference (document ID or provider portal reference) via the API, **then** the reference is appended to the case timeline with the submitter identity, timestamp, and correlation ID; the case `evidence_submitted` flag is set to `true`.

3. **Given** a dispute case in a terminal state (`won`, `lost`, `closed_reversed`), **when** an operator attempts to post a financial adjustment (hold, journal, or evidence), **then** the request is rejected with a `CASE_ALREADY_CLOSED` error; no financial side effects occur.

4. **Given** a customer with `payment.disputes.view` permission, **when** they request the dispute summary for a payment intent they own, **then** they receive a summary showing status, disputed amount, and a human-readable status label; provider-internal references, fee amounts, and case-owner notes are NOT included.

5. **Given** a dispute case flagged with `requires_finance_review = true` (post-draw raffle or admitted event liability), **when** the case is listed, **then** a `liability_exception_type` field is present and populated; the operator runbook reference for that exception type is included in the response; the case cannot be closed without an explicit `finance_reviewed = true` acknowledgement.

---

### Edge Cases

- **Already-settled funds**: Provider holds cannot debit a consumer wallet — must create a platform receivable record instead.
- **Duplicate dispute events**: Same case reference received twice must not create two `PaymentDisputeCase` records or duplicate holds/journals.
- **Contradictory provider outcomes**: `won` followed by `lost` (or vice versa) must be investigated, not automatically applied.
- **Prior refunds exceed disputed amount**: Correcting journal for chargeback must be zero or a receivable recovery, not a negative debit.
- **Post-draw raffle dispute**: Requires `post_draw_liability` flag and finance review gate before closure.
- **Consumed-service dispute (admitted ticket)**: Domain module returns `consumed_evidence_recorded`; no entitlement block; evidence reference required for dispute submission.
- **Response deadline breach**: Case must remain open with `deadline_breached = true` flag; operator alert triggered; no automatic financial settlement.
- **Unknown payment attempt**: Dispute event cannot be linked; stored as unmatched reconciliation exception; no case created.
- **Provider reversal on a payment attempt with prior partial refunds**: Net chargeback exposure = disputed amount minus prior confirmed refunds; dual-compensation prevented.
- **Multiple holds on same funds** (chargeback + pending refund): Reserve order must be deterministic; total held must not exceed available settlement balance.

---

## Requirements

### Functional Requirements

- **FR-001**: The system MUST authenticate all incoming provider reversal/dispute/chargeback events using a registered adapter signature or token before creating any case record.
- **FR-002**: The system MUST deduplicate dispute events: a second event for the same provider dispute reference MUST NOT create a duplicate `PaymentDisputeCase`.
- **FR-003**: Every `PaymentDisputeCase` MUST be linked to the original `PaymentAttempt` by `payment_attempt_id`; events referencing unknown attempts MUST be recorded as `ReconciliationException(UnmatchedDisputeEvent)`.
- **FR-004**: The system MUST record the response deadline (from the provider event) on the case; deadline proximity notifications MUST be triggered within the configured warning window.
- **FR-005**: Financial holds MUST require explicit operator authorisation and MUST NOT create a negative wallet balance; settled/paid-out funds MUST be recorded as a platform receivable instead.
- **FR-006**: The system MUST calculate net unrefunded exposure (disputed amount minus prior confirmed refunds) before applying holds or correcting journals.
- **FR-007**: Domain modules MAY register a `DisputeEntitlementHandlerInterface` for a purpose type; the handler determines the permissible entitlement action (`block_unused`, `consumed_evidence_recorded`, `post_draw_liability`, `no_action`); the system MUST NOT automatically revoke unrelated entitlements.
- **FR-008**: Provider outcome recording (`won`, `lost`, `closed_reversed`) MUST post correcting ledger journal entries via the authoritative ledger module using idempotency keys; journals MUST balance (debits == credits).
- **FR-009**: A `lost` outcome MUST account for any prior confirmed refunds to prevent double financial compensation; the net amount is recorded on the case for audit.
- **FR-010**: A `won` outcome MUST release the hold without posting a consumer debit or altering the original capture record.
- **FR-011**: A contradictory late provider outcome (opposite of a recorded terminal state) MUST be stored as a `LateContradictoryOutcomeEvent`, flag the case for investigation, and trigger an operator alert — no automatic financial adjustment.
- **FR-012**: The system MUST expose admin APIs for listing, filtering, and managing cases with `payment.disputes.view` / `payment.disputes.manage` permissions.
- **FR-013**: The system MUST expose a read-only customer-facing dispute summary API that shows status and disputed amount without revealing provider-internal references, fees, or operator notes.
- **FR-014**: Cases flagged `requires_finance_review` (post-draw raffle, admitted event ticket) MUST require an explicit `finance_reviewed` acknowledgement before closure.
- **FR-015**: All case state transitions, holds, journals, evidence submissions, and closures MUST be recorded in an audit trail with actor identity, timestamp, and correlation ID.
- **FR-016**: The system MUST emit outbox integration events for all significant case lifecycle milestones: `DisputeCaseReceivedEvent`, `DisputeHoldAuthorisedEvent`, `DisputeWonEvent`, `DisputeLostEvent`, `DisputeReversedEvent`, `DisputeLateContradictoryOutcomeEvent`.

### Key Entities

- **PaymentDisputeCase**: Represents a provider-originated reversal, dispute, or chargeback. Attributes: id (UUID v7), payment_attempt_id, provider_dispute_reference (unique per provider), case_type (reversal|dispute|chargeback), status (lifecycle state), disputed_amount_minor, currency_code, reason_code, response_deadline, net_exposure_minor, entitlement_action, exposure_type (hold|settled_funds_receivable), hold_amount_minor, finance_reviewed, liability_exception_type, closed_at.

- **DisputeCaseEvent**: Immutable timeline entry for a case. Attributes: id, dispute_case_id, event_type, actor_id, payload (JSON), occurred_at, correlation_id. Append-only; never updated.

- **DisputeEntitlementHandlerInterface**: Domain contract for entitlement coordination. Methods: `supportedPurposeType(): string`, `handleDisputeEntitlement(string $caseId, string $purposeReference, string $correlationId): DisputeEntitlementResult`.

- **DisputeHold**: Records a financial hold linked to a dispute case. Attributes: id, dispute_case_id, hold_amount_minor, currency_code, settled_funds_receivable (bool), authorised_by, authorised_at, released_at.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: Duplicate provider dispute events are fully rejected without creating duplicate records or financial adjustments in all tested scenarios.
- **SC-002**: All correcting journals posted for `lost` or `reversed` outcomes balance (debits == credits) across 100% of test cases.
- **SC-003**: Net double-compensation guard correctly reduces chargeback journals by prior confirmed refunds in all tested combinations (full refund, partial refund, no prior refund).
- **SC-004**: All `PaymentDisputeCase` state transitions are traceable — each state change has an audit record with actor, timestamp, and correlation ID.
- **SC-005**: Admin case-list API returns results within acceptable latency under normal load (consistent with existing Payment module API SLAs).
- **SC-006**: Domain entitlement coordination correctly routes to registered handlers and gracefully handles missing, timed-out, or error-returning handlers without data loss.
- **SC-007**: All major lifecycle events (`received`, `hold`, `won`, `lost`, `reversed`, `contradictory`) produce outbox events with correct payload within the same database transaction commit boundary.
- **SC-008**: Settled-funds receivable path never produces a negative wallet balance across all tested scenarios.

---

## Assumptions

- P15 is complete and deployed: `RefundService`, `PaymentRefundStatus`, `LedgerModuleContract::postEntry()`, and `ReconciliationExceptionType` are available as prerequisites.
- Provider dispute events are received via the same E25 ingress callback infrastructure used for refund callbacks (P10 inbox dispatcher); P16 does not own the ingress transport layer.
- Financial holds on settlement funds are tracked within the Payment module; settlement fund accounting belongs to the Reconciliation/Settlement module (E20); P16 posts intent-level hold records and notifies the settlement module via events.
- The `DisputeEntitlementHandlerInterface` is registered in the IoC container by domain modules (Events, Raffles); P16 provides the contract and the discovery mechanism, not the domain implementations.
- Provider chargeback fees are fixed per provider and retrievable from the Payment Configuration module (E28); P16 looks them up at outcome recording time and does not pre-commit fee amounts.
- The raffle and event post-draw liability policies are governed by domain-specific contracts external to P16; P16 flags these cases for finance review and documents the runbook reference — it does not implement the liability resolution logic.
- Mobile customers receive a read-only dispute summary; they cannot take action on a dispute (e.g., they cannot submit evidence through the mobile app in this scope).
- The double-compensation guard uses confirmed (`confirmed` or `succeeded`) refunds only; in-flight refunds are excluded from the deduction because they may not complete — this is the conservative (platform-protective) interpretation.
- Response deadline breach does not automatically close a case; operators decide outcome for breached cases.
- AD-16 (bank refund fee policy) is unresolved from P15 and does not block P16; dispute fees are governed by a separate per-provider dispute-fee configuration.
