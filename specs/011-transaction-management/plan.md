# Implementation Plan — E11 Transaction Management

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected (no new technology introduced)
- [x] No TBD value invented — TBD-E11-001 (share channels), TBD-E11-002 (partial refunds), TBD-E11-003 (PDF receipt) remain TBD
- [x] Authorization remains server-side — per-endpoint permissions defined (FR-E11-007 through FR-E11-021)
- [x] Financial integrity preserved — compensating ledger via LedgerModuleContract; idempotency via FR-E11-023; concurrency via FR-E11-024; double-entry balance via FR-E11-025
- [x] Security/audit requirements incorporated — ReversalAuditLog (FR-E11-021), receipt share audit (FR-E11-014), all mutations audited
- [ ] UX/design-system requirements incorporated — NEEDS: transaction history and receipt screens designed (Phase 1)
- [ ] QA strategy incorporated — NEEDS: CC-E11-001 and CC-E11-002 concurrency test tasks added

## Technical Context
- Backend: Laravel / PHP 8.4+
- Database: PostgreSQL (connection: `platform`, schema prefix: `transaction`)
- Queue/cache: Redis + Laravel Horizon (new queue: `transaction`)
- Web: Next.js + TypeScript
- Mobile: Flutter
- API: REST/OpenAPI 3.1.0
- Testing: PHPUnit/Pest for unit + feature tests; concurrency tests via parallel process helpers
- Observability: structured logs, metrics, correlation IDs on all requests

---

## Key Architectural Decision: Projection-Based Read Model

E06–E10 each own their own transaction tables (`wallet_funding.funding_transactions`,
`wallet_transfer.transfer_transactions`, `withdrawal.withdrawal_transactions`).

**Decision**: E11 does NOT duplicate those tables and does NOT add a generic `POST /v1/transactions`
create endpoint. Instead, E11 provides:

1. A **unified read projection** (`transaction.transaction_projections`) populated by consuming
   domain events from E08–E10 via the outbox/event bus.
2. Ownership of the **Reversal lifecycle** (`transaction.reversals`, `transaction.reversal_audit_log`).
3. Ownership of **immutable receipts** (`transaction.transaction_receipts`).

**Implication for US-TXN-001**: "Create transaction" is fulfilled by the source module endpoints
(E08 `POST /v1/funding`, E09 `POST /v1/transfers`, E10 `POST /v1/withdrawals`). E11 exposes query,
history, receipt, and reversal capabilities over all transaction types.

**Why**: Avoids duplication of source-of-truth data; keeps module boundaries extraction-ready;
prevents the Transaction module from becoming a monolith that duplicates E06–E10 write paths.

---

## Module Structure
```
app/Modules/Transaction/
  Application/
    Commands/          # ReverseTransactionCommand, RefundTransactionCommand
    Queries/           # ListTransactionsQuery, GetTransactionQuery
    EventHandlers/     # Projection handlers for E08–E10 events
    Services/          # TransactionProjectionService, ReversalService, ReceiptService
  Domain/
    Entities/          # TransactionProjection, Reversal, TransactionReceipt, ReversalAuditLog
    Enums/             # TransactionStatus, ReversalStatus, TransactionType
    Events/            # Transaction.ReversalRequested, Transaction.ReversalApproved, etc.
    Exceptions/        # TransactionReceiptImmutableException, TransactionNotReversibleException
    Contracts/         # LedgerModuleContractInterface (already defined in E07)
  Infrastructure/
    Http/
      Controllers/     # TransactionController, ReversalController, ReceiptController
      Requests/        # ListTransactionsRequest, RequestReversalRequest, etc.
      Resources/       # TransactionResource, ReversalResource, ReceiptResource
    Models/            # TransactionProjectionModel, ReversalModel, etc.
    Migrations/
    Repositories/
  Providers/
    TransactionServiceProvider.php
```

---

## Data Entities

### transaction.transaction_projections
Denormalized read model populated by domain events from E08–E10.

