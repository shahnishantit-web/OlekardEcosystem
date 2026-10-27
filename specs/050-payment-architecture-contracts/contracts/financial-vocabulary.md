# Financial Vocabulary Glossary

**Version**: 1.0 (P01 draft — pending architect and finance reviewer sign-off)

**Date**: 2026-10-21

**Purpose**: Single authoritative reference for financial lifecycle terms. All P02–P11 specifications, PR descriptions, and code comments MUST use these definitions. Vocabulary drift is a financial integrity risk.

---

## Lifecycle Sequence

The full payment lifecycle in sequential order:

```
PaymentIntent created (pending)
    ↓
PaymentAttempt submitted → CAPTURE (external provider confirms debit)
    ↓
ACCOUNTING ACKNOWLEDGEMENT (LedgerModuleContract::postEntry())
    ↓
payment.confirmed event emitted
    ↓
FULFILMENT (domain module marks obligation complete)
    ↓
[async, not per-transaction] SETTLEMENT (funds clear to OleKard bank account)
    ↓
[per business cycle] PAYOUT (OleKard disburses to organisation / beneficiary)
```

**Refund** and **Reversal** can occur at various points after capture. See terms below.

---

## Term 1: Capture

| Field | Value |
|-------|-------|
| **Definition** | The moment an external payment provider reports an authoritative, irreversible debit from the consumer's account or instrument. |
| **Trigger** | Provider callback or synchronous provider query returns a status that means "funds confirmed deducted". |
| **Preconditions** | A `PaymentAttempt` exists in processing or awaiting_action state; a provider reference ID has been issued. |
| **Evidence requirement** | Provider callback body verified with HMAC/signature verification (bank adapters), OR `WalletPaymentAdapter` creates a hold atomically (wallet capture). Redirect arrival alone is NEVER accepted as capture evidence. |
| **PaymentAttempt transition** | `processing` / `awaiting_action` → `succeeded` |
| **PaymentIntent transition** | `processing` → `requires_confirmation` (if confirmation needed) or stays in `processing` |
| **Event emitted** | None at capture alone — `payment.confirmed` is emitted only after accounting acknowledgement. |
| **Status** | Confirmed |
| **Not to be confused with** | Redirect arrival (the consumer returns from a bank checkout page — this is NOT capture); `PaymentIntentStatus.succeeded` (that requires accounting acknowledgement too) |

---

## Term 2: Accounting Acknowledgement

| Field | Value |
|-------|-------|
| **Definition** | The moment the double-entry ledger journal entry for a captured payment is durably posted via `LedgerModuleContract::postEntry()`. |
| **Trigger** | Successful capture — either within the same synchronous request (Wallet adapter) or in the payment confirmation handler after a provider callback. |
| **Preconditions** | Capture has occurred (PaymentAttempt is `succeeded`). The intent is in `processing` or `requires_confirmation`. |
| **Evidence requirement** | `LedgerModuleContract::entryExists(idempotency_key)` returns true for the payment's journal entry. |
| **PaymentIntent transition** | `processing` / `requires_confirmation` → `succeeded` |
| **Event emitted** | `payment.confirmed` (after this step completes — not before) |
| **Idempotency** | MUST be idempotent: the `postEntry()` call uses the `payment_intent_id` as idempotency key. If the entry already exists, the acknowledgement is treated as already complete. |
| **Status** | Confirmed |
| **Not to be confused with** | Capture (capture is the provider event; accounting acknowledgement is the internal ledger event — they can be separated in time for async providers) |

---

## Term 3: Fulfilment

