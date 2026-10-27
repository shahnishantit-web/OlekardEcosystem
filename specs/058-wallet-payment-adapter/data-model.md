# Data Model: P09 — OleKard Wallet Payment Adapter

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md) | [plan.md](plan.md)

P09 introduces **no new database tables or schema migrations**. All persistence is owned by the Wallet and Payment modules via their existing contracts. This document describes the domain entities, value objects, and exception types involved in P09 implementation.

---

## New Domain Exception

### `Payment\TransactionLimitExceededException`

**Path**: `app/Modules/Payment/Domain/Exceptions/TransactionLimitExceededException.php`

**Ownership**: Payment module

**Purpose**: Thrown by `WalletPaymentAdapter::initiatePayment()` when the requested payment amount exceeds the per-currency transaction limit configured in Platform Configuration.

| Field | Type | Description |
|-------|------|-------------|
| `amountMinor` | `int` | The attempted payment amount (minor units) |
| `limitMinor` | `int` | The configured ceiling (minor units) |
| `currencyCode` | `string` | e.g., `BWP` |

**Extends**: `\DomainException`

**Note**: Distinct from `Wallet\TransactionLimitExceededException` (Wallet-domain; thrown inside `createHold()` for wallet-internal limits). The Payment adapter enforces Payment-module policy limits before calling `createHold()`.

---

## Existing Entities (read-only by adapter)

### `PaymentInitiateRequest` (Value Object)

**Path**: `app/Modules/Payment/Domain/ValueObjects/PaymentInitiateRequest.php`

No changes. The adapter reads these fields:

| Field | Type | Used In |
|-------|------|---------|
| `walletId` | `?string` | Null guard + all wallet contract calls |
| `payerActorId` | `string` | Ownership check (`ownerActorId`) |
| `currencyCode` | `string` | `pocketExists`, `availableBalance`, `createHold` |
| `amountMinor` | `int` | Balance check, limit check, `createHold` |
| `paymentAttemptId` | `string` | `createHold` transactionReference (idempotency key) |
| `correlationId` | `string` | `createHold`, audit |
| `paymentIntentId` | `string` | `StepUpRequiredException` context |
| `stepUpToken` | `?string` | Step-up validation |
| `sessionId` | `?string` | Step-up token binding |
| `idempotencyKey` | `string` | Passed through (not used directly by adapter) |
| `operationId` | `?string` | Not used by adapter (orchestration-layer concern) |

### `PaymentAttemptResult` (Value Object)

**Path**: `app/Modules/Payment/Domain/ValueObjects/PaymentAttemptResult.php`

No changes. P09 populates these fields on the happy path:

| Field | Value set by adapter |
|-------|---------------------|
| `status` | `PaymentAttemptStatus::Succeeded` |
| `feeMinor` | `0` (wallet payments carry no provider fee) |
| `walletHoldId` | UUID v7 hold ID returned by `WalletModuleContract::createHold()` |
| `ledgerJournalId` | `null` (set by orchestration layer after P08 `postLedgerAcknowledgement`) |
| `providerReferenceId` | `null` (wallet is synchronous; no external reference) |

### `PaymentRefundRequest` (Value Object)

**Path**: `app/Modules/Payment/Domain/ValueObjects/PaymentRefundRequest.php`

No changes. Fields read by adapter's `initiateRefund()`:

| Field | Used For |
|-------|---------|
| `refundId` | Audit record resource ID |
| `amountMinor` | Audit record (partial refund amount) |
| `currencyCode` | Audit record |
| `correlationId` | Audit record |

### `PaymentRefundResult` (Value Object)

**Path**: `app/Modules/Payment/Domain/ValueObjects/PaymentRefundResult.php`

No changes. Adapter always returns `status: PaymentRefundStatus::Succeeded`.

### `PaymentCancelResult` (Value Object)

**Path**: `app/Modules/Payment/Domain\ValueObjects/PaymentCancelResult.php`

No changes. Adapter returns `cancelled: true`.

---

## Existing Wallet Domain Exceptions (used by adapter)

These are thrown through the adapter without wrapping — they are already part of the established cross-module exception vocabulary:

| Exception | Path | When thrown |
|-----------|------|------------|
| `WalletNotTransactableException` | `Wallet\Domain\Exceptions\` | `isActive()` returns false — wallet is frozen/suspended |
| `UnsupportedCurrencyException` | `Wallet\Domain\Exceptions\` | `pocketExists()` returns false — no pocket for this currency |
| `InsufficientBalanceException` | `Wallet\Domain\Exceptions\` | `createHold()` authoritative check fails |

These are re-exported as documented in `PaymentAdapterInterface::initiatePayment()` throws:
- `InsufficientFundsException` (Payment domain) — thrown by adapter's optimistic check
- `StepUpRequiredException` (Payment domain) — thrown by adapter's step-up check
- `TransactionLimitExceededException` (Payment domain — NEW) — thrown by adapter's limit check

---

## Configuration Keys (read by adapter)

| Config Key | Type | Default | Purpose |
|-----------|------|---------|---------|
| `auth.step_up_threshold` | `int` (minor units) | `10000` (BWP) | Step-up authentication ceiling |
| `auth.step_up_threshold_currency` | `string` | `BWP` | Currency for threshold |
| `payment.wallet_transaction_limit_{currencyCode}` | `int\|null` (minor units) | `null` (no limit) | Per-currency spending ceiling for wallet payments |

All keys are read via `ConfigurationServiceInterface::getOrDefault()`. Absent keys use the stated defaults. No hard-coded values in adapter logic.

---

## State Machine (existing — no changes)

`WalletPaymentAdapter` is synchronous. The state progression for a wallet payment attempt is:

```
[created] → initiatePayment() → Succeeded (hold placed)
                             ↘ InsufficientFundsException (no state change)
                             ↘ StepUpRequiredException (no state change)
                             ↘ TransactionLimitExceededException (no state change — NEW)
                             ↘ WalletNotTransactableException (no state change)
                             ↘ UnsupportedCurrencyException (no state change)
```

Hold states (owned by Wallet domain, not Payment adapter):
```
Hold created → (orchestration captures) → Hold consumed / released
            → (expires_at reached) → Hold expired → release required
```

The adapter does not manage hold lifecycle beyond creation. `cancelPayment()` defers hold release to the orchestration layer. `initiateRefund()` does not interact with hold state (hold is already consumed at refund time).
