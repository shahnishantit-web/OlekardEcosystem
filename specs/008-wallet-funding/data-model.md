# Data Model — E08 Wallet Funding

## Schema

All tables reside in the `funding` PostgreSQL schema.
- Migrations: `Schema::connection('ledger')->create('funding.{table}', ...)`
- Models: `protected $connection = 'platform'`, `protected $table = 'funding.{table}'`

---

## Entities

### 1. `funding.funding_transactions`

The central state machine entity. One row per funding attempt.

| Column                    | Type                   | Constraints            | Notes                                                             |
|---------------------------|------------------------|------------------------|-------------------------------------------------------------------|
| `id`                      | UUID                   | PK                     | UUID v7                                                           |
| `wallet_id`               | UUID                   | NOT NULL               | FK → `wallet.wallets.id`                                         |
| `pocket_currency_code`    | CHAR(3)                | NOT NULL               | ISO 4217 currency of the target pocket                            |
| `customer_id`             | UUID                   | NOT NULL               | FK → `platform.users.id`                                         |
| `agent_id`                | UUID                   | nullable               | FK → `platform.users.id`; set for `funding_method = agent`       |
| `funding_method`          | VARCHAR(32)            | NOT NULL               | ENUM: `mobile_money` \| `bank_transfer` \| `card` \| `agent`     |
| `status`                  | VARCHAR(32)            | NOT NULL DEFAULT 'pending' | ENUM: `pending` \| `processing` \| `awaiting_match` \| `completed` \| `failed` \| `cancelled` |
| `amount`                  | BIGINT                 | NOT NULL, CHECK > 0    | Gross funding amount in minor currency units                      |
| `fee_amount`              | BIGINT                 | NOT NULL DEFAULT 0     | Fee in minor units; always 0 for `agent` method (FR-E08-050)     |
| `net_amount`              | BIGINT                 | NOT NULL               | `amount − fee_amount`; credited to customer pocket               |
| `idempotency_key`         | VARCHAR(128)           | NOT NULL, UNIQUE       | Client-supplied idempotency key (race guard)                      |
| `provider_reference_id`   | VARCHAR(255)           | nullable               | Provider's external transaction / reference ID                    |
| `bank_reference_code`     | VARCHAR(64)            | nullable, UNIQUE       | `OLK-YYYYMMDD-XXXXXXXX`; set for `bank_transfer` on confirmation  |
| `bank_reference_expires_at` | TIMESTAMPTZ          | nullable               | TTL for bank reference matching                                   |
| `ledger_journal_id`       | UUID                   | nullable               | E07 journal ID written on `completed` transition                  |
| `transaction_reference`   | UUID                   | NOT NULL, UNIQUE       | Cross-module stable reference (used by E11 history)               |
| `failure_reason`          | VARCHAR(64)            | nullable               | Set on `failed`/`cancelled` status                               |
| `initiated_by`            | UUID                   | NOT NULL               | Actor UUID who called the initiation endpoint                     |
| `completed_at`            | TIMESTAMPTZ            | nullable               | Timestamp of `completed` transition                              |
| `failed_at`               | TIMESTAMPTZ            | nullable               | Timestamp of `failed` or `cancelled` transition                  |
| `created_at`              | TIMESTAMPTZ            | NOT NULL               |                                                                   |
| `updated_at`              | TIMESTAMPTZ            | NOT NULL               |                                                                   |

**Indexes**:
```sql
UNIQUE (idempotency_key)
UNIQUE (bank_reference_code)
UNIQUE (transaction_reference)
INDEX  (customer_id, status, created_at)   -- daily limit query
INDEX  (provider_reference_id)             -- callback lookup
INDEX  (wallet_id, status)                 -- wallet-level query
INDEX  (agent_id, created_at)              -- agent transaction history
INDEX  (bank_reference_code, status, bank_reference_expires_at)  -- matching query
```

