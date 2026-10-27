# Contract Register: Cross-Module Interfaces

**Version**: 1.0 (P01 draft — pending architect sign-off)

**Date**: 2026-10-21

**Scope**: All cross-module interfaces that touch the Payment, Wallet, or Ledger domains.

**Rule**: A module may call another module's interface only when it appears in that interface's `Permitted callers` list. Interface method signatures may not be changed without an approved ADR. Business logic belongs in domain services, not in these interfaces.

---

## 1. PaymentModuleContract

**Owner module**: `App\Modules\Payment`

**PHP path**: `app/Modules/Payment/Domain/Contracts/PaymentModuleContract.php`

**Stability**: Stable — method removal requires ADR

**Permitted callers**: Funding, Transfer (for split-wallet payments), Qr, Service, Support (dispute-triggered refunds), all future community modules

**Purpose**: The ONLY interface business modules may use to initiate, query, or receive payment capabilities. No module may use PaymentAdapterInterface or repository interfaces directly.

---

### Method: `createPaymentIntent(PaymentIntentRequest $request): array`

**Summary**: Creates a payment intent — the record that will track the full lifecycle of a payment.

**Preconditions**:
- `purposeType` maps to a registered `BusinessPurposeResolverInterface` implementation
- `purposeReference` is a valid reference understood by that resolver
- `actorId` is an authenticated actor with a valid session
- An `Idempotency-Key` MUST accompany the HTTP request (enforced by API layer)
- Amount is resolved server-side by the purpose resolver — not passed by caller

**Postconditions**:
- A `PaymentIntent` record exists with status `pending`
- The resolved `PayableObligation` (amount, currency, organisation) is stored on the intent
- Returns array including `payment_intent_id` and available payment `options`

**Invariants**:
- Amount is NEVER taken from the calling module; it is resolved by `BusinessPurposeResolverInterface::resolve()`
- idempotent: calling twice with the same Idempotency-Key returns the existing intent, not a new one
- A pending intent does not affect wallet balance — no hold is placed until `PaymentAttempt` is submitted

**Error conditions**:
- `UnknownPurposeTypeException`: no resolver registered for the given purposeType
- `PurposeNotPayableException`: resolver exists but the reference is not currently payable (e.g., already paid, cancelled)

---

### Method: `getAvailableOptions(OptionsQuery $query): array`

**Summary**: Returns the payment methods available for a given actor and amount.

**Preconditions**:
- `actorId` is a known customer with a verified wallet (for Wallet option to appear)
- `organisationId` is active with at least one configured provider account

**Postconditions**:
- Returns list of `PaymentProviderOption` entries; each includes provider code, display label, and instrument details
- Wallet option appears only if consumer has sufficient `availableBalance()`

**Invariants**:
- Options list is non-mutating — calling it has no side effects
- Options are derived from active `PaymentProviderAccount` records for the organisation

---

### Method: `getPaymentStatus(string $paymentIntentId, string $actorId): array`

**Summary**: Returns the current status of a payment intent.

**Preconditions**:
- `paymentIntentId` is a valid UUID v7 of an existing intent
- `actorId` is the payer actor or an authorised admin actor

**Postconditions**:
- Returns current `PaymentIntentStatus` plus `PaymentAttemptStatus` for the most recent attempt
- Does not trigger any state changes

**Invariants**:
- Read-only — no side effects
- Returns status even for terminal states (succeeded, failed, cancelled, expired)

---

## 2. PaymentAdapterInterface

**Owner module**: `App\Modules\Payment` (the interface); each adapter is an implementation

**PHP path**: `app/Modules/Payment/Domain/Contracts/PaymentAdapterInterface.php`

**Stability**: Stable — new adapters implement it; interface itself evolves slowly

**Permitted callers**: `PaymentOrchestrationService` only (inside Payment module). Business modules MUST NOT call adapters directly.

**Purpose**: Provider-specific execution boundary. Each payment provider (OleKard Wallet, Bank1, Bank2, Bank3, Mock) implements this. The orchestration layer calls adapters without knowing provider-specific logic.

---

### Method: `initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult`

**Summary**: Tells a specific provider to initiate a payment attempt.

**Preconditions**:
- `paymentIntentId` and `paymentAttemptId` are valid, existing IDs
- `amountMinor` is the amount resolved from `PayableObligation` — must equal the intent's recorded amount
- For Wallet adapter: `walletId` must belong to `payerActorId`
- For bank adapters: redirect URLs or checkout parameters must be provided

**Postconditions**:
- Returns `PaymentAttemptResult` with `status` (initiated, succeeded for synchronous adapters)
- For WalletPaymentAdapter: status is immediately `succeeded`; `walletHoldId` is set
- For async adapters (bank): status is `initiated` or `awaiting_action`

