# Data Model — E29 Multi-Currency Wallet

**DB Schema:** `multi_currency` (PostgreSQL schema)

---

## Entity: Currency

**Table:** `multi_currency.currencies`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| code | VARCHAR(3) | UNIQUE NOT NULL | ISO 4217 (e.g., "USD", "BWP", "ZAR") |
| name | VARCHAR(100) | NOT NULL | e.g., "US Dollar" |
| symbol | VARCHAR(10) | NOT NULL | e.g., "$", "P" |
| decimal_places | SMALLINT | NOT NULL DEFAULT 2 | |
| is_active | BOOLEAN | NOT NULL DEFAULT false | Admin-controlled |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:** `idx_currencies_active` ON (is_active) WHERE is_active = true

**Rules:** Only active currencies may have new wallets created.

---

## Entity: CurrencyWallet

**Table:** `multi_currency.currency_wallets`

Links a customer to their currency-specific wallet (one per currency, backed by an E06 wallet record).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| customer_id | UUID | NOT NULL | FK → identity.users(id) |
| currency_code | VARCHAR(3) | NOT NULL | FK → multi_currency.currencies(code) |
| wallet_id | UUID | NOT NULL | FK → wallet.wallets(id) — the E06-managed wallet |
| pocket_ledger_account_reference | VARCHAR(36) | NOT NULL | Reference to the E07 pocket ledger account for this wallet |
| idempotency_key | VARCHAR(255) | UNIQUE NOT NULL | Client-supplied key; same-key replay returns existing wallet |
| is_active | BOOLEAN | NOT NULL DEFAULT true | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `UNIQUE (customer_id, currency_code)` — enforces one-wallet-per-currency rule
- `UNIQUE (idempotency_key)` — idempotent wallet creation
- `idx_currency_wallets_customer` ON (customer_id)
- `idx_currency_wallets_wallet_id` ON (wallet_id)

---

## Entity: FxRate

**Table:** `multi_currency.fx_rates`

Versioned, time-bounded exchange rates administered by back-office operators.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| from_currency_code | VARCHAR(3) | NOT NULL | FK → multi_currency.currencies(code) |
| to_currency_code | VARCHAR(3) | NOT NULL | FK → multi_currency.currencies(code) |
| rate | DECIMAL(18,8) | NOT NULL | Units of to_currency per 1 unit of from_currency |
| spread | DECIMAL(6,5) | NOT NULL DEFAULT 0 | Fee percentage expressed as decimal (e.g., 0.015 = 1.5%) |
| effective_from | TIMESTAMPTZ | NOT NULL | When this rate becomes active |
| effective_to | TIMESTAMPTZ | NULL | NULL = currently active |
| created_by | UUID | NOT NULL | FK → identity.users(id) — admin maker |
| approved_by | UUID | NULL | FK → identity.users(id) — admin checker |
| change_request_id | UUID | NULL | FK → platform.configuration_change_requests(id) |
| status | VARCHAR(20) | NOT NULL DEFAULT 'active' | `pending_approval` \| `active` \| `superseded` |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `UNIQUE (from_currency_code, to_currency_code) WHERE status = 'active'` — one active rate per pair
- `idx_fx_rates_pair_active` ON (from_currency_code, to_currency_code, effective_from DESC)

**Rules:**
- Rates are immutable once created; a new rate supersedes the previous by setting `status = 'superseded'` and `effective_to` on the old record.
- New rates enter `pending_approval` state and require a second admin (checker) to activate via E28 maker-checker flow.
- `from_currency_code != to_currency_code` check constraint.

---

## Entity: FxQuote

**Table:** `multi_currency.fx_quotes`

Holds in-flight rate-locked quotes before conversion confirmation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| customer_id | UUID | NOT NULL | FK → identity.users(id) |
| from_currency_code | VARCHAR(3) | NOT NULL | |
| to_currency_code | VARCHAR(3) | NOT NULL | |
| from_amount | DECIMAL(18,4) | NOT NULL | Amount customer wants to convert |
| to_amount | DECIMAL(18,4) | NOT NULL | Computed at quote time (locked) |
| fee_amount | DECIMAL(18,4) | NOT NULL DEFAULT 0 | FX fee deducted from from_amount |
| rate | DECIMAL(18,8) | NOT NULL | Locked rate (snapshot of fx_rates.rate) |
| fx_rate_id | UUID | NOT NULL | FK → multi_currency.fx_rates(id) |
| status | VARCHAR(20) | NOT NULL DEFAULT 'quoted' | quoted \| confirmed \| expired \| cancelled |
| expires_at | TIMESTAMPTZ | NOT NULL | now() + 60 seconds |
| idempotency_key | VARCHAR(255) | UNIQUE NOT NULL | |
| fx_transaction_id | UUID | NULL | FK → multi_currency.fx_transactions(id), set on confirm |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_fx_quotes_customer_status` ON (customer_id, status)
- `idx_fx_quotes_expires_at` ON (expires_at) WHERE status = 'quoted'

**State transitions:** `quoted → confirmed` (on confirm within TTL) | `quoted → expired` (TTL elapsed, lazy expiry on next access)

---

## Entity: FxTransaction

**Table:** `multi_currency.fx_transactions`

Immutable record of a completed (or failed) currency conversion.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| customer_id | UUID | NOT NULL | FK → identity.users(id) |
| quote_id | UUID | NOT NULL | FK → multi_currency.fx_quotes(id) |
| from_wallet_id | UUID | NOT NULL | FK → wallet.wallets(id) |
| to_wallet_id | UUID | NOT NULL | FK → wallet.wallets(id) |
| from_currency_code | VARCHAR(3) | NOT NULL | |
| to_currency_code | VARCHAR(3) | NOT NULL | |
| from_amount | DECIMAL(18,4) | NOT NULL | |
| to_amount | DECIMAL(18,4) | NOT NULL | |
| fee_amount | DECIMAL(18,4) | NOT NULL DEFAULT 0 | |
| rate | DECIMAL(18,8) | NOT NULL | Rate applied at execution |
| status | VARCHAR(20) | NOT NULL | completed \| failed |
| failure_reason | TEXT | NULL | |
| idempotency_key | VARCHAR(255) | UNIQUE NOT NULL | Mirrors quote idempotency_key |
| completed_at | TIMESTAMPTZ | NULL | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_fx_transactions_customer` ON (customer_id, created_at DESC)
- `idx_fx_transactions_quote` ON (quote_id)

**Rules:** Records are never updated after creation (append-only for completed/failed). A failed transaction does not alter ledger balances.

---

## Relationships

```
identity.users (E01)
  └─< multi_currency.currency_wallets >── wallet.wallets (E06)
  └─< multi_currency.fx_quotes
        └── multi_currency.fx_transactions

multi_currency.currencies
  └─< multi_currency.currency_wallets
  └─< multi_currency.fx_rates

multi_currency.fx_rates
  └─< multi_currency.fx_quotes (rate snapshot)

platform.configuration_change_requests (E28)
  └── multi_currency.fx_rates (optional link)
```

---

## Financial Accounts (Ledger placeholders)

The following virtual accounts must be seeded in the E07 ledger for FX journal posting:

| Account | Purpose |
|---|---|
| `multi_currency.fx_suspense` | Transit account for FX conversion (nets to zero per transaction) |
| `platform.fx_revenue` | Captures FX fee income |
