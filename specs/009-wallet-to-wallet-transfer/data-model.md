# Data Model — E09 Wallet-to-Wallet Transfer

## Entity: TransferTransaction

**Table**: `transfer_transactions`
**Primary Key**: `id` UUID v7
**Spec Ref**: FR-E09-001 — FR-E09-004

### Fields

| Column | Type | Nullable | Constraints | Notes |
|--------|------|----------|-------------|-------|
| `id` | UUID | No | PK | UUID v7 |
| `transfer_reference` | VARCHAR(20) | No | UNIQUE, NOT NULL | Format: `TXF-{10-CHAR-ALPHANUM}` |
| `sender_customer_id` | UUID | No | FK → customers(id) | Indexed |
| `sender_wallet_id` | UUID | No | FK → wallets(id) | |
| `sender_pocket_ledger_ref` | VARCHAR(100) | No | NOT NULL | Resolved at initiation; identifies debit-side ledger account |
| `recipient_customer_id` | UUID | No | FK → customers(id) | Indexed |
| `recipient_wallet_id` | UUID | No | FK → wallets(id) | |
| `recipient_pocket_ledger_ref` | VARCHAR(100) | No | NOT NULL | Resolved at initiation; identifies credit-side ledger account |
| `currency_code` | CHAR(3) | No | NOT NULL | ISO 4217; sender and recipient pockets must share this value |
| `gross_amount` | BIGINT | No | NOT NULL, > 0 | Minor units; total sender debit |
| `fee_amount` | BIGINT | No | NOT NULL, >= 0 | Minor units; `0` when no fee |
| `net_amount` | BIGINT | No | NOT NULL, > 0 | Minor units; `gross_amount - fee_amount`; recipient credit |
| `fee_rate_bps` | INTEGER | No | NOT NULL, >= 0 | Locked at initiation; used for FEE_MISMATCH detection |
| `status` | VARCHAR(20) | No | NOT NULL, ENUM check | See TransferStatus |
| `failure_reason` | VARCHAR(40) | Yes | ENUM check | Null unless `status = 'failed'` |
| `ledger_journal_id` | VARCHAR(100) | Yes | | Set when `status = 'completed'` |
| `idempotency_key` | VARCHAR(255) | Yes | PARTIAL UNIQUE | UNIQUE WHERE `idempotency_key IS NOT NULL` |
| `correlation_id` | UUID | No | NOT NULL | Tracing across services |
| `completed_at` | TIMESTAMP WITH TZ | Yes | | Set when `status = 'completed'` |
| `cancelled_at` | TIMESTAMP WITH TZ | Yes | | Set when `status = 'cancelled'` |
| `created_at` | TIMESTAMP WITH TZ | No | NOT NULL | |
| `updated_at` | TIMESTAMP WITH TZ | No | NOT NULL | |

### Indexes

```sql
CREATE UNIQUE INDEX idx_transfer_transactions_reference ON transfer_transactions(transfer_reference);
CREATE UNIQUE INDEX idx_transfer_transactions_idempotency ON transfer_transactions(idempotency_key)
    WHERE idempotency_key IS NOT NULL;
CREATE INDEX idx_transfer_transactions_sender ON transfer_transactions(sender_customer_id, created_at DESC);
CREATE INDEX idx_transfer_transactions_recipient ON transfer_transactions(recipient_customer_id, created_at DESC);
CREATE INDEX idx_transfer_transactions_status ON transfer_transactions(status, created_at DESC);
```

### Validation Rules

- `gross_amount > 0`
- `fee_amount >= 0` and `fee_amount < gross_amount`
- `net_amount = gross_amount - fee_amount`
- `net_amount > 0`
- `fee_rate_bps >= 0`
- `status IN ('pending', 'completed', 'failed', 'cancelled')`
- `failure_reason IS NULL` when `status != 'failed'`
- `ledger_journal_id IS NOT NULL` when `status = 'completed'` (enforced at application layer)
- `completed_at IS NOT NULL` when `status = 'completed'`
- `sender_customer_id != recipient_customer_id` (self-transfer guard)

### State Transitions

```
[created] → pending
pending   → completed   (successful PIN + journal post)
pending   → failed      (balance/risk/system failure at confirmation, or TTL expiry)
pending   → cancelled   (explicit customer cancellation)
```

Only `pending → *` transitions are valid. `completed`, `failed`, `cancelled` are terminal.

---

## Entity: TransferReceipt

**Table**: `transfer_receipts`
**Primary Key**: `id` UUID v7
**Spec Ref**: FR-E09-005, FR-E09-036 — FR-E09-039

### Fields

| Column | Type | Nullable | Constraints | Notes |
|--------|------|----------|-------------|-------|
| `id` | UUID | No | PK | UUID v7 |
| `transfer_transaction_id` | UUID | No | FK → transfer_transactions(id), UNIQUE | One receipt per transfer |
| `receipt_reference` | UUID v7 | No | UNIQUE, NOT NULL | Customer-facing lookup key |
| `sender_customer_id` | UUID | No | NOT NULL | Denormalized for efficient auth queries |
| `recipient_customer_id` | UUID | No | NOT NULL | Denormalized for efficient auth queries |
| `currency_code` | CHAR(3) | No | NOT NULL | |
| `gross_amount` | BIGINT | No | NOT NULL | Snapshot from parent transfer |
| `fee_amount` | BIGINT | No | NOT NULL | |
| `net_amount` | BIGINT | No | NOT NULL | |
| `issued_at` | TIMESTAMP WITH TZ | No | NOT NULL | Equals `transfer_transactions.completed_at` |

