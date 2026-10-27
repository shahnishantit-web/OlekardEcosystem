# Implementation Plan: P15 — Shared Refund Engine and Original Method Return

**Branch**: `062-shared-refund-engine` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary

Extend the existing partial refund implementation to close eight verified gaps: add in-flight reservation to the cumulative guard, introduce `Submitted`/`Pending`/`Unknown`/`DefinitiveFailure` states, post a compensating ledger entry on wallet refund confirmation (AD-06), wire the P10/P11 inbox for async bank refund callbacks, add a domain eligibility hook, expose mobile and admin read APIs, harden `confirm()` idempotency, and emit state-transition metrics. Bank refund fee accounting remains deferred (AD-16 UNRESOLVED) with an explicit `pending_ledger_refund_fee_blocked` status.

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11) — backend API; TypeScript (Next.js) — admin; Flutter — mobile consumer read endpoint consumed by existing Flutter pages

**Primary Dependencies**: Laravel Eloquent (Payment module), `LedgerModuleContract` (Ledger module), `WalletModuleContract` (Wallet module), `OutboxEventPublisher` (Platform), `AuditServiceInterface` (Platform), `ConfigurationServiceInterface` (Platform), Laravel Horizon queued jobs

**Storage**: PostgreSQL `payment` schema — `payment_refunds` table extended; new index and columns added via migration

**Testing**: PHPUnit (unit + feature tests in `app/Modules/Payment/Tests/`); existing test infrastructure reused

**Target Platform**: Laravel modular monolith on Linux; same API gateway as existing Payment endpoints

**Project Type**: Backend service extension — new states, new job, new inbox handler, new API endpoints, migration

**Performance Goals**: The in-flight reservation query uses `SELECT FOR UPDATE` on `payment_refunds` — must complete within the existing DB transaction budget (<100ms under normal load)

**Constraints**:
- `LedgerModuleContract::postEntry()` is the ONLY permitted financial recording mechanism (Constitution Principle 4)
- `WalletModuleContract` owns all wallet balance operations — `RefundService` MUST NOT update wallet tables directly
- All state-changing refund APIs require `Idempotency-Key` header (Constitution Principle 8)
- AD-16 (bank refund fee policy) is UNRESOLVED — bank refund accounting is explicitly deferred; no incomplete journal may be posted
- AD-07 (dual `ProviderCapabilityEnum`) — `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum` (`FullRefund`/`PartialRefund`) is the authoritative enum for refund capability checks; `App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum` (`ReversePriorPayment`) is the Platform enum used by `WalletPaymentAdapter` — these are distinct and must not be confused

**Scale/Scope**: Extends ~10 existing files; creates ~10 new files; 2 new migrations; ~6 new test classes

## Constitution Check

| Principle | Requirement | Status |
|-----------|-------------|--------|
| P4 Financial Integrity | Compensating journal via `LedgerModuleContract` only; immutable originals | PASS — plan uses `postEntry()` with idempotency key |
| P4 Financial Integrity | `SELECT FOR UPDATE` on `payment_refunds` for in-flight guard | PASS — reservation uses DB lock |
| P4 Financial Integrity | State-changing APIs idempotent | PASS — `confirm()` hardened; `Idempotency-Key` required |
| P4 Financial Integrity | No silent wallet credit for unsupported refund method | PASS — `CapabilityUnsupportedException` + `ReconciliationException` |
| P7 Domain Boundaries | `RefundService` does not write wallet tables | PASS — delegates to `WalletModuleContract` |
| P7 Domain Boundaries | `PaymentRefundRepositoryInterface` extended cleanly | PASS — new method `sumReservedRefundsForAttempt()` |
| P8 API Contracts | `/v1` versioned, UUID v7, `Idempotency-Key`, OpenAPI documented | PASS |
| P9 Event Reliability | `refund.confirmed`/`refund.failed` via outbox | PASS |
| P13 Observability | State transition metrics emitted | PASS |
| P14 No Silent Architecture Changes | No new framework/ORM introduced | PASS |
| AD-16 UNRESOLVED | Bank refund accounting NOT posted | PASS — explicit `pending_ledger_refund_fee_blocked` |

**GATE RESULT**: PASS — no violations.

## Project Structure

### Documentation (this feature)

```text
specs/062-shared-refund-engine/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/           ← Phase 1 output
│   ├── refund-api.yaml
│   └── refund-events.yaml
└── tasks.md             ← /speckit-tasks output (not yet)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/app/Modules/Payment/
├── Domain/
│   ├── Contracts/
│   │   ├── RefundEligibilityHandlerInterface.php  [NEW]
│   │   └── PaymentRefundRepositoryInterface.php   [EXTENDED +sumReservedRefundsForAttempt()]
│   ├── Enums/
│   │   └── PaymentRefundStatus.php                [EXTENDED +Submitted,Pending,Unknown,DefinitiveFailure]
│   ├── Events/
│   │   └── RefundFailedEvent.php                  [NEW — for definitive_failure outbox]
│   └── Exceptions/
│       └── RefundEligibilityDeniedException.php   [NEW]
├── Application/
│   ├── Services/
│   │   └── RefundService.php                      [EXTENDED — eligibility, in-flight, ledger, idempotency]
│   ├── Jobs/
│   │   └── QueryRefundStatusJob.php               [NEW]
│   └── EventHandlers/
│       └── BankRefundCallbackHandler.php          [NEW — P11 inbox consumer]
├── Infrastructure/
│   ├── Migrations/
│   │   └── 2026_10_24_XXXXXX_extend_payment_refunds_p15.php [NEW]
│   └── Repositories/
│       └── EloquentPaymentRefundRepository.php    [EXTENDED +sumReservedRefundsForAttempt(), +listFiltered()]
└── Http/Controllers/
    ├── Customer/
    │   └── CustomerRefundStatusController.php      [NEW]
    └── Admin/
        └── AdminRefundListController.php           [EXTENDED or NEW — full list + query-status]

wallet-ecosystem/services/api/routes/modules/payment.php  [EXTENDED — new routes]

wallet-ecosystem/services/api/app/Modules/Payment/Tests/
├── Unit/
│   ├── RefundStateTransitionTest.php     [NEW]
│   ├── RefundInFlightReservationTest.php [NEW]
│   ├── RefundLedgerEntryTest.php         [NEW]
│   ├── UnsupportedRefundMethodTest.php   [NEW]
│   └── BankRefundCallbackHandlerTest.php [NEW]
└── Feature/
    └── ConcurrentPartialRefundTest.php   [NEW — SC-001 concurrency guard]
```
