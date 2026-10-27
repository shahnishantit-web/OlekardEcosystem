# Research: P16 — Reversals, Disputes and Chargebacks

**Date**: 2026-10-24
**Status**: Complete — no unresolved NEEDS CLARIFICATION items

---

## Decision 1: Single Unified Entity or Separate Tables

**Decision**: One `PaymentDisputeCase` table covers reversals, disputes, and chargebacks. A `case_type` enum (`reversal` | `dispute` | `chargeback`) distinguishes the three.

**Rationale**: All three share the same lifecycle (received → under_review → terminal), the same hold/exposure mechanism, the same ledger correcting-journal pattern, and the same domain entitlement coordination. Separate tables would duplicate all columns and require parallel service code paths. Using one table with a discriminator is the precedent set by the existing `ReconciliationExceptionType` enum pattern in the Payment module.

**Alternatives considered**: Separate `PaymentReversal`, `PaymentDispute`, `PaymentChargeback` tables were rejected because 90% of fields and all lifecycle logic would be duplicated. A polymorphic union (STI) was rejected because PostgreSQL CHECK constraints on `case_type` cleanly partition the small differences.

---

## Decision 2: Append-Only Timeline vs Versioned State Columns

**Decision**: Use an append-only `DisputeCaseEvent` table for all case actions. The `PaymentDisputeCase` table stores only current state and aggregate values (status, hold_amount_minor, net_exposure_minor, etc.); all actions are stored in the timeline.

**Rationale**: Constitution Principle 4 — historical financial records must not be edited. The append-only timeline is the pattern already established by `DisputeCaseEvent` in the spec. The current state columns on the case itself allow efficient filtering without full timeline replay.

**Alternatives considered**: Storing all state in the timeline (event-sourcing) was rejected because it would require replaying all events to answer queries like "show me all under_review cases" — unacceptable for a list API with filtering.

---

## Decision 3: DisputeEntitlementHandlerInterface — IoC Discovery

**Decision**: Domain modules register `DisputeEntitlementHandlerInterface` implementations in the IoC container keyed by purpose type: `payment.dispute_entitlement.{purpose_type}`. The `DisputeService` resolves the handler at runtime using `app()->has('payment.dispute_entitlement.'.$purposeType)`. This is identical to the `RefundEligibilityHandlerInterface` pattern from P15.

**Rationale**: Keeps the Payment module decoupled from domain modules (Events, Raffles). Domain modules bind their handler in their own ServiceProvider without modifying Payment code. The pattern is already validated by P15's `RefundEligibilityHandlerInterface`.

**Alternatives considered**: Event-driven entitlement coordination (publish event, wait for domain response) was rejected because it requires synchronous request/reply which adds complexity. A direct service-locator registry (like `BusinessPurposeResolverRegistry`) was considered but the IoC pattern is simpler and already proven.

---

## Decision 4: Financial Hold Mechanism

**Decision**: A `DisputeHold` record is created with `settled_funds_receivable = false` for holds on available funds, and `settled_funds_receivable = true` when funds are already settled. The `DisputeHold` is scoped to `PaymentDisputeCase`. No wallet balance is modified; the hold is communicated to the Reconciliation/Settlement module via `DisputeHoldAuthorisedEvent` so it can reduce eligible settlement funds. This prevents the Payment module from directly owning or mutating settlement balances (which belong to the Reconciliation module, E20).

**Rationale**: Constitution Principle 7 — domain boundaries are explicit. The Payment module does not own settlement fund accounting; it notifies the Reconciliation module which adjusts the eligible settlement balance. The receivable path (for already-settled funds) requires a `DisputeHold` record with `settled_funds_receivable = true` — the actual receivable accounting belongs to a Finance/Accounting module outside P16 scope.

**Alternatives considered**: Direct wallet balance hold was rejected per spec boundary: "No unauthorised wallet clawback." Settlement fund hold as a direct debit was rejected because P16 is payment domain, not settlement domain.

