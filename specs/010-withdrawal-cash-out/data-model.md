# Data Model — E10 Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Created**: 2026-09-18

---

## 1. Schema

All E10 tables reside in the `withdrawal` PostgreSQL schema. Models use:
- `$connection = 'platform'`
- `$table = 'withdrawal.{table}'`

The Wallet module's `wallet.wallet_holds` table (E06) stores hold records. E10 references holds by `wallet_hold_id` on `WithdrawalTransaction`.

---

## 2. Entities

### 2.1 WithdrawalTransaction

Primary aggregate for the entire withdrawal lifecycle.

| Column                     | Type                   | Constraints                              | Description                                          |
|----------------------------|------------------------|------------------------------------------|------------------------------------------------------|
| `id`                       | `uuid`                 | PK                                       | UUID v7 primary key                                  |
| `wallet_id`                | `uuid`                 | NOT NULL                                 | FK concept → `wallet.wallets.id`                     |
| `pocket_currency_code`     | `char(3)`              | NOT NULL                                 | Currency of the source pocket                        |
| `customer_id`              | `uuid`                 | NOT NULL                                 | FK concept → `platform.users.id`                     |
| `channel`                  | `varchar(32)`          | NOT NULL                                 | `mobile_money` \| `bank_transfer`                    |
| `status`                   | `varchar(20)`          | NOT NULL, DEFAULT `pending`              | See state machine §3                                 |
| `gross_amount`             | `bigint`               | NOT NULL, CHECK > 0                      | Amount to withdraw (minor currency units)            |
| `fee_amount`               | `bigint`               | NOT NULL, DEFAULT 0, CHECK >= 0          | Fee charged                                          |
| `net_payout_amount`        | `bigint`               | NOT NULL, CHECK > 0                      | `gross_amount − fee_amount`                          |
| `fee_rate_bps`             | `integer`              | NOT NULL, DEFAULT 0                      | Fee rate at confirmation time (basis points)         |
| `idempotency_key`          | `varchar(255)`         | UNIQUE (partial: NOT NULL)               | Client-provided idempotency key for initiation       |
| `payout_destination`       | `jsonb`                | NOT NULL                                 | Encrypted destination details (Laravel encrypted cast)|
| `wallet_hold_id`           | `uuid`                 | NULLABLE                                 | Reference to `wallet.wallet_holds.id`; set at confirmation |
| `provider_reference_id`    | `varchar(255)`         | NULLABLE                                 | External provider's payout transaction ID            |
| `risk_check_status`        | `varchar(32)`          | NOT NULL, DEFAULT `pending`              | `pending` → `approved` \| `rejected` at confirmation |
| `failure_reason`           | `varchar(128)`         | NULLABLE                                 | Machine-readable failure reason                      |
| `ledger_journal_id`        | `varchar(100)`         | NULLABLE                                 | E07 journal ID posted at `completed` transition      |
| `transaction_reference`    | `uuid`                 | NOT NULL, UNIQUE                         | Cross-module stable reference for E11 history        |
| `initiated_by`             | `uuid`                 | NOT NULL                                 | Actor who created the transaction                    |
| `completed_at`             | `timestamptz`          | NULLABLE                                 | Timestamp of `completed` transition                  |
| `failed_at`                | `timestamptz`          | NULLABLE                                 | Timestamp of `failed` or `cancelled` transition      |
| `created_at`               | `timestamptz`          | NOT NULL                                 | Record creation                                      |
| `updated_at`               | `timestamptz`          | NOT NULL                                 | Last modification                                    |

**Check constraints**:
- `net_payout_amount = gross_amount - fee_amount`
- `status IN ('pending','processing','completed','failed','cancelled')`
- `channel IN ('mobile_money','bank_transfer')`
- `gross_amount > 0`, `fee_amount >= 0`, `net_payout_amount > 0`

**Indexes**:
- `UNIQUE (idempotency_key)` WHERE NOT NULL
- `UNIQUE (transaction_reference)`
- `INDEX (customer_id, created_at DESC)` — history queries
- `INDEX (customer_id, status, created_at DESC)` — daily limit queries (partial: status IN pending,processing,completed)
- `INDEX (provider_reference_id)` — callback lookup
- `INDEX (status, created_at ASC)` WHERE status = 'processing' — expiry command

---

### 2.2 WithdrawalProviderCallback

Raw callback log. Written before any processing; used for idempotency and audit.

| Column                       | Type           | Constraints        | Description                                    |
|------------------------------|----------------|--------------------|------------------------------------------------|
| `id`                         | `uuid`         | PK                 | UUID v7 primary key                            |
| `withdrawal_transaction_id`  | `uuid`         | NOT NULL           | FK concept → `withdrawal_transactions.id`      |
| `provider`                   | `varchar(64)`  | NOT NULL           | Provider identifier (e.g., `mock`, `mpesa`)    |
| `provider_reference_id`      | `varchar(255)` | NOT NULL           | Provider's transaction reference               |
| `status`                     | `varchar(32)`  | NOT NULL           | `success` \| `failure` \| `unknown`            |
| `raw_payload`                | `jsonb`        | NOT NULL           | Full unmodified callback body                  |
| `signature_valid`            | `boolean`      | NOT NULL           | Whether HMAC/signature verification passed     |
| `received_at`                | `timestamptz`  | NOT NULL           | Callback receipt timestamp                     |
| `processed_at`               | `timestamptz`  | NULLABLE           | Set when callback processing completes         |