| Field | Type | Notes |
|-------|------|-------|
| id | UUID (PK) | = source transaction ID (UUID v7) |
| reference | VARCHAR(40) | TXN-YYYYMMDD-XXXXXXXX; UNIQUE |
| type | VARCHAR(30) | `funding`, `transfer`, `withdrawal`, etc. |
| status | VARCHAR(20) | current status |
| amount | BIGINT | integer minor units |
| currency_code | CHAR(3) | |
| direction | VARCHAR(10) | `debit` or `credit` |
| wallet_id | UUID | |
| customer_id | UUID | indexed |
| description | VARCHAR(500) | |
| source_module | VARCHAR(30) | `e08_funding`, `e09_transfer`, `e10_withdrawal` |
| metadata | JSONB | nullable |
| idempotency_key | VARCHAR(255) | nullable |
| correlation_id | UUID | nullable |
| ledger_journal_id | UUID | nullable |
| parent_transaction_id | UUID | nullable; self-referencing FK → transaction_projections.id; set for `refund` type transactions |
| completed_at | TIMESTAMPTZ | nullable |
| failed_at | TIMESTAMPTZ | nullable |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

Indexes: `customer_id`, `(customer_id, type)`, `(customer_id, status)`, `(customer_id, created_at DESC)`,
full-text GIN index on `to_tsvector('english', reference || ' ' || coalesce(description, ''))`.

### transaction.transaction_status_history
Append-only. No UPDATE or DELETE permitted.

| Field | Type | Notes |
|-------|------|-------|
| id | UUID (PK) | UUID v7 |
| transaction_id | UUID | FK → transaction_projections.id |
| previous_status | VARCHAR(20) | nullable (null on initial insert) |
| new_status | VARCHAR(20) | |
| actor_id | UUID | nullable |
| actor_type | VARCHAR(50) | nullable |
| reason | VARCHAR(500) | nullable |
| occurred_at | TIMESTAMPTZ | |

Indexes: `(transaction_id, occurred_at ASC)`.

### transaction.reversals
Reversal lifecycle.

| Field | Type | Notes |
|-------|------|-------|
| id | UUID (PK) | UUID v7 |
| transaction_id | UUID | FK → transaction_projections.id; indexed |
| status | VARCHAR(30) | `pending_approval`, `approved`, `rejected`, `executed`, `failed` |
| requested_by | UUID | |
| requested_at | TIMESTAMPTZ | |
| approved_by | UUID | nullable |
| approved_at | TIMESTAMPTZ | nullable |
| rejected_by | UUID | nullable |
| rejected_reason | VARCHAR(1000) | nullable |
| executed_at | TIMESTAMPTZ | nullable |
| compensating_journal_id | UUID | nullable |
| idempotency_key | VARCHAR(255) | UNIQUE; non-null |
| correlation_id | UUID | nullable |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

Constraint: only one row per `transaction_id` in a non-terminal status (`pending_approval`, `approved`)
— enforced via partial unique index: `UNIQUE (transaction_id) WHERE status IN ('pending_approval', 'approved')`.

### transaction.reversal_audit_log
Append-only.

| Field | Type | Notes |
|-------|------|-------|
| id | UUID (PK) | UUID v7 |
| reversal_id | UUID | FK → reversals.id; indexed |
| transaction_id | UUID | indexed |
| action | VARCHAR(100) | e.g., `reversal.requested`, `reversal.approved` |
| actor_id | UUID | nullable |
| actor_type | VARCHAR(50) | nullable |
| metadata | JSONB | nullable |
| occurred_at | TIMESTAMPTZ | |

### transaction.transaction_receipts
Immutable after initial insert.

| Field | Type | Notes |
|-------|------|-------|
| id | UUID (PK) | UUID v7 |
| transaction_id | UUID | FK → transaction_projections.id; UNIQUE |
| reference | VARCHAR(40) | copy of transaction reference |
| amount | BIGINT | |
| currency_code | CHAR(3) | |
| type | VARCHAR(30) | |
| channel | VARCHAR(30) | nullable |
| customer_id | UUID | indexed |
| created_at | TIMESTAMPTZ | |

---

## API Endpoints

