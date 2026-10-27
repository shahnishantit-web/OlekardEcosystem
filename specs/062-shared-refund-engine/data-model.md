# Data Model: P15 — Shared Refund Engine

## Existing Tables (referenced, not changed structurally)

### `payment.payment_intents`
- `id` UUID v7 PK
- `accounting_status` VARCHAR — existing enum (`pending_ledger`, `ledger_posted`, `ledger_failed`)
- `succeeded_attempt_id` UUID FK → `payment_attempts`
- *(no changes to this table in P15)*

### `payment.payment_attempts`
- `id` UUID v7 PK
- `payment_intent_id` UUID FK
- `provider_code` VARCHAR
- `amount_minor` BIGINT
- `currency_code` VARCHAR(3)
- `status` VARCHAR — `succeeded` required for refundability

---

## Extended Table: `payment.payment_refunds`

### New Columns (added by P15 migration)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `provider_submitted_ref` | VARCHAR(500) | YES | Provider reference returned when refund is submitted to bank; used to match callbacks |
| `submitted_at` | TIMESTAMPTZ | YES | When the adapter submitted the refund to the provider |
| `pending_at` | TIMESTAMPTZ | YES | When the refund entered `pending` state |
| `unknown_at` | TIMESTAMPTZ | YES | When the refund entered `unknown` state |
| `refund_accounting_status` | VARCHAR(64) | YES | `pending_ledger_refund` \| `ledger_refund_posted` \| `pending_ledger_refund_fee_blocked` |
| `refund_ledger_journal_id` | UUID | YES | Journal ID returned by `LedgerModuleContract::postEntry()` for the compensating entry |
| `query_attempts` | SMALLINT | NO DEFAULT 0 | Count of `QueryRefundStatusJob` invocations |

### Updated CHECK Constraint (status values)

```sql
CHECK (status IN (
  'requested',
  'pending_approval',
  'processing',
  'submitted',
  'pending',
  'unknown',
  'confirmed',
  'definitive_failure',
  'failed',
  'cancelled',
  'rejected'
))
```

*(Existing `succeeded` maps to `confirmed` for new refunds; existing DB rows with `succeeded` remain valid — the migration adds new values, not removes old ones.)*

### New Indexes

```sql
-- Fast lookup of in-flight refunds for a given attempt (reservation guard)
CREATE INDEX payment_refunds_attempt_inflight_idx
  ON payment.payment_refunds (payment_attempt_id, status)
  WHERE status NOT IN ('confirmed', 'failed', 'cancelled', 'rejected', 'definitive_failure');

-- Lookup by provider_submitted_ref for callback matching
CREATE UNIQUE INDEX payment_refunds_provider_submitted_ref_idx
  ON payment.payment_refunds (provider_submitted_ref)
  WHERE provider_submitted_ref IS NOT NULL;
```

---

## State Machine: `PaymentRefundStatus`

```
                         ┌─────────────┐
                         │  Requested  │ ← initial state (written then immediately transitioned)
                         └──────┬──────┘
                    ┌───────────┴───────────┐
                    ▼                       ▼
           ┌────────────────┐       ┌─────────────┐
           │ PendingApproval│       │  Processing  │ ← when below approval threshold
           └───────┬────────┘       └──────┬───────┘
           approve │                       │
                   ▼                       │
           ┌─────────────────────────────┐ │
           │       Processing            │◄┘
           └──────────────┬──────────────┘
                          │ initiate()
                          ▼
                  ┌───────────────┐
                  │   Submitted   │ ← adapter accepted the refund
                  └───────┬───────┘
             async        │
          ┌───────────────┼────────────────┐
          ▼               ▼                ▼
     ┌─────────┐   ┌─────────────┐  ┌──────────────────┐
     │ Pending │   │   Unknown   │  │ DefinitiveFailure │ [TERMINAL]
     └────┬────┘   └──────┬──────┘  └──────────────────┘
          │         resolved│
     confirmed│             ▼
          │         ┌─────────────┐
          └────────►│  Confirmed  │ [TERMINAL — equivalent to prior 'succeeded']
                    └─────────────┘

Also terminal: Cancelled, Rejected, Failed (legacy compat)
```

**Transition table** (`canTransitionTo()`):

| From | Valid targets |
|------|---------------|
| `Requested` | `PendingApproval`, `Processing`, `Cancelled` |
| `PendingApproval` | `Processing`, `Rejected`, `Cancelled` |
| `Processing` | `Submitted`, `Failed`, `Cancelled` |
| `Submitted` | `Pending`, `Confirmed`, `Unknown`, `DefinitiveFailure` |
| `Pending` | `Confirmed`, `DefinitiveFailure`, `Unknown` |
| `Unknown` | `Confirmed`, `DefinitiveFailure` |
| `Confirmed`, `DefinitiveFailure`, `Failed`, `Cancelled`, `Rejected` | none (terminal) |

---

## New Domain Contracts

### `RefundEligibilityHandlerInterface`

```php
interface RefundEligibilityHandlerInterface
{
    // Purpose type this handler is registered for (e.g., 'funeral_contribution')
    public function supportedPurposeType(): string;

    // Returns true if the entitlement may be financially reversed.
    // Throws RefundEligibilityDeniedException with a human-readable reason if ineligible.
    public function checkRefundEligibility(
        string $purposeReference,
        string $actorId,
        int    $requestedAmountMinor,
        string $correlationId,
    ): bool;
}
```

### Extended `PaymentRefundRepositoryInterface`

```php
// New method — sums amount_minor for all non-terminal refunds for the attempt
// (includes pending_approval, processing, submitted, pending, unknown)
public function sumReservedRefundsForAttempt(string $attemptId): int;

// New method — filtered paginated list for admin
public function listFiltered(array $filters, int $page, int $perPage): LengthAwarePaginator;
```

---

## New Value Objects / Events

### `RefundAccountingStatus` (constants or PHP enum)

| Value | Meaning |
|-------|---------|
| `pending_ledger_refund` | Wallet refund confirmed; ledger posting in progress or failed |
| `ledger_refund_posted` | Compensating journal posted successfully |
| `pending_ledger_refund_fee_blocked` | Bank refund confirmed; accounting blocked pending AD-16 |

### `RefundFailedEvent` (new outbox event)

```
event_type:        refund.failed
payload fields:    payment_intent_id, payment_refund_id, reason, is_definitive, occurred_at
outbox_type:       integration
idempotency_key:   refund.failed:{refund_id}
```

### `CustomerRefundListItem` (API response shape)

```
id                   UUID
status               string (PaymentRefundStatus value)
status_label         string (human-readable, e.g., "Being processed", "Funds returned")
amount_minor         int
currency_code        string
requested_at         ISO 8601
confirmed_at         ISO 8601 | null
funds_returned       bool  (true only when status = 'confirmed')
```