**Invariants**:
- MUST NOT call `LedgerModuleContract::postEntry()` directly — ledger posting is done by the orchestration layer after this returns
- MUST NOT read or write to `payment.*` tables — those belong to the orchestration layer
- For Wallet adapter: `createHold()` is called here; ledger posting occurs after `payment.confirmed`

**Error conditions**:
- `InsufficientFundsException`: Wallet adapter; available balance < requested amount
- `StepUpRequiredException`: Wallet adapter; amount exceeds step-up threshold and no valid token provided
- `CapabilityUnsupportedException`: provider does not support `InitiatePayment`

---

### Method: `queryPaymentStatus(string $providerReferenceId): PaymentStatusResult`

**Summary**: Asks the provider for the current status of a previously initiated attempt.

**Preconditions**: `providerReferenceId` was returned by a prior `initiatePayment()` call

**Postconditions**: Returns `PaymentStatusResult` with current `PaymentAttemptStatus`

**Invariants**:
- Read-only from the orchestration perspective — does not change attempt state
- For `timed_out` status: non-terminal; orchestration layer retries query

---

### Method: `initiateRefund(PaymentRefundRequest $request): PaymentRefundResult`

**Summary**: Tells the provider to issue a refund against a previously succeeded attempt.

**Preconditions**:
- Original attempt status is `succeeded`
- `refundAmountMinor` ≤ total amount of original attempt minus previous refunds

**Postconditions**: Returns `PaymentRefundResult` with `status` (succeeded, failed)

**Invariants**:
- MUST NOT alter original ledger entries — refund uses compensating credit entries
- MUST NOT double-refund: idempotent — calling twice with same `refundId` returns same result
- For Wallet adapter: hold release is handled by `RefundService`; this method confirms the adapter's side

---

### Method: `cancelPayment(string $providerReferenceId): PaymentCancelResult`

**Summary**: Cancels a non-terminal payment attempt.

**Preconditions**: Attempt is in a cancellable state (initiated, awaiting_action, processing)

**Postconditions**: Returns `PaymentCancelResult` with `cancelled: true` (or `false` if not possible)

**Invariants**:
- For Wallet adapter: hold is released by the orchestration layer BEFORE calling this method
- MUST NOT set attempt status — orchestration layer handles FSM transition

---

## 3. BusinessPurposeResolverInterface

**Owner module**: `App\Modules\Payment` (the interface); each business module provides implementation

**PHP path**: `app/Modules/Payment/Domain/Contracts/BusinessPurposeResolverInterface.php`

**Stability**: Stable — business modules register implementations via service provider

**Permitted callers**: `PaymentApplicationService` inside Payment module. No other callers.

**Purpose**: Domain-registered handler that resolves a business reference (e.g., "funeral contribution ID 42") into a `PayableObligation` (amount, currency, organisation, payer). This is how Payment knows the correct amount without business modules passing it directly.

---

### Method: `supportedPurposeType(): string`

**Summary**: Returns the string key this resolver handles (e.g., `'funeral_contribution'`).

**Preconditions**: None

**Postconditions**: Returns a non-empty string that is unique across all registered resolvers

**Invariants**: The returned string MUST be unique — if two resolvers return the same string, the last registered wins (order-dependent). Purpose type strings are stable identifiers; changing one requires a migration.

---

### Method: `resolve(string $purposeReference, string $actorId, string $correlationId): PayableObligation`

**Summary**: Given a business object reference, returns the amount, currency, organisation, and payer that the payment system must collect.

**Preconditions**:
- `purposeReference` is a valid ID for the concrete business object (e.g., a contribution ID)
- `actorId` is the actor requesting payment (used to validate ownership)

**Postconditions**:
- Returns `PayableObligation` with: `amountMinor` (positive integer, minor units), `currencyCode`, `organisationId`, `payerActorId`, `metadata`
- `amountMinor` is always server-side resolved — NEVER taken from consumer input

**Invariants**:
- MUST be idempotent: calling twice with same arguments returns same result
- Amount in `PayableObligation` is the authoritative amount for the payment intent — it MUST NOT be overridden by the Payment module or by the calling client

**Error conditions**:
- `PurposeNotPayableException`: reference exists but is not currently payable (already paid, cancelled, etc.)

---

## 4. WalletModuleContract (payment-relevant methods)

**Owner module**: `App\Modules\Wallet`

**PHP path**: `app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php`

**Stability**: Stable (ADR-E48-002 prohibits modifications by E48)

**Permitted callers (from Payment)**: `WalletPaymentAdapter` ONLY. No other Payment class may call this.

---

### Method: `createHold(walletId, currencyCode, amount, actorId, correlationId, transactionReference, ?expiresAt): string`

