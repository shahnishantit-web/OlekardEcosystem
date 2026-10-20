# Data Model — E21 Fees & Pricing

**Database connection**: `platform` (schema: `fees`)

---

## Entities

### FeeRule (`fees.fee_rules`)

Central configuration table. One row per fee rule. Unique constraint enforces at most one active rule per (transaction_type, currency, subject_type, subject_id) combination.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `transaction_type` | varchar(20) | NOT NULL | CHECK: see below |
| `fee_type` | varchar(12) | NOT NULL | CHECK: `fixed`, `percentage` |
| `rate` | decimal(10,4) | nullable | Required when fee_type=percentage; must be > 0 and <= 100 |
| `amount_minor` | bigint | nullable | Required when fee_type=fixed; must be > 0 |
| `min_fee_minor` | bigint | nullable | Percentage rules only; must be > 0 if set |
| `max_fee_minor` | bigint | nullable | Percentage rules only; must be > 0 if set; must be >= min_fee_minor |
| `currency` | char(3) | NOT NULL, default 'BWP' | ISO 4217 |
| `subject_type` | varchar(10) | NOT NULL | CHECK: `global`, `customer`, `agent` |
| `subject_id` | uuid | nullable | FK to customer/agent; NULL when subject_type='global' |
| `valid_from` | timestamptz | NOT NULL | Inclusive effective start |
| `valid_to` | timestamptz | nullable | Inclusive effective end; NULL = indefinite |
| `status` | varchar(12) | NOT NULL, default 'active' | CHECK: `active`, `expired`, `deactivated`, `superseded` |
| `pricing_version_id` | uuid | nullable, FK | References `fees.pricing_versions(id)` |
| `created_by` | uuid | NOT NULL | FK to admin user (auth context) |
| `idempotency_key` | varchar(255) | nullable, UNIQUE | Client-supplied on creation; stored for idempotency replay |
| `created_at` | timestamptz | NOT NULL, default NOW() | |
| `updated_at` | timestamptz | NOT NULL | |

**CHECK constraints:**
```sql
-- transaction_type
CHECK (transaction_type IN ('transfer','funding','withdrawal','bill_payment','qr_payment','cash_in','cash_out','settlement'))

-- fee_type
CHECK (fee_type IN ('fixed','percentage'))

-- subject_type
CHECK (subject_type IN ('global','customer','agent'))

-- status
CHECK (status IN ('active','expired','deactivated','superseded'))

-- rate range for percentage
CHECK (fee_type != 'percentage' OR (rate > 0 AND rate <= 100))

-- amount for fixed
CHECK (fee_type != 'fixed' OR amount_minor > 0)

-- min/max only on percentage
CHECK (fee_type = 'percentage' OR (min_fee_minor IS NULL AND max_fee_minor IS NULL))

-- min <= max when both set
CHECK (min_fee_minor IS NULL OR max_fee_minor IS NULL OR min_fee_minor <= max_fee_minor)
```

**Partial unique index (enforces active-rule uniqueness):**
```sql
CREATE UNIQUE INDEX uidx_fee_rules_active_scope
ON fees.fee_rules (transaction_type, currency, subject_type, COALESCE(subject_id::text, ''))
WHERE status = 'active';
```

**Indexes:**
```sql
CREATE INDEX idx_fee_rules_global_lookup
ON fees.fee_rules (transaction_type, currency, subject_type, status, valid_from, valid_to)
WHERE status = 'active' AND subject_type = 'global';

CREATE INDEX idx_fee_rules_subject_lookup
ON fees.fee_rules (transaction_type, currency, subject_type, subject_id, status)
WHERE status = 'active' AND subject_type != 'global';
```

---

### Fee (`fees.fees`)

One row per executed transaction that had a non-zero fee. The UNIQUE constraint on `transaction_id` is the idempotency guard for `recordFee()`.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `transaction_id` | uuid | NOT NULL, UNIQUE | Idempotency key; FK to originating transaction |
| `fee_rule_id` | uuid | NOT NULL, FK | References `fees.fee_rules(id)` |
| `amount_minor` | bigint | NOT NULL | Calculated fee in minor units; must be > 0 |
| `currency` | char(3) | NOT NULL | ISO 4217 |
| `ledger_entry_id` | uuid | NOT NULL | Journal ID returned by LedgerModuleContract::postEntry() |
| `reversal_ledger_entry_id` | uuid | nullable | Journal ID of the compensating entry (on reversal) |
| `status` | varchar(10) | NOT NULL, default 'posted' | CHECK: `posted`, `reversed` |
| `created_at` | timestamptz | NOT NULL, default NOW() | |
| `updated_at` | timestamptz | NOT NULL | |

**CHECK constraints:**
```sql
CHECK (status IN ('posted','reversed'))
CHECK (amount_minor > 0)
```

**Indexes:**
```sql
CREATE INDEX idx_fees_transaction_id ON fees.fees (transaction_id);
CREATE INDEX idx_fees_fee_rule_id ON fees.fees (fee_rule_id);
CREATE INDEX idx_fees_status ON fees.fees (status);
```

---

### PricingVersion (`fees.pricing_versions`)

Optional organizational grouping for a set of fee rules published together as a named schedule. Referenced optionally by `fee_rules.pricing_version_id`.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `label` | varchar(128) | NOT NULL | Human-readable (e.g., "Q4 2026 Standard Pricing") |
| `effective_from` | timestamptz | NOT NULL | |
| `effective_to` | timestamptz | nullable | NULL = current/indefinite |
| `is_published` | boolean | NOT NULL, default false | Published versions are immutable |
| `created_by` | uuid | NOT NULL | FK to admin user |
| `created_at` | timestamptz | NOT NULL, default NOW() | |

---

## State Transitions

### FeeRule.status

```
[on create]  → active
active       → expired      (valid_to date passes; scheduled or PATCH explicit)
active       → deactivated  (admin DELETE endpoint)
active       → superseded   (when rule is updated: old rule → superseded; new rule → active)
```

### Fee.status

```
[on recordFee()] → posted
posted           → reversed   (when parent transaction is reversed)
```

---

## Entity Relationships

```
PricingVersion
    └── has many FeeRule (via pricing_version_id, nullable)

FeeRule
    └── has many Fee (via fee_rule_id)

Fee
    ├── belongs to FeeRule
    └── references LedgerEntry (ledger_entry_id, cross-schema FK — not enforced by DB)
```

---

## Database Schema Setup

```sql
CREATE SCHEMA IF NOT EXISTS fees;
```

Connection: `platform` DB (same PostgreSQL instance as `commission`, `reconciliation` schemas).

---

## Cross-Module References (no enforced FK)

- `fees.fees.transaction_id` → transaction record (E11 module, different schema)
- `fees.fees.ledger_entry_id` → `ledger.journals.id` (E07 module, different DB connection)
- `fees.fee_rules.subject_id` → customer or agent UUID (E06/E12 modules)
- `fees.fee_rules.created_by` → admin user UUID (E04/E01 modules)

These are cross-module references maintained at the application layer, not via DB-level FKs across schema boundaries.
