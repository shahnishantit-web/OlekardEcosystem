# Data Model: P11 — Outbox Inbox Notifications and Domain Delivery

**Feature**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Research**: [research.md](research.md)

---

## No new tables required

P11 introduces no new database migrations. All storage needs are satisfied by existing tables:

| Table | Schema | Purpose in P11 |
|-------|--------|----------------|
| `platform.outbox_events` | existing | Event source; `causation_id` column present (always null before P11) |
| `notification.notifications` | existing | Deduplication via `(source_event_id, notification_type)` UNIQUE |
| `payment.payment_allocations` | existing | Deduplication via `idempotency_key` UNIQUE |

---

## Existing: `platform.outbox_events`

No schema changes. The `causation_id` column already exists but is hardcoded to null by `OutboxEventPublisher.buildRow()`.

| Column | Type | P11 Change |
|--------|------|------------|
| `id` | UUID | No change |
| `event_name` | varchar | No change |
| `aggregate_id` | UUID | No change |
| `aggregate_type` | varchar | No change |
| `payload` | jsonb | No change |
| `event_version` | varchar | No change |
| `correlation_id` | UUID | No change |
| `causation_id` | UUID nullable | **P11: populated from `$event->causationId()` when non-null** |
| `status` | enum | No change (`pending`, `processing`, `dispatched`, `failed`, `dead_letter`) |
| `attempts` | int | No change |
| `next_attempt_at` | timestamp | No change |
| `created_at` | timestamp | No change |
| `updated_at` | timestamp | No change |

### Outbox event status lifecycle

```
pending → processing → dispatched
                     ↘ failed → (retry) → dispatched
                                         ↘ dead_letter
dead_letter → (admin replay) → pending
```

---

## Existing: `notification.notifications`

No schema changes. `source_event_id` stores the outbox event ID that triggered the notification; combined with `notification_type` it provides idempotent deduplication.

| Column | Type | P11 Usage |
|--------|------|-----------|
| `id` | UUID | Generated |
| `source_event_id` | UUID nullable | **P11: set to outbox event ID from payload** |
| `notification_type` | varchar | **P11: e.g., `payment_confirmed`, `payment_failed`, `refund_confirmed`** |
| `recipient_actor_id` | UUID | Payer actor from event payload |
| `channel` | varchar | e.g., `push`, `sms`, `email` |
| `status` | enum | `queued`, `sent`, `failed` |
| `created_at` | timestamp | No change |

**Unique constraint reused**: `UNIQUE (source_event_id, notification_type)` — if listener runs twice, `INSERT ... ON CONFLICT DO NOTHING` ensures idempotent delivery.

---

## Existing: `payment.payment_allocations`

No schema changes. `idempotency_key` deduplicates allocation inserts when a listener processes the same `payment.confirmed` event more than once.

| Column | Type | P11 Usage |
|--------|------|-----------|
| `id` | UUID | Generated |
| `idempotency_key` | varchar | **P11: set to `allocationReference` from `PaymentConfirmedEvent` payload** |
| `payment_intent_id` | UUID | From event payload |
| `payment_attempt_id` | UUID | From event payload |
| `amount_minor` | int | From event payload |
| `currency_code` | varchar | From event payload |
| `created_at` | timestamp | No change |

**Unique constraint reused**: `UNIQUE (idempotency_key)` — `INSERT ... ON CONFLICT DO NOTHING` ensures exactly-once allocation effect.

---

## New PHP entities (application layer only, no DB migration)

### `OutboxQueueHealthDto`

Read-only DTO — populated by `OutboxQueueHealthService`; not persisted.

| Field | Type | Description |
|-------|------|-------------|
| `eventName` | string | e.g., `payment.confirmed` |
| `pendingCount` | int | rows with `status IN ('pending', 'failed')` |
| `deadLetterCount` | int | rows with `status = 'dead_letter'` |
| `oldestPendingAgeSeconds` | int\|null | `now() - min(created_at)` where status pending/failed; null if none |

### `OutboxDeadLetterDto`

Read-only DTO — populated by admin list endpoint.

| Field | Type | Description |
|-------|------|-------------|
| `id` | string (UUID) | Outbox event ID |
| `eventName` | string | e.g., `payment.confirmed` |
| `aggregateId` | string (UUID) | Domain aggregate ID |
| `aggregateType` | string | e.g., `PaymentAttempt` |
| `attempts` | int | Number of dispatch attempts |
| `correlationId` | string\|null | Request correlation ID |
| `causationId` | string\|null | Prior event ID (null for root events in P11) |
| `createdAt` | string (ISO-8601) | When event was first published |
| `updatedAt` | string (ISO-8601) | When last updated (dead_letter transition) |

