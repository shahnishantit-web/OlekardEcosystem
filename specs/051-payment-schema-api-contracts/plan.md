# Implementation Plan: P02 — Payment Schema and API Contracts

**Branch**: `051-payment-schema-api-contracts` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/051-payment-schema-api-contracts/spec.md`

## Summary

Extend the Payment module schema (E48 baseline) with three additive migrations (multi-dimensional intent state, fee snapshot, optimistic locking, and a new payment_allocations table), update associated Eloquent models and PHP enums, and complete the `openapi/v1/payment.yaml` spec plus a new `payment-events.yaml` event schema document. All changes are additive — no existing column is removed or its constraint tightened.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 11) — existing project stack

**Primary Dependencies**:
- Laravel migrations (Illuminate\Database\Migrations\Migration, Blueprint, Schema, DB)
- Eloquent ORM (PaymentIntentModel already exists; extend + add PaymentAllocationModel)
- PHP 8.1 backed string enums (existing pattern in Domain/Enums/)
- OpenAPI 3.1.0 YAML (existing `openapi/v1/payment.yaml`)
- PHPStan level 8 (existing CI gate)

**Storage**: PostgreSQL `payment` schema (dedicated connection). All new tables in `payment.*`. No changes to `public.*` schema.

**Testing**:
- PHPUnit via `./vendor/bin/phpunit`
- PHPStan via `./vendor/bin/phpstan analyse app/Modules/Payment --level=8`
- OpenAPI lint via `npx @redocly/cli lint` (or equivalent)

**Target Platform**: `wallet-ecosystem/services/api` (Laravel API service)

**Project Type**: Backend API service — schema migration + domain enum + model + OpenAPI spec

**Performance Goals**:
- `ALTER TABLE ADD COLUMN` with constant DEFAULT on PostgreSQL is non-blocking (no rewrite) — safe under live load
- New partial indices (`WHERE accounting_status = 'pending_ledger'`) serve P08 queries efficiently

**Constraints**:
- No existing column may be dropped, renamed, or have its constraint tightened
- No second wallet journal — payment_allocations is a snapshot/allocation table, not a ledger
- No live charge, no adapter execution in P02 scope
- All migrations must have `down()` methods
- FR-B01–FR-B04 are BLOCKED — do not implement them (AWAITING_DOCUMENT: DEC-07)
- PHPStan level 8 must remain clean after model updates

**Scale/Scope**:
- 3 new migration files
- 3 new PHP enum files
- 1 new Eloquent model
- 1 modified Eloquent model
- 1 extended OpenAPI YAML
- 1 new OpenAPI event schema YAML
- ~6 new unit/feature test files

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Status |
|-----------|------|--------|
| P1 — Source-of-Truth | All schema extensions derive from ADR-E48-003 confirmed decisions; no silent business rule changes | PASS |
| P2 — TBD Governance | FR-B01–FR-B04 explicitly BLOCKED; allocation_type uses varchar with documented examples; no values invented | PASS |
| P4 — Financial Integrity | payment_allocations is a snapshot table, NOT a ledger; no direct wallet balance writes; postEntry() not called in migrations | PASS |
| P7 — Domain Boundaries | All changes in `App\Modules\Payment`; no cross-module table writes; FK only within `payment.*` | PASS |
| P8 — API Contracts | OpenAPI extended additively; versioned v1 paths preserved; idempotency enforced via existing headers | PASS |
| P12 — Test-First Quality | Unit tests for new enums; feature test for migration; optimistic lock concurrency test | PASS |
| P14 — No Silent Architecture Changes | No technology stack changes; additive schema extension | PASS |

**Constitution Check: PASS.** No violations.

## Project Structure

### Documentation (this feature)

```text
specs/051-payment-schema-api-contracts/
├── plan.md                              # This file
├── research.md                          # Phase 0: repository findings
├── data-model.md                        # Phase 1: entity schemas and state machines
├── contracts/
│   ├── migration-contracts.md           # Phase 1: migration up()/down() contracts
│   └── openapi-extension-contract.md   # Phase 1: OpenAPI changes contract
├── quickstart.md                        # Phase 1: runnable validation guide
└── tasks.md                             # /speckit-tasks output (not yet generated)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Domain/
│   │   └── Enums/
│   │       ├── PaymentAccountingStatus.php       ← NEW (US1)
│   │       ├── PaymentFulfilmentStatus.php        ← NEW (US1)
│   │       └── PaymentAllocationStatus.php        ← NEW (US3)
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   ├── 2026_10_21_000001_add_multi_state_to_payment_intents.php   ← NEW (US1)
│   │   │   ├── 2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php ← NEW (US2)
│   │   │   └── 2026_10_21_000003_create_payment_allocations_table.php    ← NEW (US3)
│   │   ├── Models/
│   │   │   ├── PaymentIntentModel.php              ← MODIFIED (US1, US2)
│   │   │   └── PaymentAllocationModel.php          ← NEW (US3)
│   │   └── Repositories/
│   │       └── PaymentAllocationRepositoryInterface.php ← NEW (US3, stub)
│   └── Tests/
│       ├── Unit/
│       │   ├── PaymentAccountingStatusTest.php     ← NEW
│       │   ├── PaymentFulfilmentStatusTest.php     ← NEW
│       │   ├── PaymentAllocationStatusTest.php     ← NEW
│       │   └── PaymentIntentOptimisticLockTest.php ← NEW
│       └── Feature/
│           └── PaymentSchemaP02Test.php            ← NEW (SC-01 through SC-03)
└── openapi/v1/
    ├── payment.yaml                               ← MODIFIED (US4)
    └── payment-events.yaml                        ← NEW (US4)
```

**Structure Decision**: All changes stay within `App\Modules\Payment` and `openapi/v1/`. No new modules. No cross-module dependencies introduced.

## Complexity Tracking

No constitution violations. No complexity tracking required.
