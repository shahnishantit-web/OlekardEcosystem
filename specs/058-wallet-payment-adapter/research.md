# Research: P09 — OleKard Wallet Payment Adapter

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md) | [plan.md](plan.md)

All decisions below are derived from the existing codebase, ADR-E48-002, `docs/architecture/payment-contract-register.md`, and completed P06–P08 implementation. No external research required — all unknowns are resolved from repository evidence.

---

## Decision 1: Validation Ordering in `initiatePayment()`

**Decision**: Apply validation gates in the following order:
1. `walletId !== null` (invariant guard)
2. `isActive(walletId)` (wallet must be operable)
3. `ownerActorId(walletId) === payerActorId` (ownership)
4. `pocketExists(walletId, currencyCode)` (currency support)
5. `availableBalance(walletId, currencyCode) >= amountMinor` (optimistic balance)
6. Transaction limit check via `ConfigurationServiceInterface` (spending ceiling)
7. Step-up authentication check (if amount >= configured threshold)
8. `createHold()` — authoritative SELECT FOR UPDATE balance enforcement

**Rationale**: Fail fast on cheapest checks first. Wallet-exists checks (`isActive`, `ownerActorId`) are fast lookups. Pocket existence avoids a spurious balance check for unsupported currencies. The optimistic balance check eliminates most insufficient-funds attempts before the SELECT FOR UPDATE. The transaction limit check prevents oversized amounts before the hold. Step-up is last before `createHold()` because it requires session/identity validation (network/crypto cost). `createHold()` is always the authoritative financial gate — it cannot be bypassed.

**Alternatives considered**:
- Check `pocketExists` after balance check: rejected — a pocket-not-found case would wrongly return `availableBalance() = null` (which is 0), producing a confusing `InsufficientFunds` error when the real issue is missing pocket.
- Skip `isActive()` check and rely on `createHold()` throwing `WalletNotTransactableException`: acceptable, but loses the early-exit benefit and produces a less specific error message to the orchestration layer.
- Check transaction limit inside `createHold()`: rejected — `createHold()` is a Wallet-domain method; limits configured in the Payment module should be enforced by the Payment adapter, not injected into the Wallet domain.

---

## Decision 2: Transaction Limit Config Key Pattern

**Decision**: Read per-currency transaction limit from `ConfigurationServiceInterface::getOrDefault('payment.wallet_transaction_limit_{currencyCode}', null)`. If the key is absent or null, no limit is applied. If present, cast to `int` (minor units). If `amountMinor > limit`, throw `Payment\TransactionLimitExceededException`.

**Rationale**: Consistent with the existing step-up threshold pattern (`auth.step_up_threshold`). The namespace `payment.wallet_transaction_limit_*` avoids collision with wallet-domain limits. Returning `null` (no limit) is the safe default — it does not block payments when ops haven't configured a limit.

**Alternatives considered**:
- Single universal limit across currencies: rejected — BWP 5,000 and USD 5,000 are very different economic limits.
- Per-actor or per-organisation limit: deferred — this would require a more complex lookup and is beyond P09 scope. A per-currency global ceiling is sufficient for this iteration.
- Reuse `Wallet\TransactionLimitExceededException`: rejected — violates domain boundaries (Principle 7). The Payment adapter must throw Payment-domain exceptions, not Wallet-domain exceptions, to keep module boundaries extraction-ready.

---

## Decision 3: `TransactionLimitExceededException` Placement

**Decision**: Create `App\Modules\Payment\Domain\Exceptions\TransactionLimitExceededException` in the Payment module. Constructor: `(int $amountMinor, int $limitMinor, string $currencyCode)`. Extends `\DomainException`.

**Rationale**: `App\Modules\Wallet\Domain\Exceptions\TransactionLimitExceededException` already exists but belongs to the Wallet domain. Importing it in the Payment adapter would create a cross-module dependency that violates ADR-E48-002 (adapter delegates through contracts, not exceptions from another domain).

**Alternatives considered**:
- Reuse `RefundLimitExceededException`: rejected — wrong semantic; refund limits are different from spending limits.
- Reuse `InsufficientFundsException`: rejected — the amount IS available, but the policy prevents spending it; different caller intent.

---

## Decision 4: Crash-After-Hold Recovery Semantics

**Decision**: No additional recovery code required in the adapter. `WalletModuleContract::createHold()` is idempotent by `transactionReference` (= `paymentAttemptId`). A retry with the same `paymentAttemptId` returns the same hold ID without a second deduction. The adapter's crash recovery is therefore automatic — retrying `initiatePayment()` with the same `paymentAttemptId` recovers the original result.

**Rationale**: `payment-contract-register.md` Section 4, `createHold` contract: "Idempotency: Same `transactionReference` returns existing holdId — does not double-hold." The wallet domain guarantees this at the database level (SELECT FOR UPDATE + unique constraint on `transactionReference`). No adapter-side persistence is needed.