**Summary**: Places a hold on a wallet pocket, reserving the amount from the available balance. Returns a `holdId`.

**Preconditions**:
- `walletId` belongs to `actorId`
- `availableBalance(walletId, currencyCode) >= amount`
- No duplicate hold for the same `transactionReference` (idempotent)

**Postconditions**:
- `availableBalance()` decreases by `amount`
- Ledger entry is posted recording the reservation (via Wallet's internal call to LedgerModuleContract)
- Returns `holdId` (UUID v7)

**Invariants**:
- Atomic: hold creation and ledger debit are a single transaction
- MUST NOT be called outside `WalletPaymentAdapter` from within the Payment module

---

### Method: `releaseHold(holdId, correlationId): void`

**Summary**: Releases a previously created hold, restoring the amount to the available balance.

**Preconditions**: `holdId` exists and has not already been released

**Postconditions**:
- `availableBalance()` increases by the held amount
- Compensating ledger credit is posted

**Invariants**:
- Idempotent: releasing an already-released hold is a no-op
- MUST NOT directly mutate balance — releases via internal hold-release mechanism and ledger compensating entry

---

### Method: `availableBalance(walletId, currencyCode): ?int`

**Summary**: Returns the current spendable balance (total minus holds) in minor units, or null if wallet/pocket does not exist.

**Preconditions**: None

**Postconditions**: Returns integer minor units ≥ 0, or null

**Invariants**: Read-only. Optimistic pre-check only — `createHold()` is the authoritative check with SELECT FOR UPDATE.

---

## 5. LedgerModuleContract (payment-relevant methods)

**Owner module**: `App\Modules\Ledger`

**PHP path**: `app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php`

**Stability**: Stable

**Permitted callers (from Payment context)**: `WalletPaymentAdapter` (via delegation from Wallet). Direct calls from Payment orchestration layer require architectural review.

---

### Method: `postEntry(array $request): string`

**Summary**: Posts a double-entry journal entry. Returns the journal entry ID.

**Preconditions**:
- `$request` contains `ledger_account_reference`, `lines[]` (each with `ledger_account_reference`, `direction`, `amount`)
- Lines MUST balance: sum of all `debit` amounts == sum of all `credit` amounts
- `idempotency_key` MUST be present for financial mutations (Constitution Principle 4)

**Postconditions**:
- Journal entry is durably committed
- All affected ledger account balances are updated atomically
- Returns `entryId` (UUID v7)

**Invariants**:
- Immutable after posting: no `updateEntry()` or `deleteEntry()` method exists
- Reversals use a separate compensating `postEntry()` call with opposite debit/credit directions
- `entryExists(idempotency_key)` can be called first to check for duplicate prevention

**Financial constraint**: This is the single authoritative ledger. No other storage mechanism may record authoritative balances. "One authoritative ledger" — Constitution Principle 4.

---

## 6. Payout Contract (AWAITING_DECISION — P18)

**Status**: MISSING. The `ProviderCapabilityEnum::Payout` case exists in the Payment domain enum, but no corresponding method exists on `PaymentAdapterInterface`.

**Required definition** (P18 scope):

```
PayoutAdapterInterface (or PaymentAdapterInterface extension):
  Method: initiatePayout(PayoutRequest $request): PayoutResult
  Method: queryPayoutStatus(string $providerReferenceId): PayoutStatusResult
```

**Blocking condition**: DEC-04 (payout module ownership) must be resolved. The architect must decide whether payout is a new method on `PaymentAdapterInterface` or a separate `PayoutAdapterInterface`. This decision requires the architecture document.

**Recorded as**: UNRESOLVED — DEC-04 — owner: Architect — blocks P18 and all community features P24–P28.

---

## Provider, Provider Account, Instrument, Display Option — Definitions

**Provider**: A named external payment service (OleKard Wallet, FNB, Standard Bank, Stanbic, Mock). Has capabilities (from `ProviderCapabilityEnum`), a lifecycle (active/suspended/deprecated), and organisation-level accounts.

**Provider Account**: Organisation-specific credentials and configuration for one provider. Owned by `payment.payment_provider_accounts`. Multiple organisations can use the same provider through separate accounts.

**Instrument**: The payment vehicle at the consumer level (wallet balance, Visa card, FNB mobile money). An instrument is tied to a specific consumer and a specific provider. The Payment module represents instruments via `PaymentProviderOption` value objects.

**Consumer Display Option**: How a payment method appears in the UI — display name, icon reference, checkout flow type (redirect, in-app, OTP). Owned by `PaymentProviderOption::displayLabel` and `BankCheckoutFlow` enum. Display options are derived from active Provider Accounts at query time — they are not a stored entity.