### Indexes

```sql
CREATE UNIQUE INDEX idx_transfer_receipts_transfer_id ON transfer_receipts(transfer_transaction_id);
CREATE UNIQUE INDEX idx_transfer_receipts_reference ON transfer_receipts(receipt_reference);
CREATE INDEX idx_transfer_receipts_sender ON transfer_receipts(sender_customer_id);
CREATE INDEX idx_transfer_receipts_recipient ON transfer_receipts(recipient_customer_id);
```

### Immutability Contract

The Eloquent model (`TransferReceiptModel`) MUST override:
- `save()` after first insert → throw `TransferReceiptImmutableException`
- `delete()` → throw `TransferReceiptImmutableException`
- `update()` → throw `TransferReceiptImmutableException`

---

## Redis Structure: LookupToken

**Not a database table.** Stored in Redis.

| Key Pattern | Value Type | TTL | Notes |
|-------------|------------|-----|-------|
| `transfer_lookup:{uuid}` | JSON string | 300 seconds | Single-use; deleted on first consumption |

### Value Schema

```json
{
  "recipient_customer_id": "01JXXXXX",
  "recipient_wallet_id": "01JXXXXX",
  "recipient_pocket_ledger_ref": "cust_pocket:01JXXXXX:BWP",
  "currency_code": "BWP",
  "created_at": 1726694400
}
```

### Lifecycle

1. **Issue**: `RecipientLookupService` generates UUID v7, stores JSON in Redis with `SET EX 300`, returns UUID to client.
2. **Consume**: `TransferInitiationService` calls `LookupTokenService::consume(token)` which does `GETDEL transfer_lookup:{token}`. Returns payload or `null` (null → `InvalidLookupTokenException`).
3. **Expiry**: Redis TTL handles expiry automatically. No DB cleanup needed.

---

## Enum: TransferStatus

| Value | Description |
|-------|-------------|
| `pending` | Transfer created, fee quoted, awaiting PIN confirmation |
| `completed` | Journal posted, receipt created, money moved |
| `failed` | Failed during confirmation (balance, risk, system) or expired |
| `cancelled` | Sender cancelled before confirmation |

---

## Enum: TransferFailureReason

| Value | Trigger |
|-------|---------|
| `INSUFFICIENT_BALANCE` | Sender's balance < gross_amount at authoritative check (CC-E09-001) |
| `DAILY_LIMIT_EXCEEDED` | Daily limit exhausted between initiation and confirmation (CC-E09-002) |
| `RISK_REJECTED` | TransferRiskService returns rejected |
| `RECIPIENT_WALLET_SUSPENDED` | Recipient wallet suspended at confirmation time |
| `SYSTEM_ERROR` | Journal post failed, unexpected exception, or TTL expiry |

---

## Entity Relationships

```
customers (E01)
    │
    ├─── sender_customer_id ──────┐
    │                             ▼
    │                   transfer_transactions ────► transfer_receipts (1:0..1)
    │                             ▲
    └─── recipient_customer_id ───┘

wallets (E06)
    │
    ├─── sender_wallet_id ────────►  transfer_transactions
    └─── recipient_wallet_id ─────►  transfer_transactions

ledger_accounts (E07) ◄── sender_pocket_ledger_ref / recipient_pocket_ledger_ref
```

---

## Financial Journal Structure

### When fee_amount > 0 (3-line balanced entry)

```
DEBIT  sender_pocket_ledger_ref      gross_amount
CREDIT recipient_pocket_ledger_ref   net_amount       (= gross - fee)
CREDIT transfer.fee_account.{CCY}    fee_amount
─────────────────────────────────────────────────────
       TOTAL DEBITS  =  TOTAL CREDITS  =  gross_amount
```

### When fee_amount = 0 (2-line balanced entry)

```
DEBIT  sender_pocket_ledger_ref      gross_amount
CREDIT recipient_pocket_ledger_ref   gross_amount
─────────────────────────────────────────────────
       TOTAL DEBITS  =  TOTAL CREDITS  =  gross_amount
```

### Worked Example

- gross_amount = 50000 (BWP 500.00)
- fee_rate_bps = 100 (1%)
- fee_amount = round(50000 × 100 / 10000) = round(500) = 500
- net_amount = 50000 − 500 = 49500 (BWP 495.00)

| Line | Side | Account | Amount | Human |
|------|------|---------|--------|-------|
| 1 | DEBIT | Sender pocket (01JXXX) | 50000 | BWP 500.00 |
| 2 | CREDIT | Recipient pocket (01JYYY) | 49500 | BWP 495.00 |
| 3 | CREDIT | Platform fee acct (BWP) | 500 | BWP 5.00 |

Balance check: 50000 = 49500 + 500 ✓