**Alternatives considered**:
- Store hold ID in Redis for fast idempotency replay: rejected — redundant given wallet-side idempotency; adds operational complexity (Redis loss → inconsistency).
- Re-query `PaymentAttemptModel::wallet_hold_id` on retry: possible but belongs to orchestration layer logic, not the adapter.

---

## Decision 5: `initiateRefund()` Boundary — Hold Release Responsibility

**Decision**: The adapter's `initiateRefund()` MUST NOT call `WalletModuleContract::releaseHold()`. By the time a refund is initiated (post-capture via P08 `LedgerPosted`), the hold has already been consumed. The hold lifecycle ends at capture; there is no hold to release during a refund. The orchestration layer (via `RefundService`) posts the compensating credit journal entry via `LedgerModuleContract`.

**Evidence**:
- `payment-contract-register.md` Section 2, `initiateRefund`: "Compensation rule: Refunds MUST NOT alter original journal entries. The orchestration layer posts a compensating credit entry after this returns."
- Constitution Principle 4: "Reversals use compensating entries."
- Existing `WalletPaymentAdapter::initiateRefund()` already does not call `releaseHold()` — this decision confirms the existing implementation is correct.

**Alternatives considered**:
- Adapter calls `releaseHold()` during refund: rejected — hold is already consumed post-capture; calling `releaseHold()` on a consumed hold would produce an error or no-op depending on Wallet implementation; the responsibility split is wrong.
- Orchestration releases hold AND then calls adapter: rejected — hold release is a Wallet operation done by the orchestration layer for cancellations (pre-capture); for refunds (post-capture) there is no hold.

---

## Decision 6: Inactive Wallet Error Path

**Decision**: Add `$this->wallet->isActive($walletId)` as the second check (after null guard). If `false`, throw `App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException` directly — do not wrap it into a Payment domain exception. The orchestration layer already handles `WalletNotTransactableException` as a known failure type.

**Rationale**: `WalletNotTransactableException` is already declared in the `PaymentAdapterInterface::initiatePayment()` throw-set. The orchestration layer expects it. Creating a separate `WalletInactiveException` in the Payment domain adds a class without adding information.

**Alternatives considered**:
- Skip `isActive()` and let `createHold()` throw naturally: acceptable; chosen to add explicit check for faster failure and clearer message before attempting hold creation.
- Wrap in a new `Payment\WalletNotOperableException`: rejected — adds boilerplate with no benefit since `WalletNotTransactableException` is already the established exception type in this pathway.

---

## Decision 7: `WalletPaymentAdapterTest` Constructor Bug Fix

**Decision**: The existing `WalletPaymentAdapterTest` (PHP 8.4 code) instantiates `WalletPaymentAdapter` with 4 constructor arguments but the constructor requires 5 (`stepUpVerifier` is missing). P09 must fix this test by adding `$this->createMock(ActorStepUpVerifierContract::class)` as the 5th argument.

**Rationale**: PHP 8.4 requires all non-default constructor parameters. The missing argument would cause `ArgumentCountError` on test execution. This is a pre-existing bug discovered during P09 research.

**Alternatives considered**:
- Make `stepUpVerifier` nullable in the constructor: rejected — step-up verification is a required security gate; nullable would allow it to be bypassed unintentionally.

---

## Decision 8: Pocket-Not-Found Error Type

**Decision**: If `pocketExists(walletId, currencyCode)` returns false, throw `App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException` — this already exists in the Wallet domain and semantically matches "this wallet does not support this currency."

**Rationale**: `UnsupportedCurrencyException` is the established Wallet-domain exception for pocket-less currency operations (used in `addCurrencyPocket`). Re-using it in the adapter maintains semantic consistency. No new Payment-domain exception is needed for this case.

**Alternatives considered**:
- `CapabilityUnsupportedException` (Payment domain): semantically wrong — the capability (wallet debit) is supported; the specific currency isn't on this wallet.
- New `Payment\CurrencyPocketNotFoundException`: adds a class without adding semantic value over `UnsupportedCurrencyException`.

---

## Pre-existing Implementation Status

| Component | Status | Gap |
|-----------|--------|-----|
| `WalletPaymentAdapter::initiatePayment()` | PARTIAL | Missing: `isActive()`, `pocketExists()`, transaction limit check |
| `WalletPaymentAdapter::queryPaymentStatus()` | COMPLETE | No changes needed |
| `WalletPaymentAdapter::initiateRefund()` | COMPLETE | Semantically correct; needs test coverage |
| `WalletPaymentAdapter::cancelPayment()` | COMPLETE | No changes needed |
| `WalletPaymentAdapter::getCapabilities()` | COMPLETE | No changes needed |
| `WalletPaymentAdapterTest` | PARTIAL | Bug: missing `stepUpVerifier` arg; missing pocket/limit/inactive tests |
| `WalletPaymentConcurrencyTest` | PARTIAL | Missing: real concurrent overspend scenario |
| `TransactionLimitExceededException` (Payment) | MISSING | Must create |
