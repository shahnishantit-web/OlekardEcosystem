# Implementation Plan: P09 — OleKard Wallet Payment Adapter

**Branch**: `058-wallet-payment-adapter` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/058-wallet-payment-adapter/spec.md`

---

## Summary

P09 completes the `WalletPaymentAdapter` — the single implementation of `PaymentAdapterInterface` for internal OleKard Wallet payments. The adapter delegates exclusively to `WalletModuleContract` (ADR-E48-002). Three gaps from the P00 delivery register must be closed: (1) pocket/currency existence and transaction-limit validation before `createHold()`; (2) idempotent retry proof (same `paymentAttemptId` → same hold, no double-debit); (3) refund contract boundary clarified and tested. No new schema migrations are required. No new wallet module methods are required. `TransactionLimitExceededException` is a new Payment-domain exception. A new concurrent-overspend feature test and fix to the existing unit test (missing `stepUpVerifier` mock) are also in scope.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11)

**Primary Dependencies**:
- `App\Modules\Wallet\Domain\Contracts\WalletModuleContract` — delegation target for all wallet operations
- `App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface` — interface being implemented
- `App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface` — step-up threshold + transaction limits
- `App\Modules\Platform\Domain\Contracts\AuditServiceInterface` — audit trail
- `App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract` — step-up token validation

**Storage**: No new tables or migrations. Reads existing wallet holds via `WalletModuleContract` method calls; reads attempt state via `PaymentAttemptRepositoryInterface`.

**Testing**: PHPUnit (Laravel) — Unit tests (`tests/Unit/Modules/Payment/Adapters/`) and Feature tests (`tests/Feature/Modules/Payment/`)

**Target Platform**: Laravel 11 modular-monolith API backend (`wallet-ecosystem/services/api/`)

**Project Type**: Backend adapter layer (single class + exceptions + tests)

**Performance Goals**: `initiatePayment()` end-to-end (including `createHold()`) < 500 ms p95 under normal load

**Constraints**:
- `WalletModuleContract` MUST NOT be modified (ADR-E48-002 lock)
- Adapter MUST NOT call `LedgerModuleContract::postEntry()` (P08 owns ledger posting)
- Adapter MUST NOT read/write `payment.*` tables directly
- All financial operations MUST be idempotent
- No float arithmetic — integer minor units throughout

**Scale/Scope**: Single adapter class (`WalletPaymentAdapter.php`) plus one new exception + two test files modified

---

## Constitution Check

| Principle | Requirement | Status |
|-----------|------------|--------|
| P2 — TBD Governance | No invented values; FD05/LC04 AWDOC recorded | ✅ PASS |
| P3 — Configuration | Transaction limit and step-up threshold configurable, not hard-coded | ✅ PASS |
| P4 — Financial Integrity | No direct balance mutation; idempotency via `paymentAttemptId` transactionReference; concurrency tested | ✅ PASS |
| P4 — Financial Integrity | `createHold()` is the authoritative check; optimistic pre-check is supplementary only | ✅ PASS |
| P4 — Financial Integrity | `initiateRefund()` does not alter historical journals; compensating journal owned by orchestration | ✅ PASS |
| P5 — Identity/Authorization | Ownership verified via `ownerActorId()`; step-up via `ActorStepUpVerifierContract` | ✅ PASS |
| P7 — Domain Boundaries | Adapter only calls `WalletModuleContract`; no direct wallet DB access; Payment domain owns `TransactionLimitExceededException` | ✅ PASS |
| P12 — Test-First Quality | All 16 FRs have corresponding tests (unit + feature) | ✅ PASS |
| P13 — Observability | Audit record on every hold placement and refund confirmation | ✅ PASS |
| P14 — No Silent Architecture Changes | No new wallet module methods; no new DB tables; adapter pattern unchanged | ✅ PASS |

**Gate result**: PASS — no violations. Proceed to Phase 0 research.

---

## Project Structure

### Documentation (this feature)

```text
specs/058-wallet-payment-adapter/
├── plan.md              ← This file
├── research.md          ← Phase 0 output (decisions, rationale, alternatives)
├── data-model.md        ← Phase 1 output (entities, value objects, state)
├── quickstart.md        ← Phase 1 output (runnable validation scenarios)
├── contracts/
│   └── wallet-adapter-contract.md  ← Phase 1 output (adapter method contracts)
└── tasks.md             ← Phase 2 output (/speckit-tasks — NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Domain/
│   │   └── Exceptions/
│   │       └── TransactionLimitExceededException.php    ← NEW (P09 T002)
│   └── Infrastructure/
│       └── Adapters/
│           └── WalletPaymentAdapter.php                 ← MODIFIED (P09 T004–T006)
└── tests/
    ├── Unit/Modules/Payment/Adapters/
    │   └── WalletPaymentAdapterTest.php                 ← MODIFIED (P09 T003, T007–T012)
    └── Feature/Modules/Payment/
        └── WalletPaymentConcurrencyTest.php             ← MODIFIED (P09 T009, T014)
```

**Structure decision**: Single-class adapter modification pattern. No new modules, no new packages. All changes are isolated to the `Payment` module's adapter layer and its tests.

---

## Complexity Tracking

> No constitution violations requiring justification.
