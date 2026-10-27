# Payment Contract Register

**Version**: 1.0 (P01 — pending architect sign-off)

**Date**: 2026-10-21

**Purpose**: Plain-language semantic invariants for all cross-module interfaces used in the payment domain. Engineers implementing P02–P11 MUST read this before writing any code that touches these interfaces. When in doubt, this register is authoritative — not the PHP source.

---

## Interface Index

| # | Interface | Owner | Callers | Stability |
|---|-----------|-------|---------|-----------|
| 1 | `PaymentModuleContract` | Payment | Business modules | Stable |
| 2 | `PaymentAdapterInterface` | Payment | PaymentOrchestrationService only | Stable |
| 3 | `BusinessPurposeResolverInterface` | Payment | PaymentApplicationService only | Stable |
| 4 | `WalletModuleContract` | Wallet | WalletPaymentAdapter only (from Payment) | Stable — ADR-E48-002 lock |
| 5 | `LedgerModuleContract` | Ledger | Wallet (primary); WalletPaymentAdapter (via delegation) | Stable |

---

## 1. PaymentModuleContract

**Path**: `app/Modules/Payment/Domain/Contracts/PaymentModuleContract.php`

**Rule**: The ONLY interface business modules may use to interact with the Payment domain. No adapter, repository, or orchestration class is permitted to business modules.

### createPaymentIntent

- **What it does**: Creates the lifecycle record for a single payment.
- **Amount rule**: Amount is NEVER provided by the caller. It is resolved server-side by the registered `BusinessPurposeResolverInterface` for the purposeType.
- **Idempotency**: Requires `Idempotency-Key` header. Duplicate call returns existing intent, not a new one.
- **Returns**: intent ID + available payment options.
- **Post-state**: Intent is `pending`. No wallet hold exists yet. No ledger entry exists yet.
- **Throws**: `UnknownPurposeTypeException` if no resolver is registered; `PurposeNotPayableException` if the reference cannot be paid right now.

### getAvailableOptions

- **What it does**: Returns the payment methods the consumer can use for this intent.
- **Side-effect-free**: No state changes.
- **Wallet option**: Appears only if `availableBalance() >= resolved amount`.

### getPaymentStatus

- **What it does**: Returns current `PaymentIntentStatus` + most recent `PaymentAttemptStatus`.
- **Side-effect-free**: No state changes.
- **Auth constraint**: Caller must be the payer actor or an authorised admin.

---

## 2. PaymentAdapterInterface

**Path**: `app/Modules/Payment/Domain/Contracts/PaymentAdapterInterface.php`

**Rule**: Implemented by provider adapters (WalletPaymentAdapter, bank adapters). Called ONLY by `PaymentOrchestrationService`. Business modules may never call adapters directly.

**Key invariant across all methods**: Adapters MUST NOT read or write `payment.*` tables. Adapters MUST NOT modify `PaymentIntent` or `PaymentAttempt` state — that belongs to the orchestration layer.

### initiatePayment

- **Amount rule**: `amountMinor` in the request equals the amount from the stored `PayableObligation` — it is NOT re-derived from consumer input.
- **Wallet adapter behaviour**: Synchronous — places hold, returns `succeeded` in one request.
- **Bank adapter behaviour**: Async — returns `initiated` or `awaiting_action` with a redirect URL.
- **Ledger rule**: Adapter MUST NOT call `LedgerModuleContract::postEntry()`. Ledger posting is the orchestration layer's responsibility after this method returns.
- **Throws**: `InsufficientFundsException` (Wallet), `StepUpRequiredException` (Wallet, above threshold), `CapabilityUnsupportedException` (any adapter lacking InitiatePayment).

### queryPaymentStatus

- **What it does**: Asks the provider for the current status of an attempt.
- **Non-terminal TimedOut**: `PaymentAttemptStatus::TimedOut` is non-terminal — orchestration retries the query.
- **Side-effect on attempt state**: None from the adapter's perspective. Orchestration decides whether to transition the attempt based on the result.

### initiateRefund

- **Compensation rule**: Refunds MUST NOT alter original journal entries. The orchestration layer posts a compensating credit entry after this returns.
- **Idempotency**: Calling twice with the same `refundId` returns the same result.
- **Partial refund**: `refundAmountMinor` may be less than the original captured amount.

### cancelPayment

- **Wallet adapter timing**: Hold is released by the orchestration layer BEFORE this method is called. The adapter only confirms no further action is needed on its side.
- **Non-mutating on intent**: The adapter returns `cancelled: true/false`; the orchestration layer transitions the attempt and intent FSM.

