# Data Model: P07 — Durable Idempotency, Concurrency and Recovery

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md)

---

## New Entity: PaymentIdempotencyRecord

**Table**: `payment.payment_idempotency_records`
**Laravel Model**: `App\Modules\Payment\Infrastructure\Models\PaymentIdempotencyRecordModel`
**Connection**: `payment`

### Fields

| Column | PHP Type | DB Type | Nullable | Notes |
|--------|----------|---------|----------|-------|
| `id` | string (UUID v7) | UUID | NO | Primary key |
| `actor_id` | string | VARCHAR(255) | NO | Authenticated subject ID |
| `scope` | string | VARCHAR(64) | NO | e.g. `payment.intent.create` |
| `idempotency_key` | string | VARCHAR(255) | NO | Client-supplied key |
| `request_hash` | string | CHAR(64) | NO | SHA-256 hex of canonical request body |
| `response_status` | int | SMALLINT | NO | HTTP status code (200, 201, 422…) |
| `response_body` | array | JSONB | NO | Full response payload |
| `created_at` | Carbon | TIMESTAMPTZ | NO | Auto-managed |
| `expires_at` | Carbon | TIMESTAMPTZ | NO | `created_at + 90 days` |

### Indexes

- **PRIMARY**: `id`
- **UNIQUE**: `(actor_id, scope, idempotency_key)` — enforces one record per operation per actor
- **INDEX**: `expires_at` — used by the pruning command

### Relationships

- None (standalone record; references intents/attempts only via audit context, not FK)

### Validation Rules

- `scope` must be one of: `payment.intent.create`, `payment.attempt.initiate`, `payment.outcome`
- `request_hash` must be exactly 64 hex characters
- `response_status` must be a valid HTTP status code (100–599)
- `expires_at` must be in the future at creation time

---

## Modified Entity: ReconciliationExceptionType (Enum Extension)

**File**: `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php`

### New Case

```
case DuplicateCapture = 'duplicate_capture';
```

**Meaning**: The provider confirmed a successful charge under a second, distinct `provider_reference_id` for an already-succeeded attempt. This represents a real double-charge requiring operator-driven compensation.

**Distinct from**:
- `ExcessCapture`: captured amount slightly exceeds intent amount (single charge, amount mismatch)
- `DuplicateTransaction`: existing case for provider-side duplicate detection at the attempt level

### Context in ReconciliationExceptionModel

When `exception_type = 'duplicate_capture'`, the following existing columns carry specific meaning:

| Column | Value |
|--------|-------|
| `provider_reference_id` | Original (already-recorded) provider reference |
| `detected_amount_minor` | The duplicate captured amount (typically = intent amount) |
| `expected_amount_minor` | The intent amount (what was authorised once) |
| `description` | `"Duplicate capture: original ref {X}, duplicate ref {Y}"` |

The `duplicate_provider_reference_id` is stored in the `description` field (no schema change needed; the existing `description` VARCHAR captures both refs as human-readable text).

---

## Modified Entity: PaymentAttempt (No Schema Change)

No migration needed for P07 crash recovery. The `payment_attempts` table already has:

| Column | Purpose in P07 |
|--------|----------------|
| `operation_id` | UUID v7 generated at attempt creation; passed to adapters; used by crash recovery job as fallback query identifier |
| `provider_reference_id` | Provider-assigned reference; used by crash recovery when non-null (preferred over operation_id) |
| `status` | Job filters for `status = 'submitting'` |
| `initiated_at` | Timestamp used by crash recovery to detect stuck attempts (`initiated_at < NOW() - threshold`) |

---

## Migration Summary

### New Migration: `2026_10_21_000010_create_payment_idempotency_records`

```sql
-- Up
CREATE TABLE payment.payment_idempotency_records (
    id             UUID         NOT NULL,
    actor_id       VARCHAR(255) NOT NULL,
    scope          VARCHAR(64)  NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    request_hash   CHAR(64)     NOT NULL,
    response_status SMALLINT    NOT NULL,
    response_body  JSONB        NOT NULL DEFAULT '{}',
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    expires_at     TIMESTAMPTZ  NOT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX payment_idempotency_records_actor_scope_key_idx
    ON payment.payment_idempotency_records (actor_id, scope, idempotency_key);

CREATE INDEX payment_idempotency_records_expires_at_idx
    ON payment.payment_idempotency_records (expires_at);

-- Down
DROP TABLE IF EXISTS payment.payment_idempotency_records;
```

---

## Value Objects (New)

### IdempotencyResult

**File**: `app/Modules/Payment/Domain/ValueObjects/IdempotencyResult.php`

```
class IdempotencyResult {
    bool    $isReplay        // true = cached response found
    bool    $isConflict      // true = key reuse with different hash (reject with 422)
    int     $responseStatus  // HTTP status of cached response (when isReplay)
    array   $responseBody    // Response body (when isReplay)
}
```

Used by `PaymentIdempotencyService::check()` to communicate result to controllers.

---

## Scope Reference for Idempotency Records

| Scope String | Endpoint | Description |
|---|---|---|
| `payment.intent.create` | `POST /v1/payment/intents` | Create or resume a payment intent |
| `payment.attempt.initiate` | `POST /v1/payment/intents/{id}/attempts` | Initiate a payment attempt |
| `payment.outcome` | `POST /v1/payment/intents/{id}/attempts/{id}/outcome` | Record provider outcome |