---

## Event payload contracts (canonical shapes)

These are the payloads that `InProcessDriver` passes to Laravel event listeners. Listeners must read these fields to build their domain effects.

### `payment.confirmed`

```json
{
  "eventId":          "<uuid>",
  "paymentIntentId":  "<uuid>",
  "paymentAttemptId": "<uuid>",
  "payerActorId":     "<uuid>",
  "amountMinor":      12500,
  "currencyCode":     "BWP",
  "allocationReference": "<string>",
  "correlationId":    "<uuid>",
  "occurredAt":       "2026-10-21T10:00:00Z"
}
```

### `payment.failed`

```json
{
  "eventId":          "<uuid>",
  "paymentIntentId":  "<uuid>",
  "paymentAttemptId": "<uuid>",
  "payerActorId":     "<uuid>",
  "amountMinor":      12500,
  "currencyCode":     "BWP",
  "reason":           "insufficient_funds",
  "correlationId":    "<uuid>",
  "occurredAt":       "2026-10-21T10:00:01Z"
}
```

### `payment.reversed`

```json
{
  "eventId":          "<uuid>",
  "paymentIntentId":  "<uuid>",
  "paymentAttemptId": "<uuid>",
  "payerActorId":     "<uuid>",
  "amountMinor":      12500,
  "currencyCode":     "BWP",
  "reversalReason":   "admin_correction",
  "correlationId":    "<uuid>",
  "occurredAt":       "2026-10-21T10:00:05Z"
}
```

### `refund.confirmed`

```json
{
  "eventId":          "<uuid>",
  "paymentIntentId":  "<uuid>",
  "refundId":         "<uuid>",
  "payerActorId":     "<uuid>",
  "amountMinor":      5000,
  "currencyCode":     "BWP",
  "correlationId":    "<uuid>",
  "occurredAt":       "2026-10-21T10:01:00Z"
}
```

---

## State transitions by listener

### `PaymentConfirmedListener`

1. Attempt `INSERT INTO payment.payment_allocations (idempotency_key = payload.allocationReference) ON CONFLICT DO NOTHING`
2. Attempt `INSERT INTO notification.notifications (source_event_id = payload.eventId, notification_type = 'payment_confirmed') ON CONFLICT DO NOTHING`
3. Both wrapped in a single DB transaction to prevent partial commit.

### `PaymentFailedListener`

1. Attempt `INSERT INTO notification.notifications (source_event_id = payload.eventId, notification_type = 'payment_failed') ON CONFLICT DO NOTHING`

### `PaymentReversedListener`

1. Attempt `INSERT INTO notification.notifications (source_event_id = payload.eventId, notification_type = 'payment_reversed') ON CONFLICT DO NOTHING`

### `RefundConfirmedListener`

1. Attempt `INSERT INTO notification.notifications (source_event_id = payload.eventId, notification_type = 'refund_confirmed') ON CONFLICT DO NOTHING`

---

## `OutboxEventPublisher` — causation_id wiring

Modified `buildRow()` method adds:

```php
'causation_id' => method_exists($event, 'causationId') ? $event->causationId() : null,
```

No schema change required — column already exists.

---

## `IntegrationEventInterface` — causation ID contract

```php
interface IntegrationEventInterface
{
    // Existing methods unchanged
    public function eventName(): string;
    public function payload(): array;
    public function aggregateId(): string;
    public function aggregateType(): string;
    public function eventVersion(): string;
    public function correlationId(): ?string;

    // NEW (P11): optional; null for root events; populated by events that chain from a prior outbox event
    // NOTE: method_exists() check in OutboxEventPublisher preserves backward compatibility;
    //       existing event classes that do NOT implement this method continue to work unchanged.
}
```

Events that want to declare causation implement the method explicitly:

```php
public function causationId(): ?string
{
    return $this->causationId;
}
```

---

## Audit record shape (dead-letter replay)

Written to the platform audit log when an admin replays a dead-letter outbox event:

| Field | Value |
|-------|-------|
| `action` | `outbox.dead_letter.replay` |
| `actor_id` | Authenticated admin actor ID |
| `subject_type` | `OutboxEvent` |
| `subject_id` | Outbox event UUID |
| `metadata.event_name` | e.g., `payment.confirmed` |
| `metadata.aggregate_id` | Domain aggregate UUID |
| `metadata.previous_status` | `dead_letter` |
| `metadata.new_status` | `pending` |