---

## 3. BusinessPurposeResolverInterface

**Path**: `app/Modules/Payment/Domain/Contracts/BusinessPurposeResolverInterface.php`

**Rule**: Each business module (Funeral, Events, Raffle, etc.) registers one implementation. Called ONLY by `PaymentApplicationService` inside the Payment module. Business modules receive the result indirectly — they do not call this interface themselves.

### supportedPurposeType

- **Must be unique**: Two resolvers with the same purpose type is a configuration error. The last registered wins — order-dependent.
- **Must be stable**: Changing a purpose type string requires a data migration (all existing intents store this string).

### resolve

- **Amount authority**: The `amountMinor` in the returned `PayableObligation` is the ONLY authoritative amount. The Payment module MUST use this value; consumer-provided amounts are ignored.
- **Idempotent**: Calling twice with same arguments returns same result.
- **Ownership check**: Resolver MUST verify the `actorId` is permitted to initiate payment for this reference.
- **Throws**: `PurposeNotPayableException` if the reference exists but is not currently payable.

---

## 4. WalletModuleContract (payment-relevant subset)

**Path**: `app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php`

**Rule**: Called from Payment context ONLY via `WalletPaymentAdapter`. Any other Payment class importing `WalletModuleContract` is a violation of ADR-E48-002.

### createHold

- **Atomicity**: Hold creation and its ledger reservation are one atomic operation.
- **Authoritative balance check**: Uses SELECT FOR UPDATE — this is the definitive check, not `availableBalance()`.
- **Idempotency**: Same `transactionReference` returns existing holdId — does not double-hold.
- **Returns**: `holdId` (UUID v7) — store this on the `PaymentAttempt`.

### releaseHold

- **Idempotency**: Releasing an already-released hold is a no-op.
- **Compensation**: Release posts a compensating credit entry via `LedgerModuleContract` internally.
- **Timing**: For cancellations — call this BEFORE calling `PaymentAdapterInterface::cancelPayment()` (WalletPaymentAdapter expects hold already released).

### availableBalance

- **Optimistic only**: Use as a pre-check to present options. Never use as the definitive check — `createHold()` is definitive.
- **Returns null**: If wallet or pocket does not exist for the given currency.

---

## 5. LedgerModuleContract

**Path**: `app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php`

**Rule**: The single authoritative financial ledger. No module maintains an alternative balance store.

### postEntry

- **Must balance**: Every call MUST have lines where sum(debit amounts) == sum(credit amounts). Unbalanced submissions are rejected.
- **Idempotency key**: MUST be provided for all payment-related journal entries. Use `payment_intent_id` or `payment_attempt_id` as the key.
- **Immutability**: Once posted, the entry cannot be edited or deleted. Corrections require a compensating `postEntry()` with opposite directions.
- **Double-entry line format**: Each line has `ledger_account_reference`, `direction` (debit/credit), `amount` (positive integer, minor units).

### entryExists

- **Duplicate check**: Call this before `postEntry()` to implement idempotency without exception handling.
- **Side-effect-free**.

---

## Payout Contract (MISSING — P18)

`ProviderCapabilityEnum::Payout` exists but no `initiatePayout()` method exists on any interface. P18 must define and implement this.

**Open decision**: DEC-04 (P00 delivery register) — payout contract ownership unresolved: options are (a) add `initiatePayout()` to `PaymentAdapterInterface`, (b) create a separate `PayoutAdapterInterface`, or (c) create a new `App\Modules\Payout` module. See ADR-E48-003 AD-14 for the full decision record.

**Blocked P-IDs**: P18, P24, P25, P26, P27, P28

---

## Provider, Provider Account, Instrument, Display Option

| Concept | Definition | Owned by | Lifecycle |
|---------|-----------|----------|-----------|
| **Provider** | A named payment service (Wallet, FNB, Standard Bank, Stanbic, Mock). Has capabilities from `ProviderCapabilityEnum`. | `payment.payment_providers` | Active, Suspended, Deprecated |
| **Provider Account** | Organisation-specific credentials for one provider. Scoped to one organisation. | `payment.payment_provider_accounts` | Active, Suspended |
| **Instrument** | The consumer-level payment vehicle (wallet balance, bank card, mobile money). Not stored directly — derived at query time. | In-memory, per request | Per PaymentIntent |
| **Display Option** | How a method appears to the consumer (label, icon, checkout flow). Derived from Provider Account at query time. | `PaymentProviderOption` value object | Ephemeral |
