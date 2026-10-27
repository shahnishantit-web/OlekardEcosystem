# Implementation Plan: P17 — Reconciliation Clearing and Provider Settlement

**Branch**: `064-reconciliation-settlement` | **Date**: 2026-10-25 | **Spec**: [spec.md](spec.md)

## Summary

Extend the Payment module with provider-side reconciliation. New entities `ProviderStatement` + `ProviderStatementLine` ingest authenticated provider settlement data with content-based deduplication. A `StatementMatchingService` matches lines against existing platform records (attempts, fees, refunds, reversals) and classifies unmatched lines as typed `ReconciliationException` entries with owner and ageing. `ClearingRecord` tracks capture clearing as distinct from bank settlement. A dual-approval `ReconciliationCorrectionRequest` workflow posts correcting journals exclusively through `LedgerModuleContract`. `PayoutQualificationService` computes net qualified amounts and publishes a `PayoutQualifiedEvent` outbox event for the payout scheduler. Wallet-to-wallet internal transfers are explicitly excluded from all clearing and settlement totals. AD-08/AD-09 unresolved — bank-payment clearing records carry `accounting_status = pending_ledger_config` and are excluded from `net_qualified_minor` until those decisions resolve.

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 12) — backend; same Payment module monolith

**Primary Dependencies**:
- `LedgerModuleContract` (sole journal posting mechanism — Principle 4)
- `WalletModuleContract` (owns wallet balances — Principle 4; queried for internal-transfer classification)
- `OutboxEventPublisher` (Platform — durable event delivery — Principle 9)
- `AuditServiceInterface` (Platform — all financial/privileged actions audited — Principle 13)
- `ConfigurationServiceInterface` (Platform — settlement ageing window, hash algo — Principle 3)
- `ReconciliationExceptionService` (existing in Payment module — extended for P17 owner/ageing fields)
- `PaymentRefundRepositoryInterface::sumConfirmedRefundsForAttempt()` (P15 — consumed for net qualification)
- `PaymentDisputeCaseRepositoryInterface::listFiltered()` (P16 — consumed for dispute hold exclusion)
- Laravel Horizon queued jobs (`StatementMatchingJob`, `BankSettlementBatchHandlerJob`, `PayoutQualificationJob`)

**Storage**: PostgreSQL `payment` schema — 4 new tables; 1 ALTER on `reconciliation_exceptions`

**Testing**: PHPUnit unit + feature tests (`app/Modules/Payment/Tests/`)

**Target Platform**: Laravel modular monolith on Linux

**Project Type**: Backend service extension — new entities, new services, new endpoints, 5 migrations

**Performance Goals**:
- Statement matching must complete within 120 s for 10,000 lines (async job, not synchronous request)
- Admin statement list API: < 500 ms at 50,000 statements with pagination

**Constraints**:
- `LedgerModuleContract::postEntry()` is the ONLY permitted mechanism for ledger corrections (Principle 4)
- Correction approval requires TWO distinct authorised operators — the submitter MUST NOT be the approver (Principle 5)
- No mark-as-paid path; no direct journal editing (FR-008, FR-009)
- `WalletModuleContract` owns all wallet-balance knowledge; `ClearingService` MUST NOT query wallet tables directly
- All state-changing APIs require `Idempotency-Key` (Principle 8)
- AD-08 UNRESOLVED — bank-payment clearing records carry `accounting_status = pending_ledger_config`; excluded from `net_qualified_minor`
- AD-09 UNRESOLVED — bank payment chart of accounts not yet defined; same `pending_ledger_config` treatment
- Existing `reconciliation_exceptions` DB CHECK constraint omits types added in P07–P16; P17 migration 5 must DROP and RECREATE the constraint with all current + new types (P17 adds `orphan_fee`)

**Scale/Scope**: 5 new tables (4 new + 1 ALTER); ~18 new PHP files; ~6 new test classes; ~150 tasks estimated

## Constitution Check

| Principle | Requirement | Status |
|-----------|-------------|--------|
| P2 TBD Governance | AD-08/AD-09 unresolved — no journal posted for bank payments | PASS — `pending_ledger_config` status; excluded from net totals |
| P3 Configuration | Settlement ageing window configurable via `ConfigurationServiceInterface` | PASS — no hard-coded window |
| P4 Financial Integrity | Corrections only via `LedgerModuleContract::postEntry()` with idempotency key `recon:{exceptionId}:correction` | PASS |
| P4 Financial Integrity | Duplicate statement imports detected and blocked by content hash | PASS — UNIQUE INDEX enforces dedup |
| P4 Financial Integrity | State-changing APIs idempotent (`Idempotency-Key` required on correction submit/approve) | PASS |
| P4 Financial Integrity | No mark-as-paid; evidence + dual approval required for all corrections | PASS — `ReconciliationCorrectionRequest` two-actor gate |
| P4 Financial Integrity | Historical ledger entries never edited | PASS — corrections use compensating entries only |
| P5 Identity/Authorization | Dual-approval requires distinct actors; enforced server-side | PASS — `reviewed_by !== requested_by` check |
| P7 Domain Boundaries | `ClearingService` does not write wallet tables; `WalletModuleContract` used for internal-transfer classification | PASS |
| P7 Domain Boundaries | P17 lives entirely in Payment module; E20 Reconciliation module not modified | PASS |
| P8 API Contracts | `/v1` versioned, UUID v7, `Idempotency-Key`, OpenAPI documented | PASS |
| P9 Event Reliability | `StatementIngestionCompletedEvent`, `PayoutQualifiedEvent` via outbox after durable state change | PASS |
| P13 Observability | Statement import count, match rate, exception count, correction lag metrics emitted | PASS |
| P14 No Silent Architecture Changes | No new framework; existing Payment module patterns reused | PASS |

