# Data Model — E27 Reliability & Transaction Integrity

## New Tables

### `platform.dead_letter_items`

Captures financial operations that have exhausted all retry attempts and are awaiting operator review or replay.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `uuid` | PK, UUID v7 | Unique item identifier |
| `operation_type` | `varchar(100)` | NOT NULL | Category: `funding.initiation`, `transfer.confirmation`, `withdrawal.payout`, `service.payment`, `qr.payment`, `commission.reversal`, etc. |
| `source_id` | `uuid` | NOT NULL | ID of the originating domain record (transaction ID, job ID, etc.) |
| `payload` | `jsonb` | NOT NULL | Complete job/operation payload for replay |
| `failure_reason` | `text` | NOT NULL | Last failure message or exception class |
| `retry_count` | `integer` | NOT NULL, DEFAULT 0 | Number of attempts made before dead-lettering |
| `retry_history` | `jsonb` | NOT NULL, DEFAULT '[]' | Array of `{attempt, failed_at, reason}` objects |
| `status` | `varchar(20)` | NOT NULL, DEFAULT 'pending' | `pending` / `replayed` / `dismissed` |
| `replayed_at` | `timestamptz` | NULLABLE | Timestamp of successful replay |
| `replayed_by` | `uuid` | NULLABLE | Actor ID of the operator who triggered replay |
| `replay_outcome` | `jsonb` | NULLABLE | Result payload from the replay execution |
| `dismissed_at` | `timestamptz` | NULLABLE | Timestamp of dismissal |
| `dismissed_by` | `uuid` | NULLABLE | Actor ID of the operator who dismissed |
| `dismissal_reason` | `text` | NULLABLE | Operator-supplied reason for dismissal |
| `correlation_id` | `varchar(100)` | NULLABLE | Originating request correlation ID |
| `created_at` | `timestamptz` | NOT NULL | When the item was dead-lettered |
| `expires_at` | `timestamptz` | NOT NULL | Retention expiry (dead_letter_ttl_days after created_at) |

**Indexes**:
- `(status, created_at)` — admin list filtering
- `(operation_type, status)` — type-scoped queries
- `(source_id)` — look up by originating domain record
- `(expires_at)` — TTL cleanup job

**State transitions**: `pending` → `replayed` (on successful replay), `pending` → `dismissed` (on operator dismissal). Terminal states; no transitions back to `pending`.

---

### `platform.ledger_integrity_snapshots`

Records the result of each scheduled ledger consistency scan.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `uuid` | PK, UUID v7 | Unique snapshot identifier |
| `scan_at` | `timestamptz` | NOT NULL | When the scan ran |
| `wallets_checked` | `integer` | NOT NULL | Count of wallets included in the scan |
| `discrepancy_count` | `integer` | NOT NULL, DEFAULT 0 | Number of wallets with debit/credit imbalance |
| `affected_wallet_ids` | `jsonb` | NOT NULL, DEFAULT '[]' | Array of wallet UUIDs with discrepancies |
| `outcome` | `varchar(20)` | NOT NULL | `clean` / `discrepancy_found` |
| `duration_ms` | `integer` | NULLABLE | Scan execution time in milliseconds |
| `created_at` | `timestamptz` | NOT NULL | Record creation timestamp |

**Indexes**:
- `(scan_at DESC)` — latest scan lookup
- `(outcome)` — filter by discrepancy

**Retention**: Snapshots are retained for the standard platform audit log retention period. No automatic purge.

---

## Modified Entities

### `platform.idempotency_keys` (existing, no schema change)

No schema change required. E27 audits endpoint coverage and adds `idempotency:require` middleware to financial routes that currently lack mandatory enforcement. The existing `IdempotencyService`, `IdempotencyKeyModel`, and `IdempotencyCacheRepository` are not modified.

**Key behavioral change**: `IdempotencyMiddleware` gains a `require` parameter. When `require` is set, requests with no `Idempotency-Key` header return HTTP 422 (currently they pass through).

---

### Financial transaction tables (existing, possible schema additions)

Each financial module has its own transaction table. E27 may add columns where missing:

| Module | Table | Column to add (if missing) |
|--------|-------|---------------------------|
| Funding | `funding.transactions` | `provider_reference_id` already exists ✓; `dead_lettered_at` (nullable) |
| Transfer | `transfer.transactions` | `dead_lettered_at` (nullable) |
| Withdrawal | `withdrawal.transactions` | `provider_reference_id` (if missing); `dead_lettered_at` (nullable) |
| QR/Merchant | `qr.transactions` | `dead_lettered_at` (nullable) |
| Service | `service.transactions` | `dead_lettered_at` (nullable) |

`dead_lettered_at` links the domain transaction to its `platform.dead_letter_items` entry for cross-reference. Added in the E27 migration; nullable to preserve backward compatibility.

---

## New Configuration Keys

Stored in `platform.configuration_values` (via `ConfigurationServiceInterface`) with PHP-level defaults in `config/reliability.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `reliability.transaction_timeout_seconds` | 30 | Max seconds for a financial operation to complete |
| `reliability.orphan_threshold_minutes` | 5 | Minutes before a pending transaction is considered an orphan |
| `reliability.max_retry_attempts` | 3 | Max retry attempts before dead-lettering |
| `reliability.retry_base_delay_seconds` | 5 | Initial retry delay (doubles with each attempt) |
| `reliability.dead_letter_ttl_days` | 30 | Days before pending dead-letter items expire |
| `reliability.idempotency_ttl_days` | 7 | Days before idempotency records expire |
| `reliability.ledger_scan_interval_minutes` | 60 | Frequency of ledger integrity scans |

---

## New Domain Contracts

### `OrphanDetectableInterface` (new platform contract)

```php
interface OrphanDetectableInterface {
    // Returns transaction IDs pending beyond the configured threshold
    public function findOrphanedTransactions(Carbon $threshold): Collection;

    // Attempt to recover or reverse the given orphaned transaction
    public function recoverOrphanedTransaction(string $transactionId): OrphanResolution;
}
```

Financial modules (Funding, Transfer, Withdrawal, QR, Service) implement this contract. The Platform `OrphanDetectionService` iterates registered implementations.

---

## Key Entities (summary)

| Entity | Table | New? |
|--------|-------|------|
| DeadLetterItem | `platform.dead_letter_items` | NEW |
| LedgerIntegritySnapshot | `platform.ledger_integrity_snapshots` | NEW |
| IdempotencyRecord | `platform.idempotency_keys` | Existing — no schema change |
| RetrySchedule | `config/reliability.php` + ConfigurationService | NEW (config-only, no DB table) |
| OrphanTransaction | per-module transaction tables | Existing — add `dead_lettered_at` column |
