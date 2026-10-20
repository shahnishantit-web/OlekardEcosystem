# Data Model — E15 Service & Bill Payments

All tables live in the `service` PostgreSQL schema. Foreign keys to other schemas use fully qualified references.

---

## Entity: Service (service.services)

Represents a payable service in the admin-managed catalog.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK, NOT NULL | UUID v7 |
| `name` | VARCHAR(255) | NOT NULL | Display name (e.g., "Botswana Power Corporation") |
| `category` | VARCHAR(100) | NOT NULL | UTILITIES, TELECOMS, TV, INTERNET, OTHER |
| `description` | TEXT | NULL | Optional customer-facing description |
| `required_fields` | JSONB | NOT NULL | Array of field definitions: `[{name, label, placeholder, validation_regex, max_length}]` |
| `fee_type` | VARCHAR(20) | NOT NULL | `flat` or `percentage` |
| `fee_value` | INTEGER | NOT NULL | Minor units if flat (e.g., 200 = BWP 2.00); basis points if percentage (e.g., 200 = 2%) |
| `min_amount` | INTEGER | NOT NULL | Minimum payment in minor units |
| `max_amount` | INTEGER | NOT NULL | Maximum payment in minor units |
| `currency_code` | VARCHAR(10) | NOT NULL DEFAULT 'BWP' | Payment currency |
| `provider_code` | VARCHAR(100) | NOT NULL | Adapter key (TBD — e.g., 'mock', 'bpc', 'mascom') |
| `status` | VARCHAR(20) | NOT NULL DEFAULT 'active' | `active` or `inactive` |
| `created_by` | UUID | NOT NULL | FK → identity.users.id |
| `updated_by` | UUID | NULL | FK → identity.users.id |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(status, category)`, `(provider_code)`
**Validation**: `min_amount > 0`, `max_amount >= min_amount`, `fee_value >= 0`

**State transitions**:
- `active` → `inactive` (admin deactivates)
- `inactive` → `active` (admin reactivates)

---

## Entity: ServiceTransaction (service.service_transactions)

Records a single payment attempt from initiation through to a terminal outcome.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK, NOT NULL | UUID v7 |
| `idempotency_key` | VARCHAR(255) | NOT NULL, UNIQUE | Customer-supplied; unique across all transactions |
| `customer_id` | UUID | NOT NULL | FK → identity.users.id |
| `wallet_id` | UUID | NOT NULL | FK → wallet.wallets.id |
| `service_id` | UUID | NOT NULL | FK → service.services.id |
| `customer_reference` | VARCHAR(255) | NOT NULL | Account reference entered by customer (e.g., meter number) |
| `account_name` | VARCHAR(255) | NULL | Account holder name returned by provider validation |
| `amount` | INTEGER | NOT NULL | Principal in minor units |
| `fee_amount` | INTEGER | NOT NULL | Fee in minor units (snapshot at initiation time) |
| `total_amount` | INTEGER | NOT NULL | `amount + fee_amount` |
| `currency_code` | VARCHAR(10) | NOT NULL | |
| `status` | VARCHAR(50) | NOT NULL DEFAULT 'initiated' | See status enum |
| `journal_id` | UUID | NULL | Ledger journal for wallet debit (set when pending_provider) |
| `provider_code` | VARCHAR(100) | NOT NULL | Snapshot of service provider_code at initiation |
| `provider_reference` | VARCHAR(255) | NULL | Provider's confirmation reference (set on success) |
| `failure_reason` | VARCHAR(255) | NULL | Set on failed status |
| `requery_count` | SMALLINT | NOT NULL DEFAULT 0 | Number of re-query attempts made |
| `review_flag` | BOOLEAN | NOT NULL DEFAULT false | Set true when provider response is ambiguous/malformed; flags transaction for manual investigation |
| `correlation_id` | VARCHAR(255) | NOT NULL | Propagated through ledger / events |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(customer_id, status, created_at DESC)`, `(service_id, created_at DESC)`, `(status, updated_at)` (for background jobs)

**Status enum** (`ServiceTransactionStatus`):
| Status | Meaning |
|--------|---------|
| `initiated` | Record created; no money moved yet |
| `pending_provider` | Wallet debited; awaiting provider outcome |
| `completed` | Provider confirmed success; receipt generated |
| `failed` | Provider failed or submission error; debit reversed |
| `timeout_pending_review` | No provider response after timeout; re-query in progress |
| `abandoned` | Stale `initiated` record cleaned up; no wallet effect |

