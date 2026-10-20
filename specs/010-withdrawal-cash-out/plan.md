# Implementation Plan — E10 Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Epic**: E10 | **Created**: 2026-09-18

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new frameworks; pure Laravel PHP 8.4+ backend
- [x] No TBD value invented — fee rates, limit values, payout transit account reference all default to safe/permissive stubs; TBD-E10-001 through TBD-E10-005 remain deferred
- [x] Authorization remains server-side — `withdrawal.initiate` and `withdrawal.read` permissions enforced via `AuthorizationModuleContract`; IDOR returns 404 not 403
- [x] Financial integrity preserved — wallet debit via `LedgerModuleContract::postEntry()` only; hold mechanism prevents concurrent overdraft; journal idempotency key `withdrawal_complete:{id}` prevents double-debit
- [x] Security/audit requirements incorporated — all mutations audited; PIN not logged; `payout_destination` encrypted at rest; provider callback HMAC verified before any state change
- [x] UX/design-system requirements incorporated — error envelopes consistent with platform standard; masked destination in all responses
- [x] QA strategy incorporated — unit, feature, concurrency, and audit tests specified in quickstart.md

---

## Technical Context

| Aspect                   | Decision                                                      | Source              |
|--------------------------|---------------------------------------------------------------|---------------------|
| Backend framework        | Laravel / PHP 8.4+                                            | Constitution        |
| Module namespace         | `App\Modules\Withdrawal`                                      | E08/E09 pattern     |
| Database schema          | `withdrawal` (PostgreSQL)                                     | E08/E09 pattern     |
| DB connection            | `platform` (same as E06/E07/E08/E09)                         | E08/E09 pattern     |
| WalletHold mechanism     | Extend `WalletModuleContract` + `wallet.wallet_holds` (E06)   | research.md §D1     |
| PayoutProviderAdapter    | Interface in `Withdrawal\Domain\Contracts`; mock adapter      | research.md §D2     |
| WithdrawalRiskService    | Interface + stub (always approved); E16 overrides binding      | research.md §D3     |
| payout_destination       | JSONB + `encrypted:array` Laravel cast                        | research.md §D4     |
| Journal entry_type       | `debit`                                                       | research.md §D5     |
| Async dispatch           | `WithdrawalPayoutJob` on `withdrawal` queue                   | research.md §D8     |
| Migration batch          | `2026_09_21_001{N}00_`                                        | research.md §D7     |

---

## Phase 0 — Research

Research is complete. See [`research.md`](research.md) for all 9 decisions.

**Key resolved unknowns**:
- Hold mechanism → extend `WalletModuleContract` (no separate E10 hold table)
- `payout_destination` → Laravel `encrypted:array` cast
- Risk service → stub pattern identical to E09

---

## Phase 1 — Design

Design artifacts complete:
- [`data-model.md`](data-model.md) — entities, state machines, enums, WalletModuleContract extension
- [`contracts/api.md`](contracts/api.md) — all 11 endpoints with request/response contracts
- [`quickstart.md`](quickstart.md) — 11 end-to-end validation scenarios

---

## Directory Structure