**Indexes**:
- `INDEX (withdrawal_transaction_id)` — lookup callbacks by transaction
- `INDEX (provider_reference_id)` — idempotency check
- `INDEX (received_at DESC)` — recent callbacks

---

### 2.3 WithdrawalReceipt

Immutable record created at `completed` transition. Attempts to update or delete throw `WithdrawalReceiptImmutableException`.

| Column                       | Type           | Constraints       | Description                                       |
|------------------------------|----------------|-------------------|---------------------------------------------------|
| `id`                         | `uuid`         | PK                | UUID v7 primary key                               |
| `receipt_reference`          | `uuid`         | NOT NULL, UNIQUE  | Human-facing receipt ID                           |
| `withdrawal_transaction_id`  | `uuid`         | NOT NULL, UNIQUE  | FK concept → `withdrawal_transactions.id`         |
| `gross_amount`               | `bigint`       | NOT NULL          | Amount the customer withdrew                      |
| `fee_amount`                 | `bigint`       | NOT NULL          | Fee charged                                       |
| `net_payout_amount`          | `bigint`       | NOT NULL          | Amount delivered to external account              |
| `currency_code`              | `char(3)`      | NOT NULL          | Currency                                          |
| `channel`                    | `varchar(32)`  | NOT NULL          | `mobile_money` \| `bank_transfer`                 |
| `payout_destination_masked`  | `varchar(255)` | NOT NULL          | Last 4 chars of destination; no PII               |
| `provider_reference_id`      | `varchar(255)` | NULLABLE          | External provider's reference                     |
| `customer_id`                | `uuid`         | NOT NULL          | Owning customer                                   |
| `wallet_id`                  | `uuid`         | NOT NULL          | Source wallet                                     |
| `ledger_journal_id`          | `varchar(100)` | NOT NULL          | E07 journal ID                                    |
| `completed_at`               | `timestamptz`  | NOT NULL          | Completion timestamp                              |

**Indexes**:
- `UNIQUE (receipt_reference)`
- `UNIQUE (withdrawal_transaction_id)` — exactly one receipt per completed transaction
- `INDEX (customer_id)` — customer receipt queries

---

### 2.4 wallet.wallet_holds (Wallet Module — referenced, not owned by E10)

Located in `wallet.wallet_holds` — managed by the Wallet module (E06). E10 calls `WalletModuleContract::createHold()` and `releaseHold()` to interact with this table. E10 stores the returned `hold_id` in `withdrawal_transactions.wallet_hold_id`.

Relevant columns (read-only reference):
- `id` UUID PK
- `pocket_id` UUID FK → `wallet.currency_pockets.id`
- `amount` BIGINT (held amount)
- `currency_code` CHAR(3)
- `status` VARCHAR (`active` | `released` | `expired`)
- `transaction_reference` VARCHAR(255) — E10 sets this to the `WithdrawalTransaction.transaction_reference`
- `expires_at` TIMESTAMPTZ
- `released_at` TIMESTAMPTZ

---

## 3. State Machine

### WithdrawalTransaction Status

```
                  ┌──────────┐
                  │  pending  │◄─── initiation (POST /v1/withdrawals)
                  └─────┬────┘
                        │ confirm (PIN valid, balance OK, risk approved, hold created)
                        │ [FAIL] → cancelled (risk rejected)
                        ▼
                  ┌──────────────┐
                  │  processing  │◄─── hold active; payout dispatched
                  └──────┬───┬──┘
         success callback│   │failure callback / expiry
                         ▼   ▼
                  ┌─────────┐ ┌────────┐
                  │completed│ │ failed │
                  └─────────┘ └────────┘
                  
   ┌──────────┐  customer cancel
   │  pending  │─────────────────► cancelled
   └──────────┘
```

| From          | To            | Trigger                                             | Guard                                    |
|---------------|---------------|-----------------------------------------------------|------------------------------------------|
| `pending`     | `processing`  | Customer confirms (PIN valid + balance + risk pass) | All confirmation checks pass             |
| `pending`     | `cancelled`   | Risk rejected OR customer cancel                    | `isCancellable()` = true when `pending`  |
| `processing`  | `completed`   | Provider success callback                           | `status === processing`                  |
| `processing`  | `failed`      | Provider failure callback OR expiry command         | `status === processing`                  |

All other transitions throw `WithdrawalNotModifiableException` → `422 TRANSACTION_NOT_MODIFIABLE`.

### WalletHold Status (in wallet.wallet_holds — E06 values)

| Value      | Meaning in E10 context                                         |
|------------|----------------------------------------------------------------|
| `active`   | Hold created; funds reserved; payout in flight                 |
| `released` | Hold released on failure, cancellation, or expiry              |
| `expired`  | Hold expired by Wallet module's `ExpireWalletHoldsCommand`     |