**State transitions** (see spec §7.2):
```
pending ──(confirm, non-bank)──► processing ──(success callback)──► completed
pending ──(confirm, bank)──────► awaiting_match ──(bank matched)──► completed
pending ──(cancel)─────────────► cancelled
awaiting_match ──(cancel)──────► cancelled
processing ──(failure callback/timeout)──► failed
awaiting_match ──(reference expiry)──────► failed
```

---

### 2. `funding.funding_provider_callbacks`

Raw provider callback storage (written before inline processing to preserve the audit trail).

| Column                    | Type        | Constraints | Notes                                                   |
|---------------------------|-------------|-------------|----------------------------------------------------------|
| `id`                      | UUID        | PK          | UUID v7                                                  |
| `funding_transaction_id`  | UUID        | nullable    | FK → `funding_transactions.id`; null if tx not found    |
| `provider`                | VARCHAR(64) | NOT NULL    | Provider identifier string (e.g., `mock`, `mpesa`)      |
| `provider_reference_id`   | VARCHAR(255)| NOT NULL    | Provider's transaction ID                                |
| `status`                  | VARCHAR(32) | NOT NULL    | `success` \| `failure` \| `unknown`                     |
| `raw_payload`             | JSONB       | NOT NULL    | Raw request body as received                            |
| `signature_valid`         | BOOLEAN     | NOT NULL    | Whether HMAC/signature check passed                     |
| `received_at`             | TIMESTAMPTZ | NOT NULL    |                                                          |
| `processed_at`            | TIMESTAMPTZ | nullable    | Null if processing failed or still in progress          |
| `created_at`              | TIMESTAMPTZ | NOT NULL    |                                                          |

**Indexes**:
```sql
INDEX (provider_reference_id)                -- duplicate detection lookup
INDEX (funding_transaction_id)               -- join for admin review
INDEX (received_at)                          -- time-range queries
```

---

### 3. `funding.funding_receipts`

Immutable record created on every `completed` transition.

| Column                    | Type        | Constraints       | Notes                                              |
|---------------------------|-------------|-------------------|----------------------------------------------------|
| `id`                      | UUID        | PK                | UUID v7                                            |
| `receipt_reference`       | UUID        | NOT NULL, UNIQUE  | Human-facing reference (UUID v7)                  |
| `funding_transaction_id`  | UUID        | NOT NULL          | FK → `funding_transactions.id`                    |
| `amount`                  | BIGINT      | NOT NULL          | Gross amount                                       |
| `fee_amount`              | BIGINT      | NOT NULL DEFAULT 0|                                                    |
| `net_amount`              | BIGINT      | NOT NULL          |                                                    |
| `currency_code`           | CHAR(3)     | NOT NULL          |                                                    |
| `funding_method`          | VARCHAR(32) | NOT NULL          |                                                    |
| `agent_id`                | UUID        | nullable          | Set for agent cash-in                             |
| `customer_id`             | UUID        | NOT NULL          |                                                    |
| `wallet_id`               | UUID        | NOT NULL          |                                                    |
| `ledger_journal_id`       | UUID        | NOT NULL          | The E07 journal ID                                |
| `completed_at`            | TIMESTAMPTZ | NOT NULL          |                                                    |
| `created_at`              | TIMESTAMPTZ | NOT NULL          |                                                    |

**Indexes**:
```sql
UNIQUE (receipt_reference)
UNIQUE (funding_transaction_id)        -- one receipt per transaction
INDEX  (customer_id, created_at)       -- customer receipt history
INDEX  (agent_id, created_at)          -- agent transaction history
```

**Immutability**: `FundingReceiptModel` overrides `save()` and `delete()` to throw `LogicException` after initial insert (same pattern as E07 `JournalLineModel`).

---

### 4. `funding.bank_reconciliations`

Written when a bank notification is matched to a `FundingTransaction`.