---

## Decision 5: Correcting Journal Idempotency Keys

**Decision**: 
- Chargeback/reversal correcting journal: idempotency key `dispute:{caseId}:chargeback`
- Provider chargeback fee journal: idempotency key `dispute:{caseId}:fee`
- Hold release journal (if applicable): idempotency key `dispute:{caseId}:hold_release`

These mirror the P15 refund pattern (`refund:{refundId}`). The Ledger module's `postEntry()` deduplicates on idempotency key.

**Rationale**: Constitution Principle 4 — state-changing financial APIs must be idempotent. Using `caseId` as the anchor with discriminating suffixes ensures each type of journal can be retried safely.

---

## Decision 6: Contradictory Outcome Handling

**Decision**: When a contradictory outcome arrives (e.g., provider sends `won` after a `lost` was recorded, or vice versa), the system stores a `LateContradictoryOutcomeEvent` in the timeline, moves the case to `investigation_required` status, and triggers an operator alert via the notification module. No automatic financial adjustment is made. The case can be manually resolved to a terminal state by a finance operator.

**Rationale**: Per LC12: "A late contradictory provider outcome remains an explicit investigated event." Automatic reversal of already-posted journals would violate financial integrity. The `investigation_required` state is a non-terminal, non-actionable holding state that prevents further automated actions.

---

## Decision 7: Double-Compensation Guard

**Decision**: When recording a `lost` outcome, the `DisputeService` queries `sumConfirmedRefundsForAttempt()` (existing on `PaymentRefundRepositoryInterface`) to get the total of confirmed refunds against the same attempt. The net chargeback amount = `disputed_amount_minor - prior_confirmed_refunds_minor`. If net <= 0, no chargeback journal is posted. The prior refund total and net amount are recorded on the case for audit.

**Rationale**: Per LC12: "Check prior refunds to avoid double financial compensation." This is the same authoritative sum used by P15's refund limit guard. Using `confirmed` refunds only (not in-flight) is the conservative interpretation — in-flight refunds may not complete, so they cannot offset a definitive chargeback.

---

## Decision 8: Permissions

**Decision**: Three new permissions are added to the Payment module permission catalogue:
- `payment.disputes.view` — list and read dispute cases and timelines
- `payment.disputes.manage` — authorise holds, submit evidence, record outcomes, close cases
- `payment.disputes.finance_review` — acknowledge finance review for liability-exception cases

**Rationale**: Operator-authorised hold and finance review are sensitive financial operations that need separate gating from read access. The existing `payment.refunds.manage` permission is not reused to maintain clear audit separation between refund actions and dispute actions.

---

## Decision 9: E25 Ingress Integration

**Decision**: Provider dispute/chargeback events are received via the existing E25 inbox dispatcher (`InboxDispatcher`) using the same callback ingress infrastructure as bank refund callbacks (P10/P15). A new event type `bank.dispute_received` is added to the inbox routing. `BankDisputeCallbackHandler` (new in P16) processes these inbox events.

**Rationale**: E25 owns common external ingress/connector infrastructure (per scope boundary). P16 does not own the transport layer — it registers a handler. This mirrors the P15 bank refund callback pattern (`BankRefundCallbackHandler`).

---

## Open Decisions (Deferred, Non-Blocking for P16)

- **AD-17** (chargeback fee accounting): Which accounts to debit/credit when posting provider chargeback fees. Pending Finance team input. P16 posts a fee journal only when fee amount > 0 is included in the provider outcome payload; if AD-17 is unresolved at posting time, the fee entry is deferred with `accounting_status = pending_chargeback_fee_policy`. This mirrors the AD-16 pattern from P15.
- **Post-draw raffle liability resolution procedure**: The `post_draw_liability` flag and `requires_finance_review` gate are implemented; the actual liability calculation procedure is domain-specific and owned by the Raffles module outside P16.
