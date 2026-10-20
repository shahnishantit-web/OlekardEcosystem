# Data Model — E07 Wallet Ledger

All three entities live in the `ledger` PostgreSQL schema. Models use `DB::connection('platform')` with explicit schema-qualified table names for atomicity with `OutboxEventPublisher`. Migrations use `DB::connection('ledger')`.

---

## 1. `ledger.ledger_accounts`

Stores one record per registered ledger account. Accounts are never deleted.

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | `uuid` | PK, not null | UUID v7, system identifier |
| `ledger_account_reference` | `varchar(255)` | UNIQUE, not null | Opaque UUID v7 pre-assigned by consuming module (e.g., E06 pocket). The public identifier used in all cross-module calls. |
| `account_type` | `varchar(20)` | not null | ENUM: `asset`, `liability`, `income`, `expense`, `equity` |
| `normal_balance` | `varchar(10)` | not null | ENUM: `debit`, `credit`. Derived from `account_type` at registration. |
| `currency_code` | `varchar(10)` | not null | ISO 4217 (e.g., `BWP`, `USD`, `USDT`) |
| `owner_reference` | `uuid` | nullable | Opaque UUID of the owning entity (e.g., wallet pocket `id`). NULL when auto-registered via `postEntry()` (no ownership context available at that call site). MUST be non-null for explicitly registered accounts. |
| `owner_type` | `varchar(50)` | nullable | ENUM values: `customer_pocket`, `system_float`, `system_revenue`, `system_fee`, `agent_commission`, `system_suspense`. NULL when auto-registered. |
| `status` | `varchar(10)` | not null, default `active` | ENUM: `active`, `closed` |
| `created_at` | `timestamptz` | not null | UTC |
| `updated_at` | `timestamptz` | not null | UTC |
| `created_by` | `uuid` | not null | Actor UUID who registered the account |

**Indexes**:
- `PRIMARY KEY (id)`
- `UNIQUE (ledger_account_reference)`
- `INDEX (owner_reference, owner_type)` — for owner-scoped lookups (index skips NULL rows; use `WHERE owner_reference IS NOT NULL` in owner-scoped queries)
- `INDEX (currency_code, status)` — for balance check queries

**Notes**:
- Auto-registration on first `postEntry()` call uses `account_type = liability`, `normal_balance = credit` as the provisional default (pending TBD-E07-002), and sets `owner_reference = NULL`, `owner_type = NULL` (the `postEntry()` request array carries no ownership metadata per FR-E07-044). Consuming modules SHOULD use explicit `registerAccount()` calls which always populate `owner_reference` and `owner_type`.
- No `deleted_at`; accounts are never soft-deleted.
- No balance column; balance is always computed from `journal_lines` (FR-E07-024).

---

## 2. `ledger.journals`

The atomic posting unit. One row per financial event. Immutable after insert.

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | `uuid` | PK, not null | UUID v7, system identifier |
| `idempotency_key` | `varchar(255)` | UNIQUE, not null | Client-supplied `Idempotency-Key`. Unique constraint is the race guard for CC-E07-003. |
| `entry_type` | `varchar(20)` | not null | ENUM: `debit`, `credit`, `fee`, `revenue`, `commission`, `reversal` |
| `currency_code` | `varchar(10)` | not null | ISO 4217; all `journal_lines` must match |
| `status` | `varchar(10)` | not null, default `posted` | Write-once. Only value is `posted`. |
| `transaction_reference` | `varchar(255)` | nullable | Cross-module reference (e.g., E11 transaction UUID) |
| `reversed_journal_id` | `uuid` | nullable, FK → `journals(id)` | Set on reversal journals; links to the original journal being reversed |
| `correlation_id` | `varchar(255)` | not null | Request correlation ID |
| `description` | `text` | nullable | Human-readable reason for the journal |
| `posted_at` | `timestamptz` | not null | UTC timestamp of the posting |
| `created_by` | `uuid` | not null | Actor UUID (service identity for System-initiated posts) |

**Indexes**:
- `PRIMARY KEY (id)`
- `UNIQUE (idempotency_key)` — race guard
- `INDEX (transaction_reference)` — fast lookup by E11 transaction ID
- `INDEX (reversed_journal_id)` — reversal uniqueness check
- `INDEX (entry_type, status)` — filtering
- `INDEX (posted_at DESC)` — chronological queries

