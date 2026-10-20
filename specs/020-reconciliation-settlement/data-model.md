# Data Model — E20 Reconciliation & Settlement

## Schema

All entities live in the `reconciliation` PostgreSQL schema.

---

## ReconciliationBatch

Represents a single import event — either a provider file upload, a provider API push, or a bank statement import.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `idempotency_key` | VARCHAR(128) | UNIQUE NOT NULL | Caller-supplied; prevents duplicate imports |
| `source_type` | VARCHAR(30) | NOT NULL | `provider_file`, `provider_api`, `bank_statement` |
| `source_reference` | VARCHAR(256) | NOT NULL | Filename, API batch ID, or bank statement ID |
| `batch_date` | DATE | NOT NULL | The business date the batch represents |
| `provider_code` | VARCHAR(50) | NULLABLE | Config-driven provider identifier (TBD) |
| `status` | VARCHAR(20) | NOT NULL | `draft`, `processing`, `matched`, `completed`, `failed` |
| `total_count` | INTEGER | NOT NULL DEFAULT 0 | Items in batch |
| `matched_count` | INTEGER | NOT NULL DEFAULT 0 | Items auto-matched |
| `unmatched_count` | INTEGER | NOT NULL DEFAULT 0 | Items with no match |
| `exception_count` | INTEGER | NOT NULL DEFAULT 0 | Items with open exceptions |
| `file_path` | VARCHAR(512) | NULLABLE | Storage path (for file-based imports) |
| `submitted_by` | UUID | NOT NULL FK `identity.users.id` | Actor who submitted |
| `processed_at` | TIMESTAMPTZ | NULLABLE | When async job completed |
| `metadata` | JSONB | NOT NULL DEFAULT '{}' | Raw import metadata, error details |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Indexes:**
- UNIQUE `(source_type, source_reference, batch_date)` — prevents duplicate imports
- `(status, created_at DESC)` — batch list queries
- `(submitted_by, created_at DESC)` — user history

**State machine:**
```
draft → processing → matched → completed
                  → failed
```

---

## ReconciliationItem

One transaction line within a `ReconciliationBatch`. Represents either a provider transaction or a bank statement line.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `batch_id` | UUID | NOT NULL FK `reconciliation_batches.id` | Parent batch |
| `item_type` | VARCHAR(30) | NOT NULL | `provider_transaction`, `bank_transaction` |
| `provider_reference` | VARCHAR(256) | NOT NULL | External transaction ID from provider/bank |
| `internal_transaction_id` | UUID | NULLABLE FK `transaction.transaction_projections.id` | Set when matched |
| `amount` | NUMERIC(18,4) | NOT NULL | Signed decimal. Positive = deposit/credit. Negative = withdrawal/debit. Sign preserved as-is from source file. Zero not permitted. |
| `currency_code` | CHAR(3) | NOT NULL | `BWP` |
| `transaction_date` | DATE | NOT NULL | Business date from provider/bank |
| `status` | VARCHAR(20) | NOT NULL | `pending`, `matched`, `unmatched`, `exception` |
| `match_confidence` | SMALLINT | NOT NULL DEFAULT 0 | 0–100: 100=exact, 50–99=fuzzy, 0=unmatched |
| `matched_at` | TIMESTAMPTZ | NULLABLE | When match was confirmed |
| `metadata` | JSONB | NOT NULL DEFAULT '{}' | Raw line data, matching details |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Indexes:**
- `(batch_id, status)` — batch item queries
- `(provider_reference, currency_code)` — match lookup
- `(internal_transaction_id)` — reverse lookup from transaction
- `(status, created_at DESC)` — global unmatched queries

---

## ReconciliationException

Tracks unresolved discrepancies requiring human intervention.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `item_id` | UUID | NOT NULL FK `reconciliation_items.id` | The item that caused this exception |
| `batch_id` | UUID | NOT NULL FK `reconciliation_batches.id` | For fast batch-level queries |
| `exception_type` | VARCHAR(30) | NOT NULL | `unmatched_provider`, `unmatched_bank`, `amount_mismatch`, `duplicate` |
| `status` | VARCHAR(20) | NOT NULL | `open`, `investigating`, `resolved`, `written_off` |
| `opened_by` | UUID | NOT NULL FK `identity.users.id` | System or admin who created exception |
| `assigned_to` | UUID | NULLABLE FK `identity.users.id` | Admin assigned to investigate |
| `resolved_by` | UUID | NULLABLE FK `identity.users.id` | Admin who resolved |
| `resolution` | TEXT | NULLABLE | Free-text resolution note |
| `resolution_type` | VARCHAR(30) | NULLABLE | `manually_matched`, `written_off`, `reversed`, `duplicate_confirmed` |
| `opened_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `resolved_at` | TIMESTAMPTZ | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Indexes:**
- `(status, created_at DESC)` — open exception list
- `(batch_id, status)` — per-batch exception queries
- `(assigned_to, status)` — assignee workqueue
- UNIQUE `(item_id)` — one exception per item at a time

**State machine:**
```
open → investigating → resolved
     →               → written_off
