# Implementation Plan — E20 Reconciliation & Settlement

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected
- [x] No TBD value invented — provider selection deliberately left unresolved; pluggable adapter pattern used
- [x] Authorization remains server-side — `identity.auth` middleware + RBAC permissions on all endpoints
- [x] Financial integrity preserved — double-entry via E07 `LedgerModuleContract::postEntry()` for settlement; idempotency on all state-changing endpoints; maker-checker on settlement approval; DB transactions with `lockForUpdate`
- [x] Security/audit requirements incorporated — all reconciliation and settlement actions recorded via `AuditServiceInterface`; HMAC signature verification for webhook endpoint
- [x] UX/design-system requirements incorporated — Admin Portal pages consume `GET /v1/reconciliation/` endpoints; design tokens applied
- [x] QA strategy incorporated — unit, integration, concurrency tests; Docker quickstart SC-1 through SC-8

## Technical Context

- Backend: Laravel / PHP 8.4+
- Database: PostgreSQL — schema `reconciliation` (5 tables: reconciliation_batches, reconciliation_items, reconciliation_exceptions, settlement_batches, settlement_items)
- Queue/cache: Redis + Laravel Horizon — dedicated `reconciliation` queue for `ProcessReconciliationBatchJob` and `ExecuteSettlementJob`
- Web: Next.js + TypeScript — Admin Portal reconciliation dashboard
- API: REST/OpenAPI (3 contract files: recon-import.yaml, recon-matching.yaml, recon-settlement.yaml)
- Testing: PHPUnit/Pest — unit + integration + concurrency (settlement approval race condition)
- Observability: structured logs + 6 metrics (see Observability section)

## Module Structure

```
app/Modules/Reconciliation/
├── Domain/
│   ├── Contracts/
│   │   ├── ReconciliationModuleContract.php
│   │   └── ReconciliationMetricsInterface.php
│   ├── Enums/
│   │   ├── BatchSourceType.php       (provider_file, provider_api, bank_statement)
│   │   ├── BatchStatus.php           (draft, processing, matched, completed, failed)
│   │   ├── ItemStatus.php            (pending, matched, unmatched, exception)
│   │   ├── ExceptionType.php         (unmatched_provider, unmatched_bank, amount_mismatch, duplicate)
│   │   ├── ExceptionStatus.php       (open, investigating, resolved, written_off)
│   │   └── SettlementStatus.php      (draft, pending_approval, approved, executing, executed, rejected, failed)
│   ├── Models/
│   │   ├── ReconciliationBatch.php
│   │   ├── ReconciliationItem.php
│   │   ├── ReconciliationException.php
│   │   ├── SettlementBatch.php       (append-only for approved/executed states; lockForUpdate in approval)
│   │   └── SettlementItem.php
│   └── Adapters/
│       ├── ProviderFileAdapterInterface.php
│       ├── ProviderWebhookAuthenticatorInterface.php
│       └── SettlementAdapterInterface.php
├── Application/
│   ├── Services/
│   │   ├── ReconciliationImportService.php    (US-RECON-001, 002, 008)
│   │   ├── ReconciliationMatchingService.php  (US-RECON-003, 004, 005, 009, 010, 011)
│   │   ├── ReconciliationExceptionService.php (US-RECON-006, 007)
│   │   ├── SettlementService.php              (US-RECON-012–016)
│   │   └── ReconciliationMetricsService.php
│   ├── Jobs/
│   │   ├── ProcessReconciliationBatchJob.php  (reconciliation queue, tries=3, timeout=300)
│   │   └── ExecuteSettlementJob.php           (reconciliation queue, tries=3, timeout=300)
│   └── Adapters/
│       ├── CsvProviderFileAdapter.php         (default ProviderFileAdapterInterface)
│       ├── HmacWebhookAuthenticator.php       (default ProviderWebhookAuthenticatorInterface)
│       └── NullSettlementAdapter.php          (default SettlementAdapterInterface)
└── Infrastructure/
    ├── Http/
    │   ├── Controllers/
    │   │   ├── ReconciliationBatchController.php
    │   │   ├── ReconciliationMatchingController.php
    │   │   ├── ReconciliationExceptionController.php
    │   │   └── SettlementController.php
    │   ├── Requests/
    │   │   ├── ImportProviderFileRequest.php
    │   │   ├── ReceiveProviderApiRequest.php
    │   │   ├── ImportBankStatementRequest.php
    │   │   ├── MarkReconciledRequest.php
    │   │   ├── ResolveExceptionRequest.php
    │   │   ├── CreateSettlementRequest.php
    │   │   ├── ApproveSettlementRequest.php
    │   │   └── RejectSettlementRequest.php
    │   ├── Resources/
    │   │   ├── BatchSummaryResource.php
    │   │   ├── BatchDetailResource.php
    │   │   ├── ItemSummaryResource.php
    │   │   ├── ExceptionSummaryResource.php
    │   │   ├── ExceptionDetailResource.php
    │   │   ├── SettlementSummaryResource.php
    │   │   └── SettlementDetailResource.php
    │   └── Middleware/
    │       └── ReconciliationWebhookAuth.php   (HMAC verification for /batches/api)
    ├── Migrations/
    │   ├── 2026_09_27_000001_create_reconciliation_batches_table.php
    │   ├── 2026_09_27_000002_create_reconciliation_items_table.php
    │   ├── 2026_09_27_000003_create_reconciliation_exceptions_table.php
    │   ├── 2026_09_27_000004_create_settlement_batches_table.php
    │   └── 2026_09_27_000005_create_settlement_items_table.php
    └── Providers/
        └── ReconciliationServiceProvider.php
```

