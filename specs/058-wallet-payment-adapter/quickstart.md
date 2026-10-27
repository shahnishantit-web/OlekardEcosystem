# Quickstart: P09 — OleKard Wallet Payment Adapter

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md) | [plan.md](plan.md) | [contracts](contracts/wallet-adapter-contract.md)

Runnable validation scenarios for P09. All unit tests run without Docker. Feature tests require Docker (`docker compose up -d api db`).

---

## Prerequisites

```powershell
# From wallet-ecosystem/services/api/
composer install

# Docker required for feature tests (SC-03, SC-08 feature scenarios)
# docker compose up -d api db redis
```

---

## SC-01: Validation Gates — Unit Tests (no Docker)

**Goal**: Every validation failure returns the correct exception type; happy path places exactly one hold.

```powershell
# From wallet-ecosystem/services/api/
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php --verbose
```

**Expected**: All 12+ unit tests PASS. No `ArgumentCountError` (stepUpVerifier constructor bug fixed).

**Scenarios verified**:
| Scenario | Spec SC | Expected |
|----------|---------|----------|
| null walletId | SC-01 | `InvalidArgumentException` |
| inactive wallet | SC-01 | `WalletNotTransactableException` |
| wrong owner | SC-01 | `DomainException` |
| missing pocket | SC-01 | `UnsupportedCurrencyException` |
| insufficient balance (optimistic) | SC-01 | `InsufficientFundsException` |
| amount above transaction limit | SC-01 | `TransactionLimitExceededException` |
| below step-up threshold, no token | SC-05 | hold placed, `Succeeded` |
| at threshold, no token | SC-05 | `StepUpRequiredException` |
| above threshold, valid token | SC-05 | hold placed, `Succeeded` |
| above threshold, wrong actor token | SC-05 | `StepUpRequiredException` |
| same `paymentAttemptId` retry | SC-02 | `createHold` called once, same holdId |
| `initiateRefund` | SC-04 | `Succeeded`, `releaseHold` NOT called |

---

## SC-02: Idempotent Retry — Unit Test (no Docker)

**Goal**: Retrying `initiatePayment()` with the same `paymentAttemptId` after a crash returns the same hold without a second deduction.

```powershell
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php \
  --filter test_idempotent_retry_returns_same_hold_id
```

**Expected**: `createHold` is called exactly once even after two `initiatePayment` calls with the same `paymentAttemptId`. Second call returns the same `holdId`.

---

## SC-03: Concurrent Overspend Protection — Feature Test (Docker required)

**Goal**: Two simultaneous `initiatePayment` calls for the same wallet pocket, where combined amounts exceed available balance, result in exactly one succeeded payment and one failed. Wallet balance never goes negative.

```powershell
# Requires Docker
docker compose exec api php artisan test \
  tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php --verbose
```

**Expected**: All concurrent tests PASS. `WalletPaymentConcurrencyTest::test_concurrent_overspend_does_not_drain_balance_below_zero` asserts balance >= 0 after both attempts resolve.

---

## SC-04: Refund Contract — Unit Test (no Docker)

**Goal**: `initiateRefund()` returns `Succeeded` without calling `releaseHold()`. Idempotent on second call.

```powershell
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php \
  --filter test_initiate_refund
```

**Expected**: `WalletModuleContract::releaseHold` is never called. Return status is `Succeeded`. Calling twice with same `refundId` returns `Succeeded` both times.

---

## SC-05: Step-Up Threshold Respects Configuration — Unit Test (no Docker)

**Goal**: Step-up threshold changes take effect per configuration value; hard-coded values must not exist.

```powershell
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php \
  --filter test_step_up_threshold_is_configurable
```

**Expected**: Test configures threshold at 5,000 BWP; payment of 6,000 BWP requires step-up; payment of 4,999 BWP does not.

---

## SC-06: Audit Trail — Unit Test (no Docker)

**Goal**: Every `initiatePayment()` success and every `initiateRefund()` emits an audit record.

```powershell
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php \
  --filter test_audit_record_emitted_on_hold_placement
```

**Expected**: `AuditServiceInterface::record()` is called exactly once per successful `initiatePayment()` with action `payment.wallet.hold_placed`, and once per `initiateRefund()` with action `payment.wallet.refund_initiated`.

---

## SC-07: PHPStan Clean (Docker or local with Composer)

**Goal**: Zero PHPStan level 8 errors in the Payment module after P09 changes.

```powershell
# From wallet-ecosystem/services/api/
./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8
```

**Expected**: `[OK] No errors`.

If errors appear, they are in the new `TransactionLimitExceededException` class or modified `WalletPaymentAdapter`. Fix before marking T-PHPStan complete.

---

## SC-08: Full Unit + Feature Test Run (Docker for feature tests)

```powershell
# Unit tests only (no Docker needed)
./vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php

# Feature tests (Docker required)
docker compose exec api php artisan test \
  tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php \
  --stop-on-failure
```

**Expected pass counts**:
- Unit: 12+ tests PASS (0 failures, 0 errors)
- Feature: 3+ tests PASS (including new concurrent overspend scenario)

---

## Scenario Summary

| SC | Description | Requires Docker | Pass Condition |
|----|-------------|----------------|---------------|
| SC-01 | Validation gates | No | All 12 unit tests pass |
| SC-02 | Idempotent retry | No | createHold called once |
| SC-03 | Concurrent overspend | Yes | Balance non-negative |
| SC-04 | Refund contract | No | Succeeded, no releaseHold |
| SC-05 | Step-up configurable | No | Threshold from config |
| SC-06 | Audit trail | No | record() called |
| SC-07 | PHPStan clean | No (local Composer) | 0 errors |
| SC-08 | Full test run | Yes (feature) | All pass |