**GATE RESULT**: PASS — no violations.

## Project Structure

### Documentation (this feature)

```text
specs/064-reconciliation-settlement/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/           ← Phase 1 output
│   ├── statement-api.yaml
│   └── statement-events.yaml
└── tasks.md             ← /speckit-tasks output (not yet)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/app/Modules/Payment/
├── Domain/
│   ├── Contracts/
│   │   ├── ProviderStatementRepositoryInterface.php        [NEW]
│   │   ├── ClearingRecordRepositoryInterface.php           [NEW]
│   │   └── ReconciliationCorrectionRepositoryInterface.php [NEW]
│   ├── Enums/
│   │   ├── ProviderStatementStatus.php                     [NEW]
│   │   ├── StatementLineType.php                           [NEW]
│   │   ├── StatementLineMatchStatus.php                    [NEW]
│   │   ├── ClearingRecordStatus.php                        [NEW]
│   │   ├── CorrectionRequestStatus.php                     [NEW]
│   │   └── ReconciliationExceptionType.php                 [EXTENDED +OrphanFee]
│   ├── Events/
│   │   ├── StatementIngestionCompletedEvent.php             [NEW]
│   │   └── PayoutQualifiedEvent.php                        [NEW]
│   ├── ValueObjects/
│   │   └── PayoutQualificationSummary.php                  [NEW]
│   └── Exceptions/
│       ├── StatementAlreadyExistsException.php             [NEW]
│       └── CorrectionApprovalException.php                 [NEW]
├── Infrastructure/
│   ├── Migrations/
│   │   ├── 2026_10_25_000014_create_provider_statements_table.php         [NEW]
│   │   ├── 2026_10_25_000015_create_provider_statement_lines_table.php    [NEW]
│   │   ├── 2026_10_25_000016_create_clearing_records_table.php            [NEW]
│   │   ├── 2026_10_25_000017_create_reconciliation_correction_requests_table.php [NEW]
│   │   └── 2026_10_25_000018_alter_reconciliation_exceptions_add_owner_ageing.php [NEW]
│   ├── Models/
│   │   ├── ProviderStatementModel.php                      [NEW]
│   │   ├── ProviderStatementLineModel.php                  [NEW]
│   │   ├── ClearingRecordModel.php                         [NEW]
│   │   └── ReconciliationCorrectionRequestModel.php        [NEW]
│   └── Repositories/
│       ├── EloquentProviderStatementRepository.php         [NEW]
│       ├── EloquentClearingRecordRepository.php            [NEW]
│       └── EloquentReconciliationCorrectionRepository.php  [NEW]
├── Application/
│   ├── Services/
│   │   ├── ProviderStatementService.php                    [NEW]  — ingest, hash, validate, dedup
│   │   ├── StatementMatchingService.php                    [NEW]  — match lines against platform records
│   │   ├── ClearingService.php                             [NEW]  — clearing records + settlement batch matching
│   │   ├── ReconciliationCorrectionService.php             [NEW]  — submit/approve/reject correction requests
│   │   └── PayoutQualificationService.php                  [NEW]  — compute + publish qualification totals
│   ├── Jobs/
│   │   ├── StatementMatchingJob.php                        [NEW]
│   │   ├── PayoutQualificationJob.php                      [NEW]
│   │   └── SettlementAgeingCheckJob.php                    [NEW]
│   └── EventHandlers/
│       └── BankSettlementBatchHandler.php                  [NEW]  — E25 bank.settlement.batch inbox
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── ProviderStatementAdminController.php        [NEW]
│   │   │   └── ReconciliationCorrectionAdminController.php [NEW]
│   │   └── BankStatementCallbackController.php             [NEW]  — POST ingress (E25 pattern)
│   └── Resources/
│       ├── ProviderStatementListResource.php               [NEW]
│       ├── ProviderStatementDetailResource.php             [NEW]
│       └── ReconciliationCorrectionRequestResource.php     [NEW]
└── Providers/
    └── PaymentServiceProvider.php                          [EXTENDED — new bindings + event listener]

wallet-ecosystem/services/api/routes/modules/payment.php   [EXTENDED — P17 routes]
wallet-ecosystem/services/api/routes/console.php           [EXTENDED — settlement ageing job]
wallet-ecosystem/services/api/openapi/v1/payment.yaml      [EXTENDED — P17 paths]
wallet-ecosystem/services/api/database/seeders/PaymentPermissionRoleSeeder.php [EXTENDED +approve_correction]
```

## Complexity Tracking

No constitution violations requiring justification.

| Design Choice | Rationale |
|---|---|
| Content-hash dedup on statement import | SHA-256 of (provider_code + account_id + environment + period_start_iso + period_end_iso + sorted provider_reference_ids) prevents duplicate processing even if the same file is re-submitted with formatting changes |
| `ClearingRecord` separate from `PaymentAttempt` | Capture (payment confirmed) and clearing (provider acknowledges the transaction in their settlement) are distinct financial events; conflating them misrepresents fund availability |
| `StatementMatchingJob` async (not synchronous) | 10,000-line statements would exceed HTTP timeout; background job with progress tracking is appropriate |
| Dual-approval for corrections | Prevents single-point fraud; aligns with P12 maker-checker precedent |
| `OrphanFee` exception type | New to P17 — fee line with no capture; distinct from `OrphanProvider` (capture with no platform record) |
| ALTER reconciliation_exceptions migration | Adds `owner`, `aged_at` columns and repairs stale DB CHECK constraint (omits P07–P16 types added without updating the constraint) |