| Column                    | Type         | Constraints        | Notes                                            |
|---------------------------|--------------|--------------------|-------------------------------------------------|
| `id`                      | UUID         | PK                 | UUID v7                                          |
| `funding_transaction_id`  | UUID         | NOT NULL           | FK → `funding_transactions.id`                  |
| `bank_notification_id`    | VARCHAR(255) | NOT NULL, UNIQUE   | Bank's internal transaction ID (idempotency key)|
| `bank_reference_code`     | VARCHAR(64)  | NOT NULL           | Reference code that matched                     |
| `matched_amount`          | BIGINT       | NOT NULL           | Amount from bank notification                   |
| `currency_code`           | CHAR(3)      | NOT NULL           |                                                 |
| `reconciled_at`           | TIMESTAMPTZ  | NOT NULL           |                                                 |
| `created_at`              | TIMESTAMPTZ  | NOT NULL           |                                                 |

**Indexes**:
```sql
UNIQUE (bank_notification_id)         -- idempotent notification handling (CC-E08-004)
INDEX  (funding_transaction_id)
INDEX  (reconciled_at)
```

---

### 5. `funding.unmatched_deposits`

Stores bank deposits that could not be matched to any open `FundingTransaction`.

| Column                    | Type         | Constraints       | Notes                                                     |
|---------------------------|--------------|-------------------|-----------------------------------------------------------|
| `id`                      | UUID         | PK                | UUID v7                                                   |
| `bank_notification_id`    | VARCHAR(255) | NOT NULL, UNIQUE  | Bank's transaction ID                                     |
| `amount`                  | BIGINT       | NOT NULL          | Amount as reported by bank                               |
| `currency_code`           | CHAR(3)      | NOT NULL          |                                                           |
| `raw_reference`           | VARCHAR(255) | nullable          | Reference string from bank narration (for admin review)  |
| `narration`               | TEXT         | nullable          | Full narration text from bank                            |
| `received_at`             | TIMESTAMPTZ  | NOT NULL          |                                                           |
| `resolution_status`       | VARCHAR(32)  | NOT NULL DEFAULT 'pending' | `pending` \| `credited` \| `returned`         |
| `resolved_by`             | UUID         | nullable          | Admin actor UUID                                         |
| `resolved_at`             | TIMESTAMPTZ  | nullable          |                                                           |
| `resolution_notes`        | TEXT         | nullable          |                                                           |
| `raw_payload`             | JSONB        | NOT NULL          | Raw bank notification payload                            |
| `created_at`              | TIMESTAMPTZ  | NOT NULL          |                                                           |
| `updated_at`              | TIMESTAMPTZ  | NOT NULL          |                                                           |

**Indexes**:
```sql
UNIQUE (bank_notification_id)
INDEX  (resolution_status, received_at)   -- admin review queue
```

---

## Migration File Naming

Following the E07 naming convention (sequential within the same date):

```
2026_09_19_001100_create_funding_transactions_table.php
2026_09_19_001200_create_funding_provider_callbacks_table.php
2026_09_19_001300_create_funding_receipts_table.php
2026_09_19_001400_create_funding_bank_reconciliations_table.php
2026_09_19_001500_create_funding_unmatched_deposits_table.php
2026_09_19_001600_seed_funding_permissions.php
2026_09_19_001700_seed_funding_configuration_stubs.php
```

The configuration stub migration seeds the following `platform.configuration_values` keys with `NULL` values to make the config key structure visible:
- `funding.processing.timeout_minutes` = `60`
- `funding.bank_reference.expiry_days` = `3`
- `funding.idempotency.ttl_seconds` = `86400`
- `funding.mock.success_rate` = `1.0`
- `ledger.float_account.BWP` = `NULL` (TBD-E08-004/TBD-E07-001 placeholder)

---

## Entity Relationships

```
platform.users ─────────── funding.funding_transactions (customer_id)
                      └──── funding.funding_transactions (agent_id)
wallet.wallets ──────────── funding.funding_transactions (wallet_id)

funding.funding_transactions
    └──(1:many)── funding.funding_provider_callbacks
    └──(1:1)───── funding.funding_receipts
    └──(1:1)───── funding.bank_reconciliations  (when bank-matched)

(no FK) funding.unmatched_deposits   -- deliberately no FK to funding_transactions
```

`unmatched_deposits` intentionally has no FK to `funding_transactions` because unmatched deposits arrive without a known matching transaction.