```

---

## SettlementBatch

Represents a financial settlement to be paid to or received from a provider/bank.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `idempotency_key` | VARCHAR(128) | UNIQUE NOT NULL | |
| `settlement_type` | VARCHAR(30) | NOT NULL | `provider_settlement`, `bank_settlement` |
| `provider_code` | VARCHAR(50) | NULLABLE | Config-driven (TBD) |
| `period_start` | DATE | NOT NULL | Settlement period start |
| `period_end` | DATE | NOT NULL | Settlement period end |
| `currency_code` | CHAR(3) | NOT NULL | `BWP` |
| `gross_amount` | NUMERIC(18,4) | NOT NULL | Total transaction value |
| `fee_amount` | NUMERIC(18,4) | NOT NULL DEFAULT 0 | Fees/commissions |
| `net_amount` | NUMERIC(18,4) | NOT NULL | gross - fee |
| `status` | VARCHAR(20) | NOT NULL | `draft`, `pending_approval`, `approved`, `executing`, `executed`, `rejected`, `failed` |
| `maker_id` | UUID | NOT NULL FK `identity.users.id` | Creator |
| `checker_id` | UUID | NULLABLE FK `identity.users.id` | Approver |
| `approved_at` | TIMESTAMPTZ | NULLABLE | |
| `rejected_at` | TIMESTAMPTZ | NULLABLE | |
| `rejection_reason` | TEXT | NULLABLE | |
| `executed_at` | TIMESTAMPTZ | NULLABLE | |
| `ledger_transaction_id` | VARCHAR(128) | NULLABLE | E07 journal reference |
| `external_reference` | VARCHAR(256) | NULLABLE | Bank/provider reference returned by adapter |
| `metadata` | JSONB | NOT NULL DEFAULT '{}' | |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Constraints:**
- CHECK `maker_id != checker_id OR checker_id IS NULL`
- CHECK `net_amount >= 0`
- CHECK `fee_amount >= 0`
- CHECK `gross_amount >= 0`

**Indexes:**
- `(status, created_at DESC)` — settlement list
- `(maker_id, created_at DESC)` — maker history
- `(period_start, period_end, settlement_type)` — period queries

**State machine:**
```
draft → pending_approval → approved → executing → executed
                        → rejected
                                   → failed
```

---

## SettlementItem

Links reconciliation items included in a settlement batch.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `settlement_batch_id` | UUID | NOT NULL FK `settlement_batches.id` | |
| `reconciliation_item_id` | UUID | NOT NULL FK `reconciliation_items.id` | |
| `amount` | NUMERIC(18,4) | NOT NULL | Item's contribution to net_amount |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Constraints:**
- UNIQUE `(settlement_batch_id, reconciliation_item_id)` — no duplicate items in a batch

---

## Adapter Interfaces (no DB table)

### ProviderFileAdapterInterface
```
parse(resource $fileStream, string $mimeType): ProviderTransactionDto[]
```
Default implementation: `CsvProviderFileAdapter` (bound via `reconciliation.provider_file_adapter` config).

### ProviderWebhookAuthenticatorInterface
```
verify(Request $request): bool
```
Default implementation: `HmacWebhookAuthenticator` (validates `X-Provider-Signature` against `reconciliation.webhook_secret`).

### SettlementAdapterInterface
```
execute(SettlementBatch $batch): string  // returns external reference ID
```
Default implementation: `NullSettlementAdapter` (marks executed, no external call).
Bound via `reconciliation.settlement_adapter` config.

---

## Cross-Schema References

| Entity | References | Read Pattern |
|--------|-----------|-------------|
| `ReconciliationItem.internal_transaction_id` | `transaction.transaction_projections.id` | Direct DB query (read-only cross-schema, same pattern as E19) |
| `ReconciliationBatch.submitted_by` | `identity.users.id` | FK reference only |
| `SettlementBatch.ledger_transaction_id` | E07 ledger entry (string reference, not FK) | Written on execution |