## Phase 0 — Research

Complete. See `research.md`. All unknowns resolved:

1. **Provider file format:** `ProviderFileAdapterInterface` + `CsvProviderFileAdapter` (default, config-swappable)
2. **Webhook authentication:** HMAC-SHA256 via `HmacWebhookAuthenticator`; custom middleware `reconciliation.webhook_auth`
3. **Matching algorithm:** Three-pass (exact → fuzzy ±1 business day → unmatched); async via `ProcessReconciliationBatchJob`
4. **Settlement execution:** `SettlementAdapterInterface` + `NullSettlementAdapter` (default); production adapter config-swappable
5. **Batch lifecycle:** `draft → processing → matched → completed | failed`
6. **Settlement lifecycle:** `draft → pending_approval → approved → executing → executed | rejected | failed`
7. **Cross-module deps:** E07 `LedgerModuleContract::postEntry()` for settlement ledger; direct DB query on `transaction.transaction_projections` for matching (same cross-schema read pattern as E19)

## Phase 1 — Design

Complete. Artifacts:
- `data-model.md` — 5 entities (ReconciliationBatch, ReconciliationItem, ReconciliationException, SettlementBatch, SettlementItem) + 3 adapter interfaces
- `contracts/recon-import.yaml` — US-RECON-001, 002, 008 (5 endpoints)
- `contracts/recon-matching.yaml` — US-RECON-003–007, 009–011 (6 endpoints)
- `contracts/recon-settlement.yaml` — US-RECON-012–016 (7 endpoints)
- `quickstart.md` — SC-1 through SC-8

## Implementation Approach

1. Register `ReconciliationServiceProvider` in `bootstrap/providers.php`; bind adapter interfaces to defaults via config.
2. Run 5 migrations (reconciliation schema).
3. Add `reconciliation` queue to `config/horizon.php`.
4. Implement `ReconciliationImportService` — file storage, batch creation, idempotency, job dispatch.
5. Implement `ProcessReconciliationBatchJob` — three-pass matching, item status updates, exception auto-creation.
6. Implement `ReconciliationMatchingService` — manual re-match, mark-reconciled.
7. Implement `ReconciliationExceptionService` — list, get, resolve/write-off.
8. Implement `SettlementService` — calculate net amount, create batch, submit, approve (maker-checker), reject.
9. Implement `ExecuteSettlementJob` — call adapter, post E07 ledger entry, update settlement status.
10. Implement HTTP layer (controllers, requests, resources).
11. Write tests (unit + integration + concurrency for settlement approval).
12. PHPStan level 6.
13. Generate consolidated OpenAPI doc.
14. Implement Next.js Admin Portal reconciliation pages.

## Data / Financial Controls

- `SettlementBatch` enforces `maker_id ≠ checker_id` via DB CHECK constraint AND service-layer 422 guard
- Settlement approval wrapped in `DB::transaction()` with `lockForUpdate()` — same pattern as E19 `AdminWalletAdjustmentService::approveAdjustment()`
- Settlement execution posts double-entry via E07 `LedgerModuleContract::postEntry()` with `entry_type='settlement'`; ledger post and status update inside same DB transaction — if E07 throws, settlement reverts to `approved` (no partial outcome)
- `ReconciliationBatch` UNIQUE on `(source_type, source_reference, batch_date)` — duplicate import returns existing batch
- `SettlementBatch` UNIQUE on `idempotency_key` — concurrent duplicate submissions return existing record
- State-changing financial endpoints require `Idempotency-Key` header (constitution P8): `POST /settlements`, `POST /settlements/{id}/approve`, `POST /settlements/{id}/execute`; `POST /settlements/{id}/submit` intentionally omits it — the `409 if not draft` guard functions as the natural idempotency mechanism for this administrative workflow transition (no money moves on submit, so a duplicate submit is harmless beyond the first)
- Amounts stored as `NUMERIC(18,4)`, computed with `bcmath` — no floating-point arithmetic
- `default_fee_rate` reads from `config/reconciliation.php` (PHP config level); admin-portal configurability via the Platform Configuration service (P3) is **deferred to E21+ admin config epic** — the default `'0'` means no fees; no business value is hard-coded

## Audit Events

