# Migration Contracts: P02 — Payment Schema and API Contracts

**Date**: 2026-10-21

---

## Migration 1: Add Multi-State Columns to payment_intents

**File**: `app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000001_add_multi_state_to_payment_intents.php`

**Type**: Additive (ALTER TABLE ADD COLUMN) — no rewrite, safe on live data

### up()

```
ALTER TABLE payment.payment_intents
  ADD COLUMN accounting_status varchar(32) NOT NULL DEFAULT 'pending_ledger',
  ADD COLUMN fulfilment_status varchar(32) NOT NULL DEFAULT 'pending';

ADD CONSTRAINT payment_intents_accounting_status_check
  CHECK (accounting_status IN ('pending_ledger','ledger_posted','ledger_failed'));

ADD CONSTRAINT payment_intents_fulfilment_status_check
  CHECK (fulfilment_status IN ('pending','fulfilled','fulfilment_failed'));

CREATE INDEX payment_intents_accounting_status_idx
  ON payment.payment_intents (accounting_status)
  WHERE accounting_status = 'pending_ledger';

CREATE INDEX payment_intents_fulfilment_status_idx
  ON payment.payment_intents (fulfilment_status)
  WHERE fulfilment_status = 'pending';
```

**Post-conditions**:
- All existing rows have `accounting_status = 'pending_ledger'` and `fulfilment_status = 'pending'`
- CHECK constraints are active immediately
- Two partial indices for efficient P08 query (find intents needing ledger posting)

### down()

```
DROP INDEX IF EXISTS payment.payment_intents_accounting_status_idx;
DROP INDEX IF EXISTS payment.payment_intents_fulfilment_status_idx;
ALTER TABLE payment.payment_intents
  DROP CONSTRAINT IF EXISTS payment_intents_accounting_status_check,
  DROP CONSTRAINT IF EXISTS payment_intents_fulfilment_status_check,
  DROP COLUMN IF EXISTS accounting_status,
  DROP COLUMN IF EXISTS fulfilment_status;
```

**Rollback safety**: `IF EXISTS` guards prevent failure if run twice. Does NOT affect any other column.

---

## Migration 2: Add Fee Snapshot and Version to payment_intents

**File**: `app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php`

**Type**: Additive (ALTER TABLE ADD COLUMN) — safe on live data

### up()

```
ALTER TABLE payment.payment_intents
  ADD COLUMN version bigint NOT NULL DEFAULT 1,
  ADD COLUMN fee_quote_minor bigint NOT NULL DEFAULT 0,
  ADD COLUMN fee_quote_provider_code varchar(64) NULL;

ADD CONSTRAINT payment_intents_fee_quote_minor_check
  CHECK (fee_quote_minor >= 0);
```

**Post-conditions**:
- All existing rows have `version = 1`, `fee_quote_minor = 0`, `fee_quote_provider_code = NULL`
- `fee_quote_minor >= 0` CHECK is active
- No index required (fee_quote is rarely queried directly; version is queried in WHERE clauses only with the full PK)

### down()

```
ALTER TABLE payment.payment_intents
  DROP CONSTRAINT IF EXISTS payment_intents_fee_quote_minor_check,
  DROP COLUMN IF EXISTS version,
  DROP COLUMN IF EXISTS fee_quote_minor,
  DROP COLUMN IF EXISTS fee_quote_provider_code;
```

---

## Migration 3: Create payment_allocations Table

**File**: `app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000003_create_payment_allocations_table.php`

**Type**: New table creation

### up()

```
CREATE TABLE payment.payment_allocations (
  id                   uuid          PRIMARY KEY,
  payment_intent_id    uuid          NOT NULL REFERENCES payment.payment_intents(id),
  allocation_type      varchar(64)   NOT NULL,
  recipient_type       varchar(64)   NOT NULL,
  recipient_reference  varchar(255)  NULL,
  amount_minor         bigint        NOT NULL,
  currency_code        char(3)       NOT NULL,
  status               varchar(32)   NOT NULL DEFAULT 'allocated',
  idempotency_key      varchar(255)  NOT NULL,
  created_at           timestamptz   NOT NULL DEFAULT NOW(),
  updated_at           timestamptz   NOT NULL DEFAULT NOW()
);

CONSTRAINT payment_allocations_amount_check CHECK (amount_minor > 0)
CONSTRAINT payment_allocations_status_check CHECK (status IN ('allocated','settled','refunded','cancelled'))
CONSTRAINT payment_allocations_allocation_type_check CHECK (allocation_type IN (
  'org_disbursement','olekard_fee','agent_commission','platform_reserve','other'
))
CONSTRAINT payment_allocations_recipient_type_check CHECK (recipient_type IN (
  'organisation','olekard','agent','system'
))

UNIQUE INDEX payment_allocations_idempotency_idx ON (idempotency_key)
UNIQUE INDEX payment_allocations_intent_type_recipient_idx
  ON (payment_intent_id, allocation_type, recipient_reference)
  WHERE status != 'cancelled'
INDEX payment_allocations_intent_status_idx ON (payment_intent_id, status)
```

**Post-conditions**:
- Table exists with all constraints active
- Unique partial index prevents duplicate active allocations per intent+type+recipient

### down()

```
DROP TABLE IF EXISTS payment.payment_allocations;
```

---

## Migration Safety Checklist

| Risk | Mitigation |
|------|-----------|
| Live rows fail new CHECK constraint | Defaults are always valid: 'pending_ledger', 'pending', 1, 0 — constraints pass for all existing rows |
| Long lock during ADD COLUMN | PostgreSQL ADD COLUMN with constant DEFAULT is non-blocking; no table rewrite |
| Migration run twice | All `down()` methods use `IF EXISTS` guards |
| FK violation | payment_allocations FK only constrains new inserts; no effect on existing payment_intents rows |
| CHECKs violate existing data | All new columns on payment_intents have constant defaults that pass their own CHECKs |