**Terminal statuses**: `completed`, `failed`, `abandoned`

---

## Entity: ServiceReceipt (service.service_receipts)

Immutable record generated on payment completion. One receipt per completed transaction.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK, NOT NULL | UUID v7 |
| `transaction_id` | UUID | NOT NULL, UNIQUE | FK → service.service_transactions.id |
| `receipt_reference` | VARCHAR(255) | NOT NULL, UNIQUE | Globally unique (e.g., `RCP-{year}-{seq}` or UUID v7 formatted) |
| `service_name` | VARCHAR(255) | NOT NULL | Snapshot of service name at completion time |
| `customer_reference` | VARCHAR(255) | NOT NULL | Customer's account reference |
| `provider_confirmation_reference` | VARCHAR(255) | NULL | Provider's confirmation reference |
| `amount` | INTEGER | NOT NULL | Principal paid in minor units |
| `fee_amount` | INTEGER | NOT NULL | Fee charged in minor units |
| `total_amount` | INTEGER | NOT NULL | Total debited from wallet |
| `currency_code` | VARCHAR(10) | NOT NULL | |
| `issued_at` | TIMESTAMPTZ | NOT NULL | Timestamp of receipt creation |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Notes**: Receipt rows are never updated after creation; they are the immutable proof of payment.

---

## Entity: ServiceProviderCallback (service.service_provider_callbacks)

Raw callback payloads from providers. Used for idempotency guard and audit trail.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK, NOT NULL | UUID v7 |
| `transaction_id` | UUID | NULL | FK → service.service_transactions.id (NULL if unknown ref) |
| `provider_code` | VARCHAR(100) | NOT NULL | Adapter key |
| `provider_reference` | VARCHAR(255) | NOT NULL | Provider's transaction reference |
| `status` | VARCHAR(50) | NOT NULL | `success`, `failure`, `unknown` |
| `raw_payload` | JSONB | NOT NULL | Full inbound payload |
| `signature` | VARCHAR(500) | NULL | Signature header from provider |
| `processed` | BOOLEAN | NOT NULL DEFAULT false | True after outcome applied to transaction |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Unique constraint**: `(provider_code, provider_reference)` — prevents duplicate callback processing.

---

## Relationships

```
Service (1) ──── (N) ServiceTransaction
ServiceTransaction (1) ──── (0..1) ServiceReceipt
ServiceTransaction (1) ──── (N) ServiceProviderCallback
```

---

## Ledger Accounts

| Account Reference | Type | Owner |
|-------------------|------|-------|
| `wallet:{walletId}` | asset | wallet.wallets.id (existing, registered in E06) |
| `service_payable:{currencyCode}` | liability | service module (registered lazily on first use) |

**Journal structure for payment debit**:
```
entry_type: service_payment
lines:
  - account: wallet:{walletId}       direction: debit   amount: total_amount
  - account: service_payable:{bwp}   direction: credit  amount: total_amount
```

**Journal structure for failure reversal**:
```
entry_type: service_payment_reversal
lines:
  - account: service_payable:{bwp}   direction: debit   amount: total_amount
  - account: wallet:{walletId}       direction: credit  amount: total_amount
```

---

## Permissions (seed via migration 015400)

| Permission | Description |
|------------|-------------|
| `service.manage` | Admin: create / update / deactivate / reactivate services + force-outcome |
| `service.list` | Authenticated user: view active service catalog |
| `service.pay` | Authenticated user: initiate service payment |

---

## Configuration Stubs (seed via migration 015500)

| Key | Default | Description |
|-----|---------|-------------|
| `service.payment.validation_timeout_seconds` | `10` | Max seconds to await provider validation response |
| `service.payment.provider_timeout_seconds` | `30` | Max seconds to await provider submission response |
| `service.payment.requery_interval_seconds` | `300` | Interval between re-query attempts |
| `service.payment.requery_max_attempts` | `12` | Max re-query attempts before leaving in `timeout_pending_review` |
| `service.payment.stale_initiated_timeout_minutes` | `30` | Minutes before `initiated` transactions are abandoned |