```
app/Modules/Withdrawal/
├── Application/
│   ├── Contracts/
│   │   ├── WithdrawalRiskResult.php
│   │   └── WithdrawalRiskService.php
│   ├── Jobs/
│   │   └── WithdrawalPayoutJob.php
│   └── Services/
│       ├── WithdrawalInitiationService.php
│       ├── WithdrawalConfirmationService.php
│       ├── WithdrawalCancellationService.php
│       ├── WithdrawalCallbackService.php
│       ├── WithdrawalFeeService.php
│       ├── WithdrawalLimitService.php
│       ├── WithdrawalReceiptService.php
│       ├── WithdrawalMetricsService.php
│       └── WithdrawalExpiryService.php
├── Console/
│   └── Commands/
│       └── ExpireStaleWithdrawalsCommand.php
├── Domain/
│   ├── Enums/
│   │   ├── WithdrawalStatus.php
│   │   ├── WithdrawalChannel.php
│   │   └── WithdrawalFailureReason.php
│   ├── Events/
│   │   ├── WithdrawalInitiated.php
│   │   ├── WithdrawalProcessing.php
│   │   ├── WithdrawalCompleted.php
│   │   ├── WithdrawalFailed.php
│   │   ├── WithdrawalCancelled.php
│   │   ├── WithdrawalExpired.php
│   │   ├── WithdrawalAmountMismatch.php
│   │   ├── WithdrawalRiskRejected.php
│   │   └── WithdrawalLateCallback.php
│   └── Exceptions/
│       ├── WithdrawalNotFoundException.php
│       ├── WithdrawalNotModifiableException.php
│       ├── WithdrawalNotCancellableException.php
│       ├── WithdrawalReceiptImmutableException.php
│       ├── WithdrawalRiskRejectedException.php
│       ├── FeeMismatchException.php
│       └── WithdrawalAlreadyTerminalException.php
├── Infrastructure/
│   ├── Adapters/
│   │   └── MockPayoutProviderAdapter.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── WithdrawalController.php
│   │   │   ├── WithdrawalReceiptController.php
│   │   │   ├── WithdrawalCallbackController.php
│   │   │   └── AdminWithdrawalController.php
│   │   ├── Requests/
│   │   │   ├── WithdrawalInitiateRequest.php
│   │   │   └── WithdrawalConfirmRequest.php
│   │   └── Resources/
│   │       ├── WithdrawalTransactionResource.php
│   │       └── WithdrawalReceiptResource.php
│   ├── Migrations/
│   │   ├── 2026_09_21_001000_create_withdrawal_schema.php
│   │   ├── 2026_09_21_001100_create_withdrawal_transactions_table.php
│   │   ├── 2026_09_21_001200_create_withdrawal_provider_callbacks_table.php
│   │   ├── 2026_09_21_001300_create_withdrawal_receipts_table.php
│   │   ├── 2026_09_21_001400_seed_withdrawal_permissions.php
│   │   └── 2026_09_21_001500_seed_withdrawal_configuration_stubs.php
│   ├── Models/
│   │   ├── WithdrawalTransactionModel.php
│   │   ├── WithdrawalProviderCallbackModel.php
│   │   └── WithdrawalReceiptModel.php
│   └── Stubs/
│       └── WithdrawalRiskServiceStub.php
└── Providers/
    └── WithdrawalServiceProvider.php

routes/modules/withdrawal.php
```

**Modified files** (not new):
- `app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php` — add `createHold()`, `releaseHold()`
- `app/Modules/Wallet/Infrastructure/Adapters/WalletModuleContractAdapter.php` — implement new methods
- `config/horizon.php` — add `withdrawal` queue
- `bootstrap/app.php` or service provider registration — register `WithdrawalServiceProvider`

---

## Integration Points

| Contract                   | Method Used                                            | Called In                            |
|----------------------------|--------------------------------------------------------|--------------------------------------|
| `WalletModuleContract`     | `availableBalance()`, `getPocketLedgerAccountRef()`    | Initiation, Confirmation             |
|                            | `createHold()`, `releaseHold()`                        | Confirmation, Callback, Expiry       |
|                            | `isActive()`, `pocketExists()`                         | Initiation validation                |
| `LedgerModuleContract`     | `postEntry()`                                          | `WithdrawalCallbackService` (success)|
| `IdentityModuleContract`   | `verifyTransactionPin()`                               | `WithdrawalConfirmationService`      |
| `KycModuleContract`        | `getTier()` or equivalent                              | `WithdrawalLimitService`             |
| `AuditServiceInterface`    | `record()`                                             | All mutation services                |
| `OutboxEventPublisher`     | `publish()`                                            | All state transitions                |
| `IdempotencyService`       | `get()`, `set()`                                       | Initiation, Callbacks                |
| `ConfigurationService`     | `get(key, default)`                                    | FeeService, LimitService             |
| `MetricsCollectorInterface`| `increment()`, `histogram()`                           | `WithdrawalMetricsService`           |

---

## Financial Controls Checklist

- [x] Balance debit via `LedgerModuleContract::postEntry()` only — no direct balance mutation
- [x] Journal idempotency key `withdrawal_complete:{id}` prevents double-debit on duplicate callbacks
- [x] `SELECT FOR UPDATE` on pocket row during `createHold()` — concurrent overdraft prevented
- [x] Hold creation and payout dispatch are in separate steps (hold first, then dispatch job) — if job fails, hold expiry rescues
- [x] Ledger post, hold release, and receipt creation are atomic inside one DB transaction
- [x] Daily limit check runs inside DB transaction with new transaction creation (CC-E10-002)
- [x] `WithdrawalReceiptModel` overrides `save()`, `update()`, `delete()` to throw after first insert
- [x] Historical ledger entries never modified; failures use compensating patterns (hold release, no journal)

---

## Security Controls Checklist

