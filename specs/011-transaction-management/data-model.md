# Data Model — E11 Transaction Management

## Schema

PostgreSQL schema: `transaction` (created in first migration)
DB connection: `platform`

---

## transaction.transaction_projections

Denormalized read model. Populated by E08–E10 domain event handlers. NOT a source-of-truth.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID PK | No | = source transaction ID (UUID v7 from E08/E09/E10) |
| reference | VARCHAR(40) | No | TXN-YYYYMMDD-XXXXXXXX; UNIQUE |
| type | VARCHAR(30) | No | `funding`, `transfer`, `withdrawal` |
| status | VARCHAR(30) | No | current status (mirrors source module status) |
| amount | BIGINT | No | gross amount in integer minor units |
| currency_code | CHAR(3) | No | ISO 4217 |
| direction | VARCHAR(10) | No | `debit` or `credit` |
| wallet_id | UUID | No | indexed |
| customer_id | UUID | No | indexed |
| description | VARCHAR(500) | Yes | human-readable description |
| source_module | VARCHAR(30) | No | `e08_funding`, `e09_transfer`, `e10_withdrawal` |
| metadata | JSONB | Yes | arbitrary event metadata; includes `reversal_journal_template` on Completed events |
| idempotency_key | VARCHAR(255) | Yes | |
| correlation_id | UUID | Yes | |
| ledger_journal_id | VARCHAR(100) | Yes | set on Completed events |
| parent_transaction_id | UUID | Yes | FK → transaction_projections.id; set for `refund` type rows |
| completed_at | TIMESTAMPTZ | Yes | |
| failed_at | TIMESTAMPTZ | Yes | |
| created_at | TIMESTAMPTZ | No | |
| updated_at | TIMESTAMPTZ | No | |

**Indexes**:
- `UNIQUE (reference)`
- `(customer_id)` — for customer-scoped queries
- `(customer_id, type)` — for type-filtered lists
- `(customer_id, status)` — for status-filtered lists
- `(customer_id, created_at DESC)` — for chronological list ordering
- `GIN (to_tsvector('english', reference || ' ' || coalesce(description, '')))` — full-text search

**Constraints**:
- `CHECK (type IN ('funding', 'transfer', 'withdrawal', 'refund'))`
- `CHECK (direction IN ('debit', 'credit'))`
- `CHECK (amount > 0)`

---

## transaction.transaction_status_history

Append-only. No UPDATE or DELETE allowed (enforced at model layer via `performUpdate()` override).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID PK | No | UUID v7 |
| transaction_id | UUID | No | FK → transaction_projections.id ON DELETE CASCADE |
| previous_status | VARCHAR(30) | Yes | null on initial insert |
| new_status | VARCHAR(30) | No | |
| actor_id | UUID | Yes | user/system who triggered the change |
| actor_type | VARCHAR(50) | Yes | `customer`, `agent`, `system`, `admin` |
| reason | VARCHAR(500) | Yes | |
| occurred_at | TIMESTAMPTZ | No | |

**Indexes**:
- `(transaction_id, occurred_at ASC)`

---

## transaction.reversals

Reversal lifecycle records.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID PK | No | UUID v7 |
| transaction_id | UUID | No | FK → transaction_projections.id; indexed |
| status | VARCHAR(30) | No | `pending_approval`, `approved`, `rejected`, `executed`, `failed` |
| requested_by | UUID | No | actor who requested the reversal |
| requested_at | TIMESTAMPTZ | No | |
| approved_by | UUID | Yes | actor who approved |
| approved_at | TIMESTAMPTZ | Yes | |
| rejected_by | UUID | Yes | actor who rejected |
| rejected_reason | VARCHAR(1000) | Yes | |
| executed_at | TIMESTAMPTZ | Yes | |
| compensating_journal_id | VARCHAR(100) | Yes | ledger journal ID from compensating entry |
| idempotency_key | VARCHAR(255) | No | UNIQUE |
| correlation_id | UUID | Yes | |
| created_at | TIMESTAMPTZ | No | |
| updated_at | TIMESTAMPTZ | No | |

**Constraints**:
- `CHECK (status IN ('pending_approval','approved','rejected','executed','failed'))`
- `UNIQUE (idempotency_key)`
- `UNIQUE (transaction_id) WHERE status IN ('pending_approval','approved')` — partial unique index;
  prevents duplicate active reversals per transaction

---

## transaction.reversal_audit_log

Append-only. No UPDATE or DELETE allowed.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID PK | No | UUID v7 |
| reversal_id | UUID | No | FK → reversals.id; indexed |
| transaction_id | UUID | No | indexed (redundant for query performance) |
| action | VARCHAR(100) | No | e.g. `reversal.requested`, `reversal.approved`, `reversal.rejected`, `reversal.executed`, `reversal.failed` |
| actor_id | UUID | Yes | |
| actor_type | VARCHAR(50) | Yes | |
| metadata | JSONB | Yes | additional context (reason, journal ID, etc.) |
| occurred_at | TIMESTAMPTZ | No | |

**Indexes**:
- `(reversal_id, occurred_at ASC)`
- `(transaction_id, occurred_at ASC)`

---

## transaction.transaction_receipts

Immutable after initial insert. No UPDATE or DELETE allowed (enforced at model layer).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID PK | No | UUID v7 |
| transaction_id | UUID | No | FK → transaction_projections.id; UNIQUE |
| reference | VARCHAR(40) | No | copy of transaction reference at time of receipt creation |
| amount | BIGINT | No | |
| currency_code | CHAR(3) | No | |
| type | VARCHAR(30) | No | |
| channel | VARCHAR(30) | Yes | funding_method / withdrawal channel |
| customer_id | UUID | No | indexed |
| created_at | TIMESTAMPTZ | No | |

**Indexes**:
- `UNIQUE (transaction_id)`
- `(customer_id)`

---

## State Machines

### Reversal Status

```
pending_approval → approved → executed
pending_approval → rejected
approved → rejected  (if maker-checker allows withdrawal)
approved → failed    (if ledger post fails)
```

### TransactionProjection.status (mirrors source module)

No state machine owned by E11. Status is updated via upsert when E08/E09/E10 events arrive.
Possible values depend on source module (e.g. for withdrawal: `pending`, `processing`, `completed`, `failed`, `cancelled`).
