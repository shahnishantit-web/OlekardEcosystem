# Payment Financial Vocabulary

**Version**: 1.0 (P01 — pending architect and finance reviewer sign-off)

**Date**: 2026-10-21

**Purpose**: Single authoritative reference for financial lifecycle terms used across the OleKard Shared Payment Architecture. All engineers implementing P02–P33, writing PR descriptions, or commenting on code MUST use these definitions. Vocabulary drift is a financial integrity risk.

---

## Lifecycle Overview

```
Intent CREATED (pending)
    │
    ▼
PaymentAttempt submitted
    │
    ▼ [provider confirms debit]
CAPTURE ── event: none (internal)
    │
    ▼ [LedgerModuleContract::postEntry()]
ACCOUNTING ACKNOWLEDGEMENT ── event: payment.confirmed
    │
    ▼ [domain module marks obligation done]
FULFILMENT ── event: domain-specific (e.g. contribution.fulfilled)
    │
    ▼ [provider batch, async]
SETTLEMENT ── event: settlement.completed (P17)
    │
    ▼ [disbursement to org/beneficiary]
PAYOUT ── event: payout.completed (P18, MISSING)

REFUND / REVERSAL can occur after CAPTURE, as compensating flows.
CANCELLATION can occur before CAPTURE.
```

---

## Capture

**Definition**: The moment an external payment provider reports an authoritative, irreversible debit from the consumer's account or instrument.

**Trigger**: Provider callback verified with HMAC/signature, OR synchronous provider confirmation.

**Evidence requirement**: Verified provider callback body, OR synchronous `PaymentAttemptResult(status: succeeded)` from `WalletPaymentAdapter`. **Redirect arrival alone is NEVER accepted as capture evidence.**

**PaymentAttempt transition**: `processing` / `awaiting_action` → `succeeded`

**Event emitted**: None at capture alone.

**Not to be confused with**:
- **Redirect arrival**: Consumer returns from bank checkout page. This is NOT capture. The bank may not have authorised the payment yet.
- **PaymentIntent.succeeded**: The intent reaches this state only after BOTH capture AND accounting acknowledgement.

---

## Accounting Acknowledgement

**Definition**: The moment the double-entry ledger journal entry for a captured payment is durably posted via `LedgerModuleContract::postEntry()`.

**Trigger**: Follows capture — either in the same synchronous request (WalletPaymentAdapter) or in the payment confirmation handler after a provider callback (bank adapters).

**Evidence requirement**: `LedgerModuleContract::entryExists(idempotency_key)` returns true for the payment's journal entry.

**PaymentIntent transition**: `processing` / `requires_confirmation` → `succeeded`

**Event emitted**: `payment.confirmed` (published AFTER this step — not before)

**Idempotency**: MUST use `payment_intent_id` as the idempotency key for `postEntry()`. Duplicate acknowledgement is a no-op.

**Not to be confused with**:
- **Capture**: Capture is the provider event. Acknowledgement is the internal ledger event. For async providers, they can be separated in time by seconds to minutes.
- **Fulfilment**: Acknowledgement confirms money received. Fulfilment confirms business obligation delivered.

---

## Fulfilment

**Definition**: The domain-specific action that a business module performs after a payment is confirmed — the delivery of the business value (ticket issued, contribution recorded, raffle entry created).

**Trigger**: `payment.confirmed` event received by the business module's event handler.

**Preconditions**: Accounting acknowledgement is complete. `payment.confirmed` event has been published.

**Sequencing**: ALWAYS after accounting acknowledgement. Fulfilment MUST NOT be attempted before the ledger entry exists.

**Evidence**: Business module updates its own domain record (contribution.status = paid, ticket.issued = true, etc.).

**Not to be confused with**:
- **Capture**: Capture is provider-side. Fulfilment is domain-side. They may be seconds to minutes apart.
- **Settlement**: Fulfilment happens per-payment synchronously. Settlement happens per-provider-batch, async.

---

## Refund

**Definition**: A full or partial return of captured funds to the original payer, initiated after a successful payment.

**Trigger**: Business module request, consumer request via support, or admin action.

**Preconditions**: Original `PaymentIntent` is `succeeded`. `PaymentRefund` record created with unique `refundId`. Total refunds ≤ original captured amount.

**PaymentIntent transition**: `succeeded` → `refunding` → `refunded` (full) / `partially_refunded` (partial)