| Field | Value |
|-------|-------|
| **Definition** | The domain-specific action that the business module performs after a payment is confirmed — the delivery of the business value (ticket issued, contribution recorded, raffle entry created). |
| **Trigger** | The `payment.confirmed` event, consumed by the relevant `BusinessPurposeResolverInterface` handler in the business module. |
| **Preconditions** | `payment.confirmed` event has been received; accounting acknowledgement is complete. |
| **Evidence requirement** | The business module updates its own domain record (e.g., contribution status = paid). |
| **Sequencing** | ALWAYS after accounting acknowledgement. Fulfilment MUST NOT be attempted before the ledger entry exists. |
| **Event emitted** | Domain-specific event (e.g., `contribution.fulfilled`, `ticket.issued`) — outside Payment module scope |
| **Status** | Confirmed |
| **Not to be confused with** | Capture (capture is provider-side; fulfilment is domain-side — they may be seconds or minutes apart); Settlement (fulfilment happens per-payment; settlement happens per provider batch) |

---

## Term 4: Refund

| Field | Value |
|-------|-------|
| **Definition** | A full or partial return of captured funds to the original payer, initiated after a successful payment and corresponding accounting acknowledgement. |
| **Trigger** | Business module request via `PaymentModuleContract` (e.g., dispute resolution, cancelled event), or admin action. |
| **Preconditions** | Original `PaymentIntent` is `succeeded`. `PaymentRefund` is created with a valid `refundId`. The total of all refunds for the intent MUST NOT exceed the original captured amount. |
| **Evidence requirement** | Provider confirms refund (WalletPaymentAdapter: hold release + compensating ledger entry; bank adapter: provider refund confirmation). |
| **PaymentIntent transition** | `succeeded` → `refunding` → `refunded` (full) or `partially_refunded` (partial) |
| **Ledger treatment** | Compensating credit entry — NEVER modifies the original journal entry. A new `postEntry()` call with opposite directions. |
| **Event emitted** | `RefundConfirmedEvent` (after ledger compensating entry is posted) |
| **Status** | Confirmed |
| **Not to be confused with** | Reversal (reversal is provider-initiated; refund is OleKard-initiated); Cancellation (cancellation is before capture; refund is after capture) |

---

## Term 5: Reversal

| Field | Value |
|-------|-------|
| **Definition** | A provider-initiated or network-initiated undoing of a capture, typically triggered by the provider (card network chargeback, bank dispute). |
| **Trigger** | Provider callback with a chargeback or reversal event type, OR admin-initiated reversal via dispute resolution (P16 scope). |
| **Preconditions** | Original capture occurred. Provider has the capability (`ProviderCapabilityEnum::CancelPayment` or chargeback notification). |
| **Evidence requirement** | Provider reversal notification with provider reference ID matching the original attempt. |
| **PaymentIntent transition** | `succeeded` → REVERSED (new state, P16 scope) — MISSING: P16 is not yet implemented |
| **Ledger treatment** | Same as refund: compensating entry. NEVER modify the original journal entry. |
| **Event emitted** | `PaymentReversedEvent` (already exists in repository) |
| **Status** | PARTIALLY CONFIRMED — `PaymentReversedEvent` exists, but the full state machine treatment of reversals (P16) is MISSING |
| **Blocking condition** | P16 must be implemented before reversal flows are complete |

---

## Term 6: Settlement

| Field | Value |
|-------|-------|
| **Definition** | The moment a payment provider transfers net captured funds to OleKard's bank settlement account. Settlement is a batch event, not a per-transaction event. |
| **Trigger** | Provider settlement cycle (daily, weekly — provider-specific). Not triggered by any individual payment. |
| **Preconditions** | One or more captures have occurred in the settlement period for the organisation's provider account. |
| **Evidence requirement** | Provider settlement report or bank statement matching the expected net amount. |
| **Important distinction** | Settlement is SEPARATE from and LATER THAN capture and accounting acknowledgement. A payment can be `succeeded` in the system with accounting acknowledged while the provider batch settlement is still pending. |
| **Event emitted** | Settlement event (P17 scope — PARTIAL implementation) |
| **Reconciliation** | `ReconciliationException` records are created when the settled amount does not match expected captured amounts. |
| **Status** | Confirmed (concept); PARTIAL (P17 implementation) |
| **Not to be confused with** | Capture (capture is per-transaction; settlement is per-batch); Payout (settlement is receipt from provider; payout is OleKard disbursing to organisation/beneficiary) |