| Event Key | Trigger | Actor Type |
|-----------|---------|------------|
| `reconciliation.batch.created` | File/API/bank import submitted | admin |
| `reconciliation.batch.matched` | Async matching job completed | system |
| `reconciliation.item.reconciled` | Item manually marked reconciled | admin |
| `reconciliation.exception.created` | Unmatched item auto-creates exception | system |
| `reconciliation.exception.resolved` | Admin resolves or writes off exception | admin |
| `reconciliation.settlement.created` | Maker creates settlement batch | admin (maker) |
| `reconciliation.settlement.submitted` | Maker submits for approval | admin (maker) |
| `reconciliation.settlement.approved` | Checker approves | admin (checker) |
| `reconciliation.settlement.rejected` | Checker rejects | admin (checker) |
| `reconciliation.settlement.executed` | ExecuteSettlementJob completes | system |

## Observability

**Metrics** (StatsD/Prometheus):

| Metric | Labels | Description |
|--------|--------|-------------|
| `reconciliation.batch.processed` | `source_type`, `status` | Batch lifecycle events |
| `reconciliation.item.matched` | `match_confidence_tier` (exact/fuzzy/unmatched) | Match outcome distribution |
| `reconciliation.exception.created` | `exception_type` | Exception creation rate |
| `reconciliation.exception.resolved` | `resolution_type` | Resolution outcomes |
| `reconciliation.settlement.action` | `action_type` | Settlement lifecycle events |
| `reconciliation.settlement.amount` | `currency_code` | Net settlement amounts |

**P9 Event Reliability decision**: Domain integration events (e.g., `US_RECON_001_SUCCEEDED` from spec.md) are scoped to audit records via `AuditServiceInterface` for E20. Full outbox/reliable-event delivery is **deferred** — the audit log satisfies P9 observability requirements within E20 scope. Revisit if Reconciliation events need to drive downstream processes (notifications, fraud scoring) in a future epic.

## Permissions

| Permission | Description | Endpoints |
|-----------|-------------|-----------|
| `reconciliation.read` | View batches, items, exceptions, settlements | All GET endpoints |
| `reconciliation.write` | Import files, trigger matching, mark reconciled, manage exceptions | POST batch/import, match, mark-reconciled, resolve-exception |
| `reconciliation.approve_settlement` | Approve/reject settlement (checker role) | /settlements/{id}/approve, /settlements/{id}/reject |
| `reconciliation.execute_settlement` | Execute approved settlement | /settlements/{id}/execute |

Seeded roles: `reconciliation-officer` (read+write), `settlement-approver` (read+write+approve), `settlement-executor` (read+execute).

## API Path Mapping (spec → implementation)

The spec declares action-based paths per story. This plan normalizes to RESTful paths:

| Spec Path | Implemented Path | Story |
|-----------|-----------------|-------|
| `/v1/reconciliation/import-provider-transaction-file` | `POST /v1/reconciliation/batches/file` | US-RECON-001 |
| `/v1/reconciliation/receive-provider-transaction-api` | `POST /v1/reconciliation/batches/api` | US-RECON-002 |
| `/v1/reconciliation/match-provider-transaction` | `POST /v1/reconciliation/batches/{id}/match` | US-RECON-003 |
| `/v1/reconciliation/mark-transaction-reconciled` | `POST /v1/reconciliation/items/{id}/mark-reconciled` | US-RECON-004 |
| `/v1/reconciliation/detect-unmatched-transaction` | `GET /v1/reconciliation/batches/{id}/unmatched` | US-RECON-005 |
| `/v1/reconciliation/create-reconciliation-exception` | Auto-created by matching job + `GET /v1/reconciliation/exceptions` | US-RECON-006 |
| `/v1/reconciliation/resolve-reconciliation-exception` | `POST /v1/reconciliation/exceptions/{id}/resolve` | US-RECON-007 |
| `/v1/reconciliation/import-bank-statement` | `POST /v1/reconciliation/batches/bank-statement` | US-RECON-008 |
| `/v1/reconciliation/match-bank-transaction` | `POST /v1/reconciliation/batches/{id}/match` (same endpoint, item_type=bank_transaction) | US-RECON-009 |
| `/v1/reconciliation/identify-unmatched-deposit` | `GET /v1/reconciliation/batches/{id}/unmatched?item_type=bank_transaction` | US-RECON-010 |
| `/v1/reconciliation/identify-unmatched-withdrawal` | `GET /v1/reconciliation/batches/{id}/unmatched?item_type=bank_transaction` (negative amount items) | US-RECON-011 |
| `/v1/reconciliation/calculate-settlement-amount` | `POST /v1/reconciliation/settlements` (returns calculated amounts) | US-RECON-012 |
| `/v1/reconciliation/generate-settlement-batch` | `POST /v1/reconciliation/settlements` (same endpoint — calculate + generate atomically) | US-RECON-013 |
| `/v1/reconciliation/approve-settlement` | `POST /v1/reconciliation/settlements/{id}/approve` | US-RECON-014 |
| `/v1/reconciliation/execute-settlement` | `POST /v1/reconciliation/settlements/{id}/execute` | US-RECON-015 |
| `/v1/reconciliation/record-settlement-ledger` | Internal: executed by `ExecuteSettlementJob` via E07 | US-RECON-016 |

> **US-RECON-006**: Exception creation is system-automated by `ProcessReconciliationBatchJob` — no manual `POST /exceptions` endpoint is exposed to actors. The `GET /exceptions` list endpoint satisfies the "view exceptions" aspect of this story.
