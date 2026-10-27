# Implementation Plan: P18 — Beneficiary Verification and Payout Lifecycle

**Branch**: `065-beneficiary-payout-lifecycle` | **Date**: 2026-10-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/065-beneficiary-payout-lifecycle/spec.md`

---

## Summary

P18 introduces the shared payout capability: a structured lifecycle for disbursing collected, settled funds to a verified beneficiary. The feature spans four user stories:

- **US1 (UNBLOCKED)**: Beneficiary verification and payable balance computation — verify destination, check domain entitlement, derive cleared balance net of refunds, disputes, and prior payouts.
- **US2 (UNBLOCKED)**: Funds reservation with maker/checker separation — pessimistic-locked reservation, maker ≠ approver, approval invalidated on amount/destination change.
- **US3 (BLOCKED — AD-14)**: Payout execution via adapter — stable operation ID, retention on pending/unknown, manual_review on terminal failure.
- **US4 (BLOCKED — AD-10, LC14)**: Settlement confirmation and ledger disbursement — double-entry journal via LedgerModuleContract, idempotency on operation ID, reconcile before settling.

Deliverable for this iteration: US1 + US2 fully implemented; US3 + US4 scaffolded as BLOCKED stubs.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11)

**Primary Dependencies**: Eloquent ORM, Laravel Horizon (queue), Laravel Scheduler, LedgerModuleContract (E07), WalletModuleContract (E06)

**Storage**: PostgreSQL — `payment` schema (existing Payment module schema, same DB connection as P03–P17)

**Testing**: PHPUnit 11 — unit tests (no Docker required); feature/integration tests (Docker-gated — `@group docker`)

**Target Platform**: Laravel API service (`wallet-ecosystem/services/api`)

**Performance Goals**: Reservation creation ≤ 200 ms p95 (pessimistic lock hold is brief); balance computation ≤ 300 ms p95

**Constraints**:
- AD-14 unresolved — payout adapter interface cannot be finalised; US3 is a stub
- AD-10 unresolved — ledger debit account unknown; US4 is a stub
- No bank payout API calls fabricated; `StubPayoutAdapter` is DEV-only mock
- All money amounts in minor units (integer); no floating-point arithmetic
- Idempotency-Key required on all state-changing endpoints

**Scale/Scope**: Payment module extension — 5 new tables, ~20 new files in existing Laravel module structure

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked post-design.*

| Principle | Status | Evidence |
|-----------|--------|---------|
| P1 — Source-of-truth | PASS | Spec derived from FD10/LC14/AD10; no silent requirement changes |
| P2 — No TBD invention | PASS | AD-14 and AD-10 recorded as BLOCKED; not invented |
| P3 — Config over hard-coding | PASS | Reservation expiry, balance computation thresholds → ConfigurationService |
| P4 — Financial integrity | PASS | Double-entry via LedgerModuleContract; idempotency on operation_id; SELECT FOR UPDATE; compensating entries for corrections |
| P5 — Auth & authorization | PASS | Server-side RBAC; maker ≠ approver enforced at service layer with audit |
| P6 — Zero trust | PASS | All admin endpoints require authenticated session with scoped permissions |
| P7 — Domain boundaries | PASS | Payment module owns payout lifecycle; Wallet module owns balance via WalletModuleContract |
| P8 — API contracts | PASS | Versioned `/v1` endpoints; Idempotency-Key on mutations; OpenAPI documented |
| P9 — Event reliability | PASS | Outbox pattern via OutboxEventPublisher for domain events |
| P12 — Test-first quality | PASS | Unit tests for all domain logic; feature tests for API flows (Docker-gated) |
| P13 — Observability | PASS | Structured audit logging on all payout state transitions |
| P14 — No silent arch changes | PASS | AD-14 recorded as open; no new module created without ADR |
| P15 — Release governance | PASS | US3+US4 stubs documented as BLOCKED with explicit gate conditions |

**Constitution Check result**: PASS — US1+US2 can proceed to implementation. US3+US4 remain BLOCKED pending AD-14 and AD-10.

---

## Project Structure

### Documentation (this feature)

```text
specs/065-beneficiary-payout-lifecycle/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── payout-api.yaml  # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Domain/
│   │   ├── Contracts/
│   │   │   ├── PayoutAdapterInterface.php          [STUB — blocked AD-14]
│   │   │   ├── PayoutBeneficiaryRepositoryInterface.php
│   │   │   ├── PayoutRequestRepositoryInterface.php
│   │   │   └── PayoutReservationRepositoryInterface.php
│   │   ├── Enums/
│   │   │   ├── PayoutRequestStatus.php
│   │   │   ├── PayoutReservationStatus.php
│   │   │   ├── PayoutBeneficiaryStatus.php
│   │   │   ├── PayoutAttemptStatus.php
│   │   │   └── PayoutSettlementReconciliationStatus.php
│   │   ├── Events/
│   │   │   ├── PayoutReservationApprovedEvent.php
│   │   │   ├── PayoutExecutionSubmittedEvent.php   [STUB — blocked AD-14]
│   │   │   └── PayoutSettledEvent.php              [STUB — blocked AD-10]
│   │   ├── Exceptions/
│   │   │   ├── BeneficiaryNotVerifiedException.php
│   │   │   ├── InsufficientPayableBalanceException.php
│   │   │   ├── PayoutSelfApprovalException.php
│   │   │   ├── PayoutApprovalInvalidatedException.php
│   │   │   └── PayoutReservationExpiredException.php
│   │   └── ValueObjects/
│   │       ├── PayableBalance.php
│   │       └── PayoutOperationId.php               [STUB — blocked AD-14]
│   ├── Application/
│   │   ├── Services/
│   │   │   ├── PayoutBeneficiaryService.php
│   │   │   ├── PayoutBalanceService.php
│   │   │   ├── PayoutReservationService.php
│   │   │   ├── PayoutExecutionService.php          [STUB — blocked AD-14]
│   │   │   └── PayoutSettlementService.php         [STUB — blocked AD-10]
│   │   └── Jobs/
│   │       ├── PayoutReservationExpiryJob.php
│   │       └── PayoutAttemptQueryJob.php           [STUB — blocked AD-14]
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   ├── 2026_10_25_000019_create_payout_beneficiaries_table.php
│   │   │   ├── 2026_10_25_000020_create_payout_requests_table.php
│   │   │   ├── 2026_10_25_000021_create_payout_reservations_table.php
│   │   │   ├── 2026_10_25_000022_create_payout_attempts_table.php  [STUB]
│   │   │   └── 2026_10_25_000023_create_payout_settlement_records_table.php [STUB]
│   │   ├── Models/
│   │   │   ├── PayoutBeneficiaryModel.php
│   │   │   ├── PayoutRequestModel.php
│   │   │   ├── PayoutReservationModel.php
│   │   │   ├── PayoutAttemptModel.php              [STUB]
│   │   │   └── PayoutSettlementRecordModel.php     [STUB]
│   │   ├── Repositories/
│   │   │   ├── EloquentPayoutBeneficiaryRepository.php
│   │   │   ├── EloquentPayoutRequestRepository.php
│   │   │   └── EloquentPayoutReservationRepository.php
│   │   └── Adapters/
│   │       └── StubPayoutAdapter.php               [DEV MOCK — not for production]
│   └── Http/
│       ├── Controllers/Admin/
│       │   ├── PayoutBeneficiaryAdminController.php
│       │   ├── PayoutRequestAdminController.php
│       │   └── PayoutReservationAdminController.php
│       └── Resources/
│           ├── PayoutBeneficiaryResource.php
│           ├── PayoutRequestResource.php
│           └── PayoutReservationResource.php
├── routes/modules/payment.php           (AMENDED — P18 admin routes appended)
├── routes/console.php                   (AMENDED — PayoutReservationExpiryJob scheduled)
└── Providers/PaymentServiceProvider.php (AMENDED — P18 bindings + StubPayoutAdapter)

tests/
└── Unit/Payment/
    ├── PayoutBeneficiaryServiceTest.php
    ├── PayoutBalanceServiceTest.php
    └── PayoutReservationServiceTest.php

openapi/v1/payment.yaml                  (AMENDED — P18 paths and schemas appended)
docs/payment/
└── p18-payout-lifecycle-operator-runbook.md
```

---

## Complexity Tracking

No constitution violations.

> BLOCKER stubs are documented with `[STUB — blocked AD-14]` or `[STUB — blocked AD-10]` comments. They are empty PHP classes with a `@todo` referencing the blocking decision.