**Ledger treatment**: Compensating credit entry via new `postEntry()` call. **The original journal entry is NEVER modified.**

**Event emitted**: `RefundConfirmedEvent` (after compensating ledger entry posted)

**Not to be confused with**:
- **Reversal**: Reversal is provider/network-initiated. Refund is OleKard-initiated.
- **Cancellation**: Cancellation is before capture. Refund is after capture.

---

## Reversal

**Definition**: A provider-initiated or network-initiated undoing of a capture — typically a chargeback or bank dispute.

**Trigger**: Provider webhook/callback with chargeback or reversal event type, OR admin-initiated reversal (P16 scope).

**Evidence requirement**: Provider reversal notification with provider reference ID matching the original attempt.

**PaymentIntent transition**: `succeeded` → REVERSED (P16 state, MISSING — P16 not yet implemented)

**Ledger treatment**: Compensating entry (same as refund). **Never modify original journal entry.**

**Event emitted**: `PaymentReversedEvent` (class exists; full P16 implementation MISSING)

**Status**: PARTIALLY CONFIRMED — `PaymentReversedEvent` exists; full state machine treatment requires P16.

---

## Settlement

**Definition**: The moment a payment provider transfers net captured funds to OleKard's bank settlement account. A batch event — not per-transaction.

**Trigger**: Provider settlement cycle (daily, weekly — provider-specific). Not triggered by any single payment.

**Key distinction**: **A payment can be `succeeded` in the system while the provider batch settlement is still pending.** These are independent events.

**Evidence requirement**: Provider settlement report or bank statement.

**Reconciliation**: `ReconciliationException` records flag discrepancies between expected and received settlement amounts.

**Event emitted**: Settlement event (P17 scope — PARTIAL implementation)

**Not to be confused with**:
- **Capture**: Capture is per-transaction. Settlement is per-batch.
- **Payout**: Settlement = OleKard receives funds from provider. Payout = OleKard disburses to organisation/beneficiary.

---

## Payout

**Definition**: OleKard disbursing collected, settled funds to an organisation, beneficiary, or agent.

**Trigger**: Business cycle completion (fund closes, event ends, raffle drawn), OR scheduled rule.

**Preconditions**: Funds are settled in OleKard's bank account. Recipient account is verified.

**Contract gap**: `ProviderCapabilityEnum::Payout` exists but `PaymentAdapterInterface` has no `initiatePayout()` method. **P18 is MISSING.** Until P18 is implemented and DEC-04 (payout module ownership) is resolved, payout cannot be processed.

**Status**: UNRESOLVED — blocks P18 and all community features P24–P28.

---

## Cancellation

**Definition**: Termination of a payment intent or attempt BEFORE capture occurs. No money has moved.

**Trigger**: Consumer abandons payment, session expires, admin cancels, intent TTL expires.

**Preconditions**: Intent is `pending`, `processing`, or `awaiting_action`. Capture has NOT occurred.

**PaymentIntent transition**: `pending` / `processing` / `awaiting_action` → `cancelled` or `expired`

**Ledger treatment**: If a wallet hold was placed, `WalletModuleContract::releaseHold()` is called. No new ledger entry for the cancellation itself (the hold release is its compensation).

**Event emitted**: None (no funds moved).

**Not to be confused with**:
- **Refund**: Refund is after capture. Cancellation is before capture.
- **Reversal**: Reversal is provider-initiated. Cancellation is OleKard/consumer-initiated.

---

## Why a Bank Purchase Does Not Top Up the Consumer's OleKard Wallet

**Rule**: A bank payment capture does NOT increase the consumer's OleKard Wallet balance.

**Why**: The OleKard Wallet is a stored-value account. Bank payments are a separate instrument where the consumer pays from their bank account. Bank funds flow to OleKard's merchant bank settlement account (external). The consumer's wallet balance is unchanged.

**Accounting**: A bank capture credits an OleKard settlement receivable ledger account (UNRESOLVED — AD-09 in ADR-E48-003). It does NOT credit the consumer's wallet pocket.

**Consumer wallet can ONLY increase via**: Explicit Funding (E08) — agent cash-in, bank top-up deposit, or incoming transfer. Not via outgoing payments.

**Code evidence**: `AbstractBankPaymentAdapter` and `MockBankPaymentAdapter` do not import `WalletModuleContract`. Bank payment confirmations never reach the Wallet module.