**Notes**:
- **No `updated_at` column** — deliberate; signals immutability.
- `status` is write-once at the application layer (FR-E07-013); no UPDATE path exists in any service.
- A journal with `reversed_journal_id` set is itself immutable; it may not be re-reversed (FR-E07-031).
- A reversal journal targets the original via `reversed_journal_id`; the original journal itself has no mutation (original `reversed_journal_id` remains null — the reversal's FK points at the original, not the other way).

---

## 3. `ledger.journal_lines`

Individual debit or credit legs. Append-only; no UPDATE or DELETE ever occurs.

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | `uuid` | PK, not null | UUID v7, system identifier |
| `journal_id` | `uuid` | not null, FK → `journals(id)` | Parent journal |
| `ledger_account_id` | `uuid` | not null, FK → `ledger_accounts(id)` | Account being debited or credited |
| `direction` | `varchar(10)` | not null | ENUM: `debit`, `credit` |
| `amount` | `bigint` | not null, CHECK > 0 | Minor currency units (e.g., thebe for BWP, cents for USD). Always positive. |
| `currency_code` | `varchar(10)` | not null | Must match parent `journal.currency_code` |
| `created_at` | `timestamptz` | not null | UTC |

**Indexes**:
- `PRIMARY KEY (id)`
- `INDEX (journal_id)` — line lookup by journal
- `INDEX (ledger_account_id, direction, amount)` — balance aggregation covering index
- `INDEX (ledger_account_id, currency_code)` — per-currency balance queries

**Notes**:
- **No `updated_at` column** — deliberate; signals append-only.
- `JournalLineModel::save()` MUST throw `LogicException` if `$this->exists === true` (FR-E07-012, SR-E07-005).
- `JournalLineModel::delete()` MUST always throw `LogicException`.
- Database-level immutability (trigger or RLS rule preventing UPDATE/DELETE) is RECOMMENDED as a defense-in-depth measure.
- `amount CHECK (amount > 0)` enforced at DB level; zero or negative amounts are also rejected at the application layer before the transaction begins (FR-E07-009).

---

## 4. Entity Relationships

```
ledger_accounts (1) ←────────────── journal_lines (N)
                                         │
journals (1) ─────────────────────────── ┘ (journal_id FK)
    │
    └── journals (self-referential, reversed_journal_id FK)
```

- One `LedgerAccount` has many `JournalLine` records (its ledger history).
- One `Journal` has one or more `JournalLine` records (at least one debit, one credit).
- `Journal.reversed_journal_id` is a self-referential FK pointing to the original journal being reversed.

---

## 5. State Transitions

### LedgerAccount.status
```
(not exists) ──register──→ active ──close──→ closed
```
Closed accounts reject new postings. No re-opening path in E07 (future epic if needed).

### Journal.status
```
(insert) → posted
```
Write-once. No transitions. Reversals create a new journal; they do not change the original's status.

---

## 6. Balance Computation (Derived; No Column)

Balance is never stored — it is always derived:

```
For normal_balance = 'credit' (liability, income, equity):
  balance = SUM(amount WHERE direction = 'credit')
           − SUM(amount WHERE direction = 'debit')
  (across all journal_lines for the account, joined to posted journals)

For normal_balance = 'debit' (asset, expense):
  balance = SUM(amount WHERE direction = 'debit')
           − SUM(amount WHERE direction = 'credit')
```

See `research.md §4` for the raw SQL query pattern.

---

## 7. Migration Naming

| File | Purpose |
|---|---|
| `2026_09_17_001100_create_ledger_accounts_table.php` | Create `ledger.ledger_accounts` |
| `2026_09_17_001200_create_ledger_journals_table.php` | Create `ledger.journals` |
| `2026_09_17_001300_create_ledger_journal_lines_table.php` | Create `ledger.journal_lines` |
| `2026_09_17_001400_seed_ledger_permissions.php` | Seed 5 `ledger.*` permissions into E04 permission store |
| `2026_09_17_001500_seed_ledger_system_accounts_stub.php` | Stub seeder for TBD-E07-001 system accounts (inserts config keys into `platform.configuration_values` with null values; populated before E08) |

All migration classes: `protected $connection = 'ledger'`.
