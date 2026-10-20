# Data Model: E13 — Agent Float Management
**Date**: 2026-09-21

All amounts are stored as positive integers in minor currency units (e.g., BWP thebe: 1 BWP = 100).

---

## Schema changes to existing tables

### `wallet.wallets` — extend with `wallet_type` discriminator

**Migration**: `2026_09_26_001000_add_wallet_type_to_wallets.php` (wallet connection)

```
ALTER TABLE wallet.wallets
  ADD COLUMN wallet_type VARCHAR(20) NOT NULL DEFAULT 'customer';

ALTER TABLE wallet.wallets
  ADD CONSTRAINT wallets_wallet_type_check
  CHECK (wallet_type IN ('customer', 'agent_float'));

-- Drop old single-column partial unique index (prevents dual wallet per user)
DROP INDEX IF EXISTS wallets_owner_id_active_unique;

-- New compound partial unique index: one active wallet per (owner, type)
CREATE UNIQUE INDEX wallets_owner_wallettype_active_unique
  ON wallet.wallets (owner_id, wallet_type)
  WHERE status <> 'closed';
```

**Existing rows**: Backfill `SET wallet_type = 'customer'` (DEFAULT handles new rows). The partial unique index change is safe because existing customer wallets use `wallet_type = 'customer'`; agent float wallets use `wallet_type = 'agent_float'`.

---

## New tables

### `agent.float_wallets` — reference table (platform connection)

Maps each agent to their float wallet and its ledger account reference.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `agent_id` | UUID | NOT NULL, UNIQUE, FK → `agent.agents(id)` | One float wallet per agent |
| `wallet_id` | UUID | NOT NULL | Cross-schema soft ref to `wallet.wallets(id)` (no DB FK — different connections) |
| `ledger_account_reference` | VARCHAR(100) | NOT NULL | Registered with LedgerService at wallet creation |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `agent_id` (UNIQUE — enforced), `wallet_id` (non-unique for reverse lookup)

---

### `agent.float_transactions` — float transaction history (platform connection)

Records every float movement (funding, cash-in, cash-out).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `agent_id` | UUID | NOT NULL, FK → `agent.agents(id)` | |
| `float_wallet_id` | UUID | NOT NULL | Cross-schema soft ref to `wallet.wallets(id)` |
| `type` | VARCHAR(20) | NOT NULL, CHECK `('funding','cash_in','cash_out')` | Operation type |
| `amount` | BIGINT | NOT NULL, CHECK `> 0` | Minor units |
| `currency_code` | VARCHAR(10) | NOT NULL | e.g. 'BWP' |
| `direction` | VARCHAR(10) | NOT NULL, CHECK `('debit','credit')` | From agent float perspective (debit = float ↑, credit = float ↓) |
| `related_party_id` | UUID | NOT NULL | Customer UUID (cash-in/out) or admin UUID (funding) |
| `related_party_type` | VARCHAR(20) | NOT NULL, CHECK `('customer','admin')` | |
| `reference` | VARCHAR(100) | NOT NULL, UNIQUE | Human-readable reference (e.g. `FLT-{uuid_fragment}`) |
| `journal_id` | VARCHAR(100) | NOT NULL | Journal ID returned by LedgerService |
| `idempotency_key` | VARCHAR(255) | NOT NULL, UNIQUE | Client-supplied idempotency key |
| `status` | VARCHAR(20) | NOT NULL DEFAULT 'completed', CHECK `('completed','failed')` | Float transactions are synchronous; only completed/failed |
| `created_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**:
- `(agent_id, created_at DESC)` — agent transaction history queries
- `(agent_id, type, created_at DESC)` — type-filtered history
- `idempotency_key` (UNIQUE)
- `reference` (UNIQUE)

---

### `agent.float_limits` — per-agent float limits (platform connection)

One record per agent; all limit fields are nullable (TBD per TBD-E13-002). `is_tbd = true` means that dimension is not yet enforced.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `agent_id` | UUID | NOT NULL, UNIQUE, FK → `agent.agents(id)` | |
| `currency_code` | VARCHAR(10) | NOT NULL | e.g. 'BWP' |
| `max_balance` | BIGINT | NULLABLE | Max float wallet ceiling (minor units) |
| `max_balance_is_tbd` | BOOLEAN | NOT NULL DEFAULT TRUE | TBD-E13-002 |
| `per_transaction_limit` | BIGINT | NULLABLE | Max single cash-in or cash-out (minor units) |
| `per_tx_is_tbd` | BOOLEAN | NOT NULL DEFAULT TRUE | TBD-E13-002 |
| `daily_volume_limit` | BIGINT | NULLABLE | Max total float volume per calendar day (minor units) |
| `daily_vol_is_tbd` | BOOLEAN | NOT NULL DEFAULT TRUE | TBD-E13-002 |
| `low_float_threshold` | BIGINT | NULLABLE | Alert fires when balance drops below this (minor units) |
| `low_threshold_is_tbd` | BOOLEAN | NOT NULL DEFAULT TRUE | TBD-E13-002 |
| `low_float_alerted` | BOOLEAN | NOT NULL DEFAULT FALSE | Tracks whether a FloatLowAlert has been emitted since last recovery; reset when balance rises above threshold |
| `effective_from` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `created_by` | UUID | NOT NULL | Admin actor ID |
| `updated_by` | UUID | NOT NULL | Admin actor ID |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `agent_id` (UNIQUE), `(agent_id, currency_code)` (non-unique for multi-currency future)

---

## Entity relationships

```
identity.users (E02)
    ↑ owner_id FK (deferred)
wallet.wallets [wallet_type = 'agent_float']
    ↑ wallet_id (soft cross-schema ref)
agent.float_wallets
    → agent_id FK
    → ledger_account_reference (registered in ledger.ledger_accounts)
agent.agents (E12)
    ↑ agent_id FK
agent.float_limits
agent.float_transactions
    → journal_id (soft ref to ledger journal)
```

---

## State transitions

### Float wallet status (derived from `wallet.wallets.status`)

```
[not yet created]
      ↓ create (admin)
   active
      ↓ agent suspended (E12 event)   ↑ agent reactivated (E12 event)
   suspended
      ↓ agent account permanently closed (E12 event)
    closed
```

Float wallet status is never set directly via the float API — it mirrors agent lifecycle events from E12.

---

## Ledger accounts (E07 LedgerService)

| Account | `ownerType` | `accountType` | Normal balance | When registered |
|---------|-------------|---------------|----------------|-----------------|
| Float wallet | `agent_float` | `asset` | debit | At float wallet creation |
| Platform funding source | `system_float` | `asset` | debit | Pre-seeded (TBD-E13-001 config) |

**Float balance** = `LedgerModuleContract::getBalance(ledgerAccountReference)` (sum of debits minus sum of credits for debit-normal account).

---

## Daily volume tracking

Float daily volume is tracked by summing `agent.float_transactions.amount WHERE agent_id = ? AND created_at >= CURRENT_DATE AND type IN ('cash_in','cash_out')`. No separate daily-tally table is needed at this scale; the float_transactions table with the `(agent_id, type, created_at DESC)` index supports efficient per-day aggregation.