---

## Term 7: Payout

| Field | Value |
|-------|-------|
| **Definition** | The act of OleKard disbursing collected, settled funds to an organisation, beneficiary, or agent. |
| **Trigger** | Business cycle completion (e.g., funeral fund closes, event ends, raffle draw completed) OR scheduled disbursement rule. |
| **Preconditions** | Funds are settled in OleKard's bank account. Payout recipient is verified and their account is known. The business domain has confirmed the payout trigger condition. |
| **Evidence requirement** | Payout provider confirmation (external bank transfer confirmation, mobile money confirmation). |
| **Contract gap** | `ProviderCapabilityEnum::Payout` exists but `PaymentAdapterInterface` has no payout method. This is the MISSING payout contract (DEC-04, P18). |
| **Module ownership (UNRESOLVED)** | It is unresolved whether payout is: (a) a new method on `PaymentAdapterInterface`, (b) a separate `PayoutAdapterInterface` inside the Payment module, or (c) owned by a new Payout module. Owner: Architect. |
| **Status** | UNRESOLVED — P18 scope; blocks all community features P24–P28 |

---

## Term 8: Cancellation

| Field | Value |
|-------|-------|
| **Definition** | Termination of a payment intent or attempt before capture occurs. No money has moved; no ledger entries have been posted. |
| **Trigger** | Consumer abandons payment, session expires, admin cancels, or intent TTL expires. |
| **Preconditions** | Intent is in `pending`, `processing`, or `awaiting_action` status. Capture has NOT yet occurred. |
| **Evidence requirement** | No provider confirmation needed — internal state transition only. For Wallet adapter: ensure hold is released. |
| **PaymentIntent transition** | `pending` / `processing` / `awaiting_action` → `cancelled` or `expired` |
| **Ledger treatment** | If a wallet hold was placed: `WalletModuleContract::releaseHold()` is called. No ledger entry for the cancellation itself (the hold reservation was a ledger entry; the release is its compensation). |
| **Event emitted** | None (no funds moved) |
| **Status** | Confirmed |
| **Not to be confused with** | Refund (refund is after capture; cancellation is before capture); Reversal (reversal is provider-initiated; cancellation is OleKard/consumer-initiated) |

---

## Why a Bank Purchase Does Not Top Up the Consumer's OleKard Wallet

**Rule**: A bank payment capture does NOT increase the consumer's OleKard Wallet balance.

**Explanation**:

The OleKard Wallet is a stored-value account — the consumer's wallet balance represents funds they have deposited or received within the OleKard system. A bank payment is a separate instrument where the consumer pays from their bank account directly.

When a consumer pays via bank (FNB, Standard Bank, etc.):
1. The bank captures funds from the consumer's bank account.
2. Those funds flow to OleKard's merchant bank settlement account (external).
3. The consumer's OleKard Wallet balance is UNCHANGED.
4. The accounting entry for a bank capture credits an OleKard settlement receivable ledger account (UNRESOLVED — FD05/FD06 — exact account TBD pending architecture document).

When a consumer pays via OleKard Wallet:
1. `WalletPaymentAdapter` calls `WalletModuleContract::createHold()` — this reduces available balance.
2. On capture/confirmation, the hold is converted to a ledger debit against the consumer's wallet pocket.
3. The credit goes to an organisation suspense/receivable account.

**The consumer's wallet balance can ONLY increase through explicit Funding operations** (E08: agent cash-in, bank top-up, incoming transfer). Bank payments from wallet to a merchant are debits, not credits.

**Evidence in code**: `WalletPaymentAdapter` is the ONLY adapter that calls `WalletModuleContract`. `AbstractBankPaymentAdapter` and `MockBankPaymentAdapter` do not import or call `WalletModuleContract`. A bank payment confirmation never reaches the Wallet module.
