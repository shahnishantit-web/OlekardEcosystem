# Data Model: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Date**: 2026-10-21

---

## Schema Changes

### 1. `payment.payment_intents` — two new columns

**Migration**: `2026_10_21_000011_add_payment_intents_ledger_repair_at.php`

| Column | Type | Nullable | Default | Purpose |
|--------|------|----------|---------|---------|
| `ledger_repair_at` | TIMESTAMP WITH TIME ZONE | YES | NULL | Records when `RepairPendingAccountingJob` last attempted posting for this intent |
| `accounting_repair_attempts` | SMALLINT | NO | 0 | Count of repair attempts made by `RepairSingleAccountingJob`; triggers `ledger_failed` when >= max retries |

**Rationale for `accounting_repair_attempts`**: Stores the intent-level retry count so that each independent `RepairSingleAccountingJob` dispatch can check whether max retries are exhausted. Job-level `attempts()` resets on each dispatch; intent-level count persists across all dispatches.

**Index**: No new index required — `payment_intents_accounting_status_idx` (partial index on `accounting_status = 'pending_ledger'`) was already created in P06 migration `2026_10_21_000001_add_multi_state_to_payment_intents.php`. This index is the primary access path for `RepairPendingAccountingJob`.

---

## Domain Model Changes

### 2. `PaymentConfirmedEvent` — new field

**File**: `app/Modules/Payment/Domain/Events/PaymentConfirmedEvent.php`

| Field | Type | Value | Purpose |
|-------|------|-------|---------|
| `allocation_reference` | string (UUID v7) | = `payment_intent_id` | Stable domain allocation idempotency key for consuming modules |

This is an additive change to the event payload. Consuming modules that do not read `allocation_reference` are unaffected. The field is added to both the constructor and `toArray()`.

---

### 3. `ReconciliationExceptionType` — new enum case

**File**: `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php`

| Case | Value | Meaning |
|------|-------|---------|
| `AccountingFailure` | `'accounting_failure'` | Repair job has exhausted max retries without successfully posting the ledger journal for a captured payment |

---

## Entity State Transitions

### `payment_intents.accounting_status` — repair path

```
pending_ledger  ──(repair job succeeds)──►  ledger_posted   [terminal]
pending_ledger  ──(repair exhausted)─────►  ledger_failed   [terminal]
```

The initial set at capture time:
- Wallet payment, credit account configured: attempt journal → `ledger_posted` (success) or `pending_ledger` (exception)
- Wallet payment, credit account NOT configured (AD-08 unresolved): `pending_ledger`
- Bank payment (no `wallet_hold_id`): `pending_ledger`

Note: `LedgerFailed` (from P06 migration constraint) is now also the terminal state when repair exhausts retries. `PaymentAccountingStatus::canTransitionTo()` already allows `PendingLedger → LedgerPosted` and `PendingLedger → LedgerFailed`.

---

## Configuration Keys (P08 adds)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `payment.credit_account_{currency_code}` | string\|null | null | Org credit account ledger reference for wallet payments (per currency). Null = AD-08 unresolved → pending_ledger |
| `payment.accounting_repair_max_retries` | int | 5 | Max repair attempts before `ledger_failed` + `AccountingFailure` reconciliation exception |

---

## No New Tables

P08 does not create new tables. All state is stored in the existing `payment.payment_intents`, `payment.payment_attempts`, and `platform.outbox_events` tables.

---

## Relationship Summary

```
payment_intents (1) ──── (*) payment_attempts
payment_intents (1) ──── (0..1) outbox_events   [via aggregate_id = payment_intent_id, event_name = payment.confirmed]
payment_intents (1) ──── (0..1) reconciliation_exceptions  [type = accounting_failure]
```