- [x] `payout_destination` encrypted at rest (`encrypted:array` cast)
- [x] `payout_destination` never in event payloads, logs, or API responses
- [x] `payout_destination_masked` (last 4 chars) is the only external-facing destination representation
- [x] Transaction PIN never logged or stored plaintext
- [x] Provider HMAC/signature verified before any state mutation
- [x] IDOR: customer can only see own transactions (returns 404, not 403)
- [x] Step-up authentication required at `auth.step_up_threshold` (runtime config)
- [x] All mutations audited in `platform.audit_events`

---

## Implementation Approach

1. **Foundation** — Schema migration, domain enums, exceptions, `WithdrawalServiceProvider`, route registration.
2. **Models** — `WithdrawalTransactionModel` (with `encrypted:array` payout_destination cast), `WithdrawalProviderCallbackModel`, `WithdrawalReceiptModel` (immutable overrides).
3. **WalletModuleContract extension** — Add `createHold()` and `releaseHold()` to interface + adapter.
4. **Supporting services** — `WithdrawalFeeService`, `WithdrawalLimitService`, `WithdrawalRiskService` interface + stub.
5. **Core services** — `WithdrawalInitiationService`, `WithdrawalConfirmationService` (financial core; hold + journal), `WithdrawalCancellationService`, `WithdrawalCallbackService`.
6. **Provider adapter** — `PayoutProviderAdapter` interface + `MockPayoutProviderAdapter`.
7. **Async job** — `WithdrawalPayoutJob` on `withdrawal` queue.
8. **Expiry command** — `ExpireStaleWithdrawalsCommand` + `WithdrawalExpiryService`.
9. **Receipt service** — `WithdrawalReceiptService`, `WithdrawalReceiptModel`.
10. **HTTP layer** — Controllers, Form Requests, API Resources.
11. **Admin endpoints** — `AdminWithdrawalController` with force-fail.
12. **Metrics** — `WithdrawalMetricsService` injected into all state-change services.
13. **Events** — All 9 domain events.
14. **Migrations/seeds** — Permissions + configuration stubs.
15. **Tests** — Unit (fee/limit), feature (full API flows), concurrency, audit trail, receipt immutability, journal balance.

---

## Test Strategy

| Test Type       | File Pattern                                              | Covers                                              |
|-----------------|-----------------------------------------------------------|-----------------------------------------------------|
| Unit            | `tests/Unit/Modules/Withdrawal/*Test.php`                 | FeeService, LimitService (formula, rounding)        |
| Feature         | `tests/Feature/Modules/Withdrawal/*Test.php`              | All API flows, state machine, idempotency           |
| Concurrency     | `tests/Concurrency/Modules/Withdrawal/*Test.php`          | CC-E10-001 through CC-E10-004                       |
| Journal balance | `WithdrawalJournalBalanceTest.php`                        | 2-line, 3-line journals; balanced; no journal on failure |
| Audit trail     | `WithdrawalAuditTrailTest.php`                            | All audit events; PIN absent                        |
| Receipt         | `WithdrawalReceiptImmutabilityTest.php`                   | save/update/delete throw after insert               |
| Expiry command  | `ExpireStaleWithdrawalsTest.php`                          | TTL expiry, hold release, idempotency               |

---

## Re-evaluated Constitution Check (Post-Design)

All constitution principles satisfied:

| Principle | Status | Notes |
|-----------|--------|-------|
| P1 Source of truth | ✓ | All 12 backlog stories implemented |
| P2 TBD governance | ✓ | No TBD values invented; fee/limit defaults are 0/permissive |
| P3 Configurable | ✓ | Fee rates, limits, step-up threshold, hold TTL all from config |
| P4 Financial integrity | ✓ | Double-entry, immutable receipt, idempotent journal, hold atomicity |
| P5 Identity/auth | ✓ | PIN verification, step-up, server-side permission checks |
| P6 Zero trust | ✓ | Encrypted destination, PIN not logged, provider HMAC verified |
| P7 Domain boundaries | ✓ | WalletModuleContract extended properly; no direct table cross-access |
| P8 API contracts | ✓ | Versioned /v1, Idempotency-Key enforced, UUID v7, correlation IDs |
| P9 Event reliability | ✓ | Outbox publisher inside same DB transaction as state change |
| P10 UX | ✓ | Masked destination, clear error codes, consistent envelopes |
| P12 Test-first | ✓ | Full test matrix specified |
| P13 Observability | ✓ | 9 metrics, structured logging, correlation IDs, audit events |
| P14 No silent arch changes | ✓ | No new frameworks; WalletModuleContract extension is additive |
| P15 Release governance | ✓ | DoD checklist in spec §19 |
