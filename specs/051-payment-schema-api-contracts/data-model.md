# Data Model: P02 — Payment Schema and API Contracts

**Date**: 2026-10-21

**Basis**: P01 ADR-E48-003, E48 migration files, repository code inspection

---

## Entities Overview

| Entity | Action | Table | Notes |
|--------|--------|-------|-------|
| PaymentIntent | EXTEND (additive migration) | `payment.payment_intents` | 5 new columns |
| PaymentAllocation | CREATE (new migration) | `payment.payment_allocations` | New table |
| PaymentAccountingStatus | CREATE (new PHP enum) | — | Domain enum |
| PaymentFulfilmentStatus | CREATE (new PHP enum) | — | Domain enum |
| PaymentAllocationStatus | CREATE (new PHP enum) | — | Domain enum |
| PaymentAllocationModel | CREATE (new Eloquent model) | `payment.payment_allocations` | New model |
| PaymentIntentModel | MODIFY ($fillable + casts) | — | Add 5 new casts |

---

## 1. PaymentIntent (extended)

**Table**: `payment.payment_intents` (existing — additive columns only)

### Existing columns (do not modify)

`id`, `reference`, `purpose_type`, `purpose_reference`, `organisation_id`, `payer_actor_id`, `currency_code`, `amount_minor`, `status`, `idempotency_key`, `correlation_id`, `metadata`, `expires_at`, `succeeded_at`, `failed_at`, `cancelled_at`, `created_at`, `updated_at`

### New columns (P02 additive migration)

| Column | Type | Nullable | Default | Constraint | Purpose |
|--------|------|----------|---------|------------|---------|
| `accounting_status` | varchar(32) | NOT NULL | `'pending_ledger'` | CHECK IN ('pending_ledger','ledger_posted','ledger_failed') | Independent ledger state dimension |
| `fulfilment_status` | varchar(32) | NOT NULL | `'pending'` | CHECK IN ('pending','fulfilled','fulfilment_failed') | Independent domain fulfilment state |
| `version` | bigint | NOT NULL | `1` | — | Optimistic locking counter |
| `fee_quote_minor` | bigint | NOT NULL | `0` | CHECK ≥ 0 | Immutable fee snapshot at intent creation |
| `fee_quote_provider_code` | varchar(64) | NULL | — | — | Provider code for the quoted fee (nullable until AD-12 resolved for bank payments) |

### New indices (P02 additive migration)

```sql
CREATE INDEX payment_intents_accounting_status_idx
ON payment.payment_intents (accounting_status)
WHERE accounting_status = 'pending_ledger';

CREATE INDEX payment_intents_fulfilment_status_idx
ON payment.payment_intents (fulfilment_status)
WHERE fulfilment_status = 'pending';
```

### State machines (three independent dimensions)

**Dimension 1 — Finance status** (existing `status` column):
```
pending → processing → awaiting_action → requires_confirmation → succeeded ↘
       ↘ failed                                                              refunding → refunded
       ↘ cancelled                                                           ↘ partially_refunded
       ↘ expired
```

**Dimension 2 — Accounting status** (`accounting_status` column, P02):
```
pending_ledger → ledger_posted  (terminal)
              → ledger_failed   (terminal, manual recovery required)
```

**Dimension 3 — Fulfilment status** (`fulfilment_status` column, P02):
```
pending → fulfilled         (terminal)
        → fulfilment_failed (terminal, retry from domain module)
```

**Transition rules**:
- `accounting_status` may only advance when `status = 'succeeded'` or `status = 'refunding'`
- `fulfilment_status` may only advance when `accounting_status = 'ledger_posted'`
- These rules are enforced by `PaymentOrchestrationService`, not by DB constraints

---

## 2. PaymentAllocation (new)

**Table**: `payment.payment_allocations` (new table, P02)

| Column | Type | Nullable | Default | Constraint |
|--------|------|----------|---------|------------|
| `id` | uuid | NOT NULL | — | PK |
| `payment_intent_id` | uuid | NOT NULL | — | FK → payment_intents.id |
| `allocation_type` | varchar(64) | NOT NULL | — | CHECK IN ('org_disbursement','olekard_fee','agent_commission','platform_reserve','other') — open list pending FD02 |
| `recipient_type` | varchar(64) | NOT NULL | — | CHECK IN ('organisation','olekard','agent','system') |
| `recipient_reference` | varchar(255) | NULL | — | UUID or external ref; nullable for OleKard system allocations |
| `amount_minor` | bigint | NOT NULL | — | CHECK > 0 |
| `currency_code` | char(3) | NOT NULL | — | ISO 4217 |
| `status` | varchar(32) | NOT NULL | `'allocated'` | CHECK IN ('allocated','settled','refunded','cancelled') |
| `idempotency_key` | varchar(255) | NOT NULL | — | Unique |
| `created_at` | timestamptz | NOT NULL | now() | — |
| `updated_at` | timestamptz | NOT NULL | now() | — |

**Indices**:
- UNIQUE on `(idempotency_key)`
- UNIQUE on `(payment_intent_id, allocation_type, recipient_reference)` WHERE `status != 'cancelled'` (partial unique)
- INDEX on `(payment_intent_id, status)`