| Method | Path | Story | Priority | Auth |
|--------|------|-------|----------|------|
| GET | /v1/transactions | US-TXN-006–010 | P0 | Bearer |
| GET | /v1/transactions/{id} | US-TXN-011 | P0 | Bearer |
| GET | /v1/transactions/{id}/status-history | US-TXN-005 | P0 | Bearer |
| GET | /v1/transactions/{id}/receipt | US-TXN-012 | P1 | Bearer |
| POST | /v1/transactions/{id}/receipt/share | US-TXN-013 | P1 | Bearer |
| POST | /v1/transactions/{id}/reversals | US-TXN-014 | P0 | Bearer |
| GET | /v1/transactions/{id}/reversals | — | P0 | Bearer |
| POST | /v1/transactions/{id}/reversals/{rid}/approve | US-TXN-015 | P0 | Bearer |
| POST | /v1/transactions/{id}/reversals/{rid}/reject | US-TXN-016 | P0 | Bearer |
| POST | /v1/transactions/{id}/reversals/{rid}/execute | US-TXN-017 | P0 | Bearer (system) |
| GET | /v1/transactions/{id}/reversals/{rid}/audit | US-TXN-019 | P0 | Bearer |
| POST | /v1/transactions/{id}/refunds | US-TXN-018 | P1 | Bearer |

Note: US-TXN-001 (Create transaction) is served by E08–E10 module endpoints. E11 does not add a new
generic create endpoint.

---

## Dependencies on Prior Epics

| Epic | Dependency | Usage in E11 |
|------|-----------|--------------|
| E07 Ledger | `LedgerModuleContract::postEntry()` | Compensating journal for reversal/refund |
| E08 Funding | `Transaction.Completed` event | Populate transaction_projections |
| E09 Transfer | `Transaction.Completed` event | Populate transaction_projections |
| E10 Withdrawal | `Transaction.Completed` event | Populate transaction_projections |
| E17 Notifications (or stub) | Notification dispatch | Receipt share via email/SMS |

**E07 LedgerModuleContract must be stable** before FR-E11-019 (execute reversal) can be implemented.
If E17 is not yet available, use a `NotificationServiceStub` that logs the dispatch.

---

## Phase 0 — Research (required before tasks)

- [ ] Confirm E07 `LedgerModuleContract::postEntry()` signature is stable and supports reversals.
- [ ] Confirm E08–E10 outbox events carry `transaction_id`, `customer_id`, `wallet_id`, `amount`,
      `currency_code`, `type`, `direction`, `reference`, `status` fields.
- [ ] Evaluate full-text search approach for US-TXN-010:
      Option A: PostgreSQL `tsvector` GIN index (no new infrastructure).
      Option B: Elasticsearch (separate infra, not in approved stack without ADR).
      **Recommended: Option A** — PostgreSQL tsvector unless search requirements dictate otherwise.
- [ ] Confirm Redis idempotency store TTL config key exists or define `transaction.idempotency.ttl_seconds`.

Capture findings in `research.md`.

---

## Phase 1 — Design (required before tasks)

- [ ] `data-model.md` — full entity definitions, indexes, FK constraints, partial unique indexes.
- [ ] `contracts/api.yaml` — OpenAPI 3.1.0 for all 12 endpoints with request/response schemas.
- [ ] `quickstart.md` — validation scenarios: projection population, reversal lifecycle end-to-end,
      concurrency tests CC-E11-001 and CC-E11-002.

---

## Implementation Approach
1. Confirm all Phase 0 research findings.
2. Produce Phase 1 design artifacts.
3. Run `/speckit-tasks` to generate task breakdown.
4. Implement module skeleton (providers, migrations, base models).
5. Implement event handlers for transaction projection population.
6. Implement query layer (list/filter/search/detail).
7. Implement receipt creation and retrieval.
8. Implement reversal lifecycle (request → approve/reject → execute) with concurrency protection.
9. Implement refund path (P1).
10. Add PHPStan level 8 configuration and resolve all errors.
11. Write OpenAPI docs.
12. DoD validation.

---

## Data / Financial Controls
- Immutable history and receipt records enforced at model layer.
- `SELECT FOR UPDATE` on all reversal mutation paths.
- Compensating journal via `LedgerModuleContract::postEntry()` only — no direct balance mutation.
- Idempotency store keyed per FR-E11-023.
- All reversal state changes recorded in `ReversalAuditLog`.

## Observability
Every request carries a `X-Correlation-Id` header. Financial events (`reversal.executed`,
`refund.processed`) emit structured log lines with `amount`, `currency`, `reversal_id`,
`transaction_id`, `journal_id`. Status transitions emit metrics counter `transaction.status_changed{from,to}`.

## Complexity Tracking
Any architecture deviation (e.g., switching from projection model to direct cross-module DB queries,
introducing Elasticsearch) requires an ADR and constitution check update.
