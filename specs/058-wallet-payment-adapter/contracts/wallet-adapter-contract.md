# Adapter Contract: P09 — WalletPaymentAdapter

**Date**: 2026-10-21
**Feature**: [spec.md](../spec.md) | [plan.md](../plan.md)
**Interface**: `App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface`
**Implementation**: `App\Modules\Payment\Infrastructure\Adapters\WalletPaymentAdapter`
**ADR**: ADR-E48-002 — Wallet Payment Adapter Delegation Pattern

---

## Invariants (non-negotiable, from ADR-E48-002)

1. `WalletPaymentAdapter` is the **only** class in `App\Modules\Payment` that calls `WalletModuleContract`.
2. The adapter **never** writes to `payment.*` database tables directly.
3. The adapter **never** calls `LedgerModuleContract::postEntry()` (ledger posting is the orchestration layer's responsibility — P08).
4. `WalletModuleContract` is not modified by P09 (ADR-E48-002 lock).

---

## Method: `initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult`

### Validation Gate (ordered)

| Step | Check | Exception on Failure |
|------|-------|---------------------|
| 1 | `$request->walletId !== null` | `\InvalidArgumentException` |
| 2 | `wallet->isActive($walletId) === true` | `Wallet\WalletNotTransactableException` |
| 3 | `wallet->ownerActorId($walletId) === $request->payerActorId` | `\DomainException` ("Wallet does not belong to actor") |
| 4 | `wallet->pocketExists($walletId, $currencyCode) === true` | `Wallet\UnsupportedCurrencyException` |
| 5 | `wallet->availableBalance($walletId, $currencyCode) >= $request->amountMinor` | `Payment\InsufficientFundsException` |
| 6 | `$request->amountMinor <= config('payment.wallet_transaction_limit_{currencyCode}')` (if configured) | `Payment\TransactionLimitExceededException` |
| 7 | Step-up check if `$request->amountMinor >= auth.step_up_threshold` | `Payment\StepUpRequiredException` |
| 8 | `wallet->createHold(...)` — authoritative SELECT FOR UPDATE balance check | `Wallet\InsufficientBalanceException` (propagates) |

### Success Path

1. All validation gates pass.
2. `wallet->createHold(walletId, currencyCode, amountMinor, payerActorId, correlationId, transactionReference: paymentAttemptId)` is called.
3. Audit record emitted: `payment.wallet.hold_placed`.
4. Returns `PaymentAttemptResult(status: Succeeded, feeMinor: 0, walletHoldId: holdId)`.

### Idempotency Contract

- `paymentAttemptId` is used as `transactionReference` in `createHold()`.
- Same `paymentAttemptId` → same `holdId` returned by `createHold()` (Wallet domain guarantee).
- Calling `initiatePayment()` twice with the same `paymentAttemptId` results in exactly one hold on the wallet pocket.

### What This Method Does NOT Do

- Does NOT post a ledger entry.
- Does NOT update `PaymentAttempt` state (orchestration layer does this after return).
- Does NOT retry on `InsufficientBalanceException` from `createHold()`.
- Does NOT resolve `walletId` from `payerActorId` — orchestration layer resolves this before calling.

---

## Method: `queryPaymentStatus(string $providerReferenceId): PaymentStatusResult`

**`providerReferenceId`** = `paymentAttemptId` for wallet payments.

- Reads `PaymentAttemptModel` via `attemptRepository->findById($providerReferenceId)`.
- Returns `PaymentStatusResult(status: attempt.status ?? Failed, providerReferenceId: $providerReferenceId)`.
- No external calls. No side effects.

**No changes required in P09** — implementation is correct and complete.

---

## Method: `initiateRefund(PaymentRefundRequest $request): PaymentRefundResult`

### Contract

- Called by orchestration layer **after** hold has been consumed (post-capture / `LedgerPosted`).
- The adapter MUST NOT call `WalletModuleContract::releaseHold()` — the hold no longer exists at refund time.
- Emits audit record: `payment.wallet.refund_initiated`.
- Returns `PaymentRefundResult(status: Succeeded)`.

### Idempotency Contract

- Same `refundId` called twice → returns `Succeeded` both times.
- The compensating journal (credit to consumer pocket) is posted by the orchestration layer via `LedgerModuleContract::postEntry()` using `refundId` as idempotency key.

### What This Method Does NOT Do

- Does NOT post a ledger entry.
- Does NOT call `releaseHold()`.
- Does NOT validate refund amount against original captured amount (orchestration layer responsibility — `RefundService`).

### Partial Refund Support

- `$request->amountMinor` may be less than the original payment amount.
- The adapter accepts any non-zero positive amount and returns `Succeeded`.
- Amount validation and ceiling enforcement are the orchestration layer's responsibility.

---

## Method: `cancelPayment(string $providerReferenceId): PaymentCancelResult`

**`providerReferenceId`** = `paymentAttemptId` for wallet payments.

- The orchestration layer calls `WalletModuleContract::releaseHold($holdId)` BEFORE calling this method.
- The adapter verifies the attempt exists but does not release the hold itself.
- Returns `PaymentCancelResult(cancelled: true)` regardless of whether hold existed.

**No changes required in P09** — implementation is correct and complete.

---

## Declared Capabilities

| Capability | Supported |
|-----------|-----------|
| `InitiatePayment` | ✅ |
| `QueryTransactionStatus` | ✅ |
| `ReversePriorPayment` | ✅ |
| `QueryBalance` | ❌ |
| `DeliverOtp` | ❌ |

---

## Exception Taxonomy (P09 state)

| Exception | Domain | Thrown By |
|-----------|--------|----------|
| `Payment\InsufficientFundsException` | Payment | Adapter (optimistic pre-check) |
| `Payment\StepUpRequiredException` | Payment | Adapter (step-up gate) |
| `Payment\TransactionLimitExceededException` | Payment | Adapter (limit gate) — **NEW in P09** |
| `Wallet\WalletNotTransactableException` | Wallet | Adapter (isActive gate) + propagated from `createHold()` |
| `Wallet\UnsupportedCurrencyException` | Wallet | Adapter (pocketExists gate) |
| `Wallet\InsufficientBalanceException` | Wallet | Propagated from `createHold()` (authoritative check) |
| `\InvalidArgumentException` | PHP | Adapter (null walletId guard) |
| `\DomainException` | PHP | Adapter (ownership mismatch) |

---

## Test Coverage Matrix

| Scenario | Test Type | File |
|----------|-----------|------|
| Null walletId throws `InvalidArgumentException` | Unit | `WalletPaymentAdapterTest` |
| Inactive wallet throws `WalletNotTransactableException` | Unit | `WalletPaymentAdapterTest` |
| Wrong owner throws `DomainException` | Unit | `WalletPaymentAdapterTest` |
| Missing pocket throws `UnsupportedCurrencyException` | Unit | `WalletPaymentAdapterTest` |
| Insufficient balance throws `InsufficientFundsException` | Unit | `WalletPaymentAdapterTest` |
| Amount above limit throws `TransactionLimitExceededException` | Unit | `WalletPaymentAdapterTest` |
| Below threshold, no step-up needed | Unit | `WalletPaymentAdapterTest` |
| At/above threshold, no token → `StepUpRequiredException` | Unit | `WalletPaymentAdapterTest` |
| At/above threshold, valid token → hold placed | Unit | `WalletPaymentAdapterTest` |
| Same `paymentAttemptId` retry → one hold only | Unit | `WalletPaymentAdapterTest` |
| `initiateRefund` → Succeeded, no `releaseHold` call | Unit | `WalletPaymentAdapterTest` |
| `cancelPayment` → `cancelled: true` | Unit | `WalletPaymentAdapterTest` |
| Concurrent same-pocket two-request overspend → balance non-negative | Feature | `WalletPaymentConcurrencyTest` |
| Concurrent same-attempt retry → exactly one hold | Feature | `WalletPaymentConcurrencyTest` |