**Service-layer invariant** (not DB-enforced): `SUM(amount_minor WHERE status != 'cancelled')` for a given `payment_intent_id` MUST NOT exceed `payment_intents.amount_minor`.

**Immutability rule**: `amount_minor` and `recipient_reference` are write-once. Only `status` is mutable after creation.

---

## 3. PaymentAccountingStatus (new PHP enum)

**File**: `app/Modules/Payment/Domain/Enums/PaymentAccountingStatus.php`

```
PendingLedger = 'pending_ledger'   — default; awaiting P08 acknowledgement
LedgerPosted  = 'ledger_posted'    — terminal; journal entry confirmed
LedgerFailed  = 'ledger_failed'    — terminal; requires manual investigation
```

Methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`

---

## 4. PaymentFulfilmentStatus (new PHP enum)

**File**: `app/Modules/Payment/Domain/Enums/PaymentFulfilmentStatus.php`

```
Pending           = 'pending'           — default; awaiting domain module fulfilment
Fulfilled         = 'fulfilled'         — terminal; business obligation delivered
FulfilmentFailed  = 'fulfilment_failed' — terminal; domain module reported failure
```

Methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`

---

## 5. PaymentAllocationStatus (new PHP enum)

**File**: `app/Modules/Payment/Domain/Enums/PaymentAllocationStatus.php`

```
Allocated = 'allocated' — created; awaiting settlement
Settled   = 'settled'   — terminal; funds transferred to recipient
Refunded  = 'refunded'  — terminal; refund processed
Cancelled = 'cancelled' — terminal; allocation voided (e.g., failed payment)
```

Methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`

---

## 6. PaymentAllocationModel (new Eloquent model)

**File**: `app/Modules/Payment/Infrastructure/Models/PaymentAllocationModel.php`

**Pattern**: Final class, `$connection = 'payment'`, `$table = 'payment.payment_allocations'`, `$keyType = 'string'`, `$incrementing = false`

**$fillable**: `id, payment_intent_id, allocation_type, recipient_type, recipient_reference, amount_minor, currency_code, status, idempotency_key`

**casts()**: 
```
'status'        => PaymentAllocationStatus::class
'amount_minor'  => 'integer'
'created_at'    => 'immutable_datetime'
'updated_at'    => 'immutable_datetime'
```

**Relations**: `belongsTo(PaymentIntentModel::class, 'payment_intent_id')`

---

## 7. PaymentIntentModel (modified — no new file, update existing)

**File**: `app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php`

**$fillable additions**:
```
'accounting_status',
'fulfilment_status',
'version',
'fee_quote_minor',
'fee_quote_provider_code',
```

**casts() additions**:
```
'accounting_status' => PaymentAccountingStatus::class,
'fulfilment_status' => PaymentFulfilmentStatus::class,
'version'           => 'integer',
'fee_quote_minor'   => 'integer',
```

**Relation addition**:
```php
public function allocations(): HasMany
{
    return $this->hasMany(PaymentAllocationModel::class, 'payment_intent_id');
}
```

---

## Entity Relationships

```
PaymentIntent (1) ──── (0..N) PaymentAttempt
             (1) ──── (0..N) PaymentRefund
             (1) ──── (0..N) PaymentAllocation  ← NEW (P02)
```

---

## OpenAPI Schema Additions

### NextAction (replace flat object with discriminated union)

```yaml
NextAction:
  oneOf:
    - $ref: '#/components/schemas/NextActionRedirect'
    - $ref: '#/components/schemas/NextActionOtpVerification'
    - $ref: '#/components/schemas/NextActionPollingRequired'
    - $ref: '#/components/schemas/NextActionWalletStepUp'
  discriminator:
    propertyName: type
```

Four typed schemas:
- `NextActionRedirect`: `type: redirect`, `redirect_url` (uri), `expires_at` (datetime)
- `NextActionOtpVerification`: `type: otp_verification`, `masked_destination` (string), `expires_at` (datetime)
- `NextActionPollingRequired`: `type: polling_required`, `poll_interval_seconds` (int), `max_attempts` (int)
- `NextActionWalletStepUp`: `type: wallet_step_up`, `required_method` (string)

### PaginatedMeta component

```yaml
PaginatedMeta:
  type: object
  required: [current_page, per_page, total, last_page]
  properties:
    current_page: {type: integer}
    per_page: {type: integer}
    total: {type: integer}
    last_page: {type: integer}
```

### ErrorCode component

```yaml
ErrorCode:
  type: string
  enum:
    - INSUFFICIENT_FUNDS
    - STEP_UP_REQUIRED
    - PAYMENT_INTENT_NOT_FOUND
    - DUPLICATE_ATTEMPT
    - INVALID_STATE_TRANSITION
    - CAPABILITY_UNSUPPORTED
    - PROVIDER_CIRCUIT_OPEN
    - REFUND_LIMIT_EXCEEDED
    - IDEMPOTENCY_KEY_REQUIRED
    - UNAUTHENTICATED
    - ACCESS_DENIED
    - VALIDATION_ERROR
    - PRODUCTION_BLOCKED
```