E10 calls `releaseHold()` in both the success and failure paths:
- **Success path**: ledger journal debit posted FIRST inside DB transaction, THEN `releaseHold()` called.
- **Failure path**: `releaseHold()` called, no ledger entry.

---

## 4. Enums

### WithdrawalStatus
```php
enum WithdrawalStatus: string {
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
}
```

### WithdrawalChannel
```php
enum WithdrawalChannel: string {
    case MobileMoney  = 'mobile_money';
    case BankTransfer = 'bank_transfer';
}
```

### WithdrawalFailureReason
```php
enum WithdrawalFailureReason: string {
    case ProviderDeclined    = 'provider_declined';
    case ProcessingTimeout   = 'processing_timeout';
    case JobDispatchFailed   = 'job_dispatch_failed';
    case AmountMismatch      = 'amount_mismatch';
    case RiskRejected        = 'risk_rejected';
    case AdminForceFailed    = 'admin_force_failed';
    case SystemError         = 'system_error';
}
```

---

## 5. Relationships

```
WithdrawalTransaction ─── has one ──► WithdrawalReceipt
WithdrawalTransaction ─── has many ─► WithdrawalProviderCallback
WithdrawalTransaction ─── references ► wallet.wallet_holds (via wallet_hold_id)
WithdrawalTransaction ─── references ► wallet.wallets (via wallet_id)
WithdrawalTransaction ─── references ► platform.users (via customer_id)
WithdrawalTransaction ─── references ► ledger.journals (via ledger_journal_id, cross-module)
WithdrawalReceipt ─────── belongs to ─ WithdrawalTransaction
```

---

## 6. Configuration Keys

Config keys read from `platform.configuration_values`:

| Key                                              | Default           | Description                                  |
|--------------------------------------------------|-------------------|----------------------------------------------|
| `withdrawal.fee.mobile_money.{currency}`         | `0`               | Fee rate in basis points                     |
| `withdrawal.fee.bank_transfer.{currency}`        | `0`               | Fee rate in basis points                     |
| `withdrawal.limit.mobile_money.{tier}.min_amount`| `100`             | Min per-transaction amount (minor units)     |
| `withdrawal.limit.mobile_money.{tier}.max_amount`| `1000000`         | Max per-transaction amount                   |
| `withdrawal.limit.mobile_money.{tier}.daily_max` | `5000000`         | Daily maximum                                |
| `withdrawal.limit.bank_transfer.{tier}.min_amount`| `100`            | Min per-transaction amount                   |
| `withdrawal.limit.bank_transfer.{tier}.max_amount`| `1000000`        | Max per-transaction amount                   |
| `withdrawal.limit.bank_transfer.{tier}.daily_max`| `5000000`         | Daily maximum                                |
| `withdrawal.hold_ttl_minutes`                    | `60`              | Minutes before stale hold expires            |
| `withdrawal.channel.mobile_money.available_currencies` | `BWP`       | Comma-separated available currencies         |
| `withdrawal.channel.bank_transfer.available_currencies`| `BWP`       | Comma-separated available currencies         |
| `ledger.payout_transit.{currency}`               | *(TBD-E10-004)*   | Ledger account ref for payout transit        |
| `ledger.fee_revenue.{currency}`                  | *(TBD-E10-002)*   | Ledger account ref for fee revenue           |

---

## 7. New WalletModuleContract Methods

Two methods are added to extend the existing `WalletModuleContract` interface:

```php
/**
 * Create a hold on a wallet pocket, reducing available_balance atomically.
 *
 * Delegates to WalletHoldService::place().
 * Throws InsufficientBalanceException if available_balance < amount.
 * Throws WalletNotTransactableException if pocket is suspended or frozen.
 *
 * @return string Hold ID (UUID v7)
 */
public function createHold(
    string $walletId,
    string $currencyCode,
    int $amount,
    string $actorId,
    string $correlationId,
    string $transactionReference,
    ?\DateTimeImmutable $expiresAt = null,
): string;

/**
 * Release a previously placed hold, restoring available_balance.
 *
 * Delegates to WalletHoldService::release().
 * Idempotent: releasing an already-released hold is a no-op.
 */
public function releaseHold(string $holdId, string $correlationId): void;
```

`WalletModuleContractAdapter` is updated to implement both methods.

---

## 8. PayoutProviderAdapter Interface

Defined at `App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter`:

```php
interface PayoutProviderAdapter {
    public function initiate(array $transaction): array;
    public function verifyCallback(array $payload, string $signature, string $provider): array;
}
```

`initiate()` input keys: `id`, `gross_amount`, `net_payout_amount`, `currency_code`, `channel`, `payout_destination` (decrypted array), `transaction_reference`, `correlation_id`.

`verifyCallback()` returns: `valid` (bool), `provider_reference_id` (string|null), `status` (`success`|`failure`|`unknown`), `amount` (int|null), `currency` (string|null).

`MockPayoutProviderAdapter`: Returns `status=initiated`, `provider_reference_id='mock-payout-' . $transaction['id']`.
