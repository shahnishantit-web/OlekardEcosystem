# Data Model: P06 — Intent, Attempt and Payment State Engine

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md)
**Research**: [research.md](research.md)

---

## Entities Modified

### `payment_attempts` (existing table: `payment.payment_attempts`)

**New columns**:

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `operation_id` | UUID | NOT NULL | `gen_random_uuid()` | Provider-side dedup handle; generated at attempt-create time; immutable |
| `quote_id` | UUID | NULL | — | FK to `payment.payment_quotes.id`; the active quote at attempt time |
| `quote_version` | INT | NULL | — | Version of the quote at attempt time |

**Unique index**: `UNIQUE INDEX payment_attempts_operation_id_unique ON payment.payment_attempts(operation_id)`

**New enum values** (application-level; PHP `PaymentAttemptStatus`):

| Value | Replaces | Notes |
|-------|----------|-------|
| `submitting` | `initiated` | Persisted BEFORE adapter call; intent transitions to `processing` on this |
| `unknown` | `timed_out` | Timeout fired; outcome unresolved; NOT terminal; hold preserved |

**Backward compatibility**: `initiated` and `timed_out` remain valid DB values for existing rows; state machine accepts them with the same transition rules as `submitting` and `unknown` respectively.

**Updated `PaymentAttemptStatus` PHP enum**:

```php
enum PaymentAttemptStatus: string
{
    // New P06 states
    case Submitting   = 'submitting';
    case Unknown      = 'unknown';

    // Existing states (unchanged DB values)
    case Initiated      = 'initiated';    // deprecated alias for Submitting
    case Processing     = 'processing';
    case AwaitingAction = 'awaiting_action';
    case Succeeded      = 'succeeded';
    case Failed         = 'failed';
    case Cancelled      = 'cancelled';
    case Expired        = 'expired';
    case TimedOut       = 'timed_out';    // deprecated alias for Unknown

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Expired => true,
            default       => false,  // Unknown and TimedOut are NOT terminal
        };
    }
}
```

**Updated transition table** (both in `PaymentAttemptStatus::transitions()` and `PaymentAttemptStateMachine`):

| From | Allowed To |
|------|-----------|
| `submitting` | `processing`, `awaiting_action`, `succeeded`, `failed`, `unknown` |
| `initiated` (legacy) | `processing`, `awaiting_action`, `succeeded`, `failed`, `unknown`, `cancelled`, `expired` |
| `processing` | `succeeded`, `failed`, `unknown` |
| `awaiting_action` | `processing`, `succeeded`, `failed`, `cancelled`, `expired`, `unknown` |
| `unknown` | `succeeded`, `failed`, `expired` |
| `timed_out` (legacy) | `succeeded`, `failed`, `expired` |
| `succeeded` | _(terminal)_ |
| `failed` | _(terminal)_ |
| `cancelled` | _(terminal)_ |
| `expired` | _(terminal)_ |

---

### `payment_intents` (existing table: `payment.payment_intents`)

No new columns. Existing `accounting_status` and `fulfilment_status` columns are now populated by `handleOutcome()` as described in research Decision 7.

---

### `reconciliation_exceptions` (existing table: `payment.reconciliation_exceptions`)

**New enum cases** in `ReconciliationExceptionType` PHP enum:

| Case | Value | Raised When |
|------|-------|-------------|
| `ContradictoryEvidence` | `contradictory_evidence` | A `failed` (or other conflicting) callback arrives for a `succeeded` attempt |
| `ExcessCapture` | `excess_capture` | Captured amount exceeds `intent.amount_minor + payment.excess_capture_tolerance_minor` |

**Existing relevant cases** (unchanged):
- `UnknownOutcome = 'unknown_outcome'` — timeout fired, no definitive provider status

**No schema change needed** for the type column — it stores the string value and the CHECK constraint (if any) is non-blocking since new values are application-controlled.

---

## New Domain Exceptions (PHP)

| Class | HTTP Status | Error Code | Description |
|-------|-------------|------------|-------------|
| `UnsafeProviderSwitchException` | 409 | `UNSAFE_PROVIDER_SWITCH` | Different provider requested when existing UNKNOWN attempt may have charged |

---

## `PaymentInitiateRequest` Value Object (additive change)

Add `operationId` as a new nullable constructor parameter (default `null`) for backward compatibility with adapters that don't consume it:

```php
final readonly class PaymentInitiateRequest
{
    public function __construct(
        public string  $paymentIntentId,
        public string  $paymentAttemptId,
        public int     $amountMinor,
        public string  $currencyCode,
        public string  $payerActorId,
        public string  $correlationId,
        public string  $idempotencyKey,
        public ?string $operationId = null,   // NEW: P06 FR-04
        public ?string $walletId = null,
        public ?string $stepUpToken = null,
        public ?string $sessionId = null,
    ) {}
}
```

---

## Migration

**File**: `database/migrations/2026_10_21_000001_p06_payment_attempt_state_engine.php`

Operations (all on `payment.payment_attempts`):
1. `ADD COLUMN operation_id UUID NOT NULL DEFAULT gen_random_uuid()`
2. `CREATE UNIQUE INDEX payment_attempts_operation_id_unique ON payment.payment_attempts (operation_id)`
3. `ADD COLUMN quote_id UUID NULL`
4. `ADD COLUMN quote_version INT NULL`

No column renames or enum type alterations — backward compatible with existing rows.

---

## Platform Config Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `payment.excess_capture_tolerance_minor` | int | `0` | Max cents above `intent.amount_minor` before `EXCESS_CAPTURE` exception |

Stored via `ConfigurationServiceInterface` in the `payment` domain.

---

## Entity Relationships (unchanged topology, new columns)

```
payment_intents (1) ──< payment_attempts (N)
payment_attempts (N) >── payment_quotes (1)  [quote_id FK, nullable]
payment_intents (1) ──< reconciliation_exceptions (N)
payment_attempts (1) ──< reconciliation_exceptions (N)
```
