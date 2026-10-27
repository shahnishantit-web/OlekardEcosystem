# Research: P15 — Shared Refund Engine

**Date**: 2026-10-24
**Verified against**: live codebase (commit-time inspection)

---

## Decision 1: Domain Eligibility Hook Interface

**Decision**: Introduce a new `RefundEligibilityHandlerInterface` (separate from `BusinessPurposeResolverInterface`) registered by domain modules via the service container under `payment.refund_eligibility.{purpose_type}`. `RefundService::request()` resolves the handler by purpose type; if none is registered, the refund is permitted by default (opt-in model).

**Rationale**: `BusinessPurposeResolverInterface` is for resolving a payable obligation (one-time, at checkout). Refund eligibility is a distinct lifecycle event — the domain module must confirm the entitlement has not been consumed. Creating a separate interface preserves the single-responsibility of the existing contract, avoids breaking P04 implementations, and allows domain modules to add eligibility support incrementally.

**Alternatives considered**:
- Adding `isRefundEligible()` to `BusinessPurposeResolverInterface` — rejected because it breaks all existing implementations and conflates payment quoting with refund eligibility.
- Calling the domain module's HTTP API directly — rejected because it violates module boundary principles; cross-module calls must use registered contracts.

---

## Decision 2: ProviderCapabilityEnum — Which Enum to Use for Refunds

**Decision**: Use `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum::FullRefund` and `PartialRefund` for refund capability checks inside `RefundService`. This is the 8-case Payment-domain enum. The Platform enum (`App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum`) with `ReversePriorPayment` is used only by `WalletPaymentAdapter` (P09 scope). These two enums are intentionally distinct; P15 does NOT merge them — that is an architect decision (AD-07, UNRESOLVED).

**Rationale**: The existing `RefundService::resolveAdapter()` already wraps adapters through `PaymentCapabilities::refund()` which uses the Payment-domain capability namespace. The existing test `CapabilityValidationTest` uses the Payment-domain enum. Consistency requires continuing with that enum.

**Risk documented**: Until AD-07 is resolved, a bank adapter implementing `WalletPaymentAdapter`-style `ReversePriorPayment` instead of `FullRefund`/`PartialRefund` would fail the refund capability check silently. P15 adds a test that asserts the adapter under test declares `FullRefund` or `PartialRefund` (not `ReversePriorPayment`) to surface this risk.

---

## Decision 3: Refund Accounting Status Storage

**Decision**: Add a `refund_accounting_status` column (varchar 64, nullable) to `payment_refunds` table rather than extending `PaymentAccountingStatus` enum on `payment_intents`. Valid values: `pending_ledger_refund`, `ledger_refund_posted`, `pending_ledger_refund_fee_blocked`.

**Rationale**: The existing `PaymentAccountingStatus` enum on `payment_intents` tracks the payment (original direction) accounting. Refund accounting is a separate lifecycle concern — adding refund states to the existing payment enum creates ambiguity about which direction `pending_ledger` refers to. Storing it on `payment_refunds` keeps the two concerns cleanly separated and requires no changes to `PaymentIntent` or its existing repair job.

**Alternatives considered**:
- New `RefundAccountingStatus` PHP enum — valid, but a simple varchar with CHECK constraint is sufficient for now; an enum can be introduced when the states stabilise.
- Extending `PaymentAccountingStatus` — rejected because it would confuse the existing `RepairPendingAccountingJob` which scans `pending_ledger`.

---

## Decision 4: In-Flight Reservation Query Pattern

**Decision**: Add `sumReservedRefundsForAttempt(string $attemptId): int` to `PaymentRefundRepositoryInterface`. The Eloquent implementation uses `SELECT SUM(amount_minor) ... WHERE payment_attempt_id = ? AND status NOT IN ('succeeded', 'failed', 'cancelled', 'rejected', 'definitive_failure')` with a `FOR UPDATE SKIP LOCKED` on the `payment_refunds` rows to prevent lost-update races.

**Rationale**: The existing `sumConfirmedRefundsForAttempt()` only counts `succeeded` rows — this is the confirmed gap. The new method counts all non-terminal rows (i.e., all amounts that are either already returned or still in flight). Using `FOR UPDATE` at the application layer aligns with the existing pattern in `RefundService::request()`.

**Alternatives considered**:
- Adding a DB trigger — rejected; Constitution prohibits business logic in triggers.
- Advisory locks (pg_advisory_lock) — overkill for a row-level concern; `FOR UPDATE` is sufficient.

---

## Decision 5: `PaymentRefundStatus::Requested` Alignment

**Decision**: Retain `Requested` as the initial state written to the DB. The `request()` method will set status = `Requested` first (within the transaction), then immediately transition to either `PendingApproval` or `Processing` in the same transaction based on the approval threshold. This brings the enum case into actual use and documents the true entry point.

**Rationale**: `Requested` exists in the enum but the service skips it — this is inconsistency between the enum's state machine and actual usage. Using `Requested` as the momentary initial state before routing makes the DB audit trail complete (the state = requested, then = pending_approval is a two-row history). If that is operationally noisy, alternative is to remove `Requested` from the enum entirely.

**Alternative**: Remove `Requested` from enum and start at `PendingApproval` or `Processing` — simpler but removes the initial record of the request event. Decision: use `Requested` + immediate transition to keep full audit trail.

---

## Decision 6: Bank Refund Callback Routing via P11 Inbox

**Decision**: `BankRefundCallbackHandler` is registered as a P11 inbox consumer for events of type `bank.refund.callback`. The existing `PaymentCallbackModel` (P10) is reused to store the verified callback receipt. The handler matches the callback to a `PaymentRefund` by `provider_submitted_ref` (stored when the refund is submitted to the bank), then advances the state.

**Rationale**: P10/P11 already provide the verified, deduplicated, durable ingress infrastructure. Reusing it avoids creating a second callback ingress path. The `PaymentCallbackModel` provides the evidence record required by FR-007 (P10 FR04).

**Alternatives considered**:
- A direct HTTP webhook endpoint for bank refund confirmations — rejected; this would bypass the P10 verification and deduplication layer.
- Polling-only (no callback) — rejected; P10 already provides callback infrastructure; refund callbacks arriving via the same bank channel should use the same verified path.

---

## Decision 7: Mobile API Scope

**Decision**: `GET /v1/customer/payments/{intentId}/refunds` returns a list of refunds for the intent, each with: `id`, `status`, `status_label` (human-readable), `amount_minor`, `currency_code`, `requested_at`, `confirmed_at` (nullable), `funds_returned` (bool). This endpoint is read-only; mobile NEVER drives state transitions.

**Rationale**: Mobile apps need to display refund progress. The `funds_returned` boolean prevents the app from showing "funds returned" for non-terminal or pending states (SC-003). The endpoint reuses the existing JWT auth middleware; `organisation_id` and `actor_id` are derived server-side from the token.

---

## Decision 8: `QueryRefundStatusJob` Scheduling

**Decision**: `QueryRefundStatusJob` is dispatched with a configurable delay (default 5 minutes, config key `payment.refund_query_interval_seconds`). It calls `adapter->queryRefund(refundId)` and advances the state only if the provider returns a definitive outcome. If the response remains pending, the job reschedules itself up to a configurable max retries (default 24 attempts = 2 hours). After max retries, it transitions the refund to `Unknown` and emits an operator alert.

**Rationale**: Configurable delay avoids hammering the provider API. Self-rescheduling avoids a separate scheduled task infrastructure. Transition to `Unknown` after timeout is per LC11: "UNKNOWN refund execution keeps its amount reserved."
