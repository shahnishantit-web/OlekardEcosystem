# API Contract: Outbox Admin (P11)

**Module**: Platform / Operations | **Feature**: [../spec.md](../spec.md) | **Research**: [../research.md](../research.md)

**Authorization**: All endpoints require `operations.dead_letter.manage` permission (existing E04 permission).

**Base path**: `/v1/operations/outbox`

---

## Endpoints

### GET /v1/operations/outbox/health

Returns queue health metrics per event type. Operators use this to detect stuck or lagging events.

**Authorization**: `operations.dead_letter.manage`

**Request**: No body. Optional query parameter:
- `event_name` (string, optional) — filter to a single event type

**Response 200 OK**

```json
{
  "data": [
    {
      "event_name": "payment.confirmed",
      "pending_count": 0,
      "dead_letter_count": 0,
      "oldest_pending_age_seconds": null
    },
    {
      "event_name": "payment.failed",
      "pending_count": 3,
      "dead_letter_count": 1,
      "oldest_pending_age_seconds": 342
    }
  ],
  "meta": {
    "queried_at": "2026-10-21T10:05:00Z"
  }
}
```

**Field definitions**:

| Field | Type | Description |
|-------|------|-------------|
| `event_name` | string | Dot-notation event type (e.g., `payment.confirmed`) |
| `pending_count` | int | Rows with `status IN ('pending', 'failed')` |
| `dead_letter_count` | int | Rows with `status = 'dead_letter'` |
| `oldest_pending_age_seconds` | int\|null | Seconds since oldest pending/failed row was created; null if none |

**Errors**:

| Status | Code | Description |
|--------|------|-------------|
| 401 | `unauthenticated` | No valid bearer token |
| 403 | `forbidden` | Actor lacks `operations.dead_letter.manage` permission |

---

### GET /v1/operations/outbox/dead-letters

Returns paginated list of outbox events in `dead_letter` status.

**Authorization**: `operations.dead_letter.manage`

**Query parameters**:
- `event_name` (string, optional) — filter by event type
- `correlation_id` (UUID string, optional) — filter by correlation ID (per FR-011)
- `page` (int, default 1)
- `per_page` (int, default 20, max 100)

**Response 200 OK**

```json
{
  "data": [
    {
      "id": "01932b2a-1234-7000-8000-aabbccddeeff",
      "event_name": "payment.confirmed",
      "aggregate_id": "01932b1c-0000-7000-8000-112233445566",
      "aggregate_type": "PaymentAttempt",
      "attempts": 5,
      "last_error": "Connection timeout on consumer PaymentConfirmedListener",
      "correlation_id": "01932b00-0000-7000-8000-998877665544",
      "causation_id": null,
      "created_at": "2026-10-21T09:00:00Z",
      "updated_at": "2026-10-21T09:45:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

**Note**: The `payload` JSONB field is intentionally excluded from the list response to avoid exposing PII at scale. `last_error` is a metadata string (not payload) and IS included per FR-011. Use a single-item endpoint (future) if full payload inspection is needed.

**Errors**:

| Status | Code | Description |
|--------|------|-------------|
| 401 | `unauthenticated` | No valid bearer token |
| 403 | `forbidden` | Actor lacks `operations.dead_letter.manage` permission |
| 422 | `validation_error` | Invalid query parameters |

---

### POST /v1/operations/outbox/dead-letters/{id}/replay

Replays a dead-letter outbox event by resetting it to `pending`. This causes `OutboxDispatcherJob` to retry dispatch on the next poll cycle.

**Authorization**: `operations.dead_letter.manage`

**Path parameter**:
- `id` (UUID) — outbox event ID

**Request**: No body required.

**Response 200 OK**

```json
{
  "data": {
    "id": "01932b2a-1234-7000-8000-aabbccddeeff",
    "event_name": "payment.confirmed",
    "status": "pending",
    "attempts": 0,
    "replayed_by": "01910000-0000-7000-8000-000000000001",
    "replayed_at": "2026-10-21T10:00:00Z"
  }
}
```

**Side effects**:
1. Sets `status = 'pending'`, `attempts = 0`, `next_attempt_at = now()` on the outbox row.
2. Writes an audit record (`outbox.dead_letter.replay`) — see data-model.md for shape.

**Idempotency**: If event is already `pending` (replayed twice in quick succession), responds 200 with current state and does NOT write a second audit record.

**Errors**:

| Status | Code | Description |
|--------|------|-------------|
| 401 | `unauthenticated` | No valid bearer token |
| 403 | `forbidden` | Actor lacks `operations.dead_letter.manage` permission |
| 404 | `not_found` | No outbox event with that ID |
| 409 | `conflict` | Event is not in `dead_letter` status (already active or dispatched) |

---

## Route registration

Routes are appended to `routes/modules/reliability.php` under the existing `operations` middleware group:

```php
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/v1/operations/outbox/health',             [OutboxAdminController::class, 'health']);
    Route::get('/v1/operations/outbox/dead-letters',       [OutboxAdminController::class, 'deadLetters']);
    Route::post('/v1/operations/outbox/dead-letters/{id}/replay', [OutboxAdminController::class, 'replay']);
});
```

---

## Event listener registration

`PaymentServiceProvider::boot()` registers listeners:

```php
Event::listen('payment.confirmed', PaymentConfirmedListener::class);
Event::listen('payment.failed',    PaymentFailedListener::class);
Event::listen('payment.reversed',  PaymentReversedListener::class);
Event::listen('refund.confirmed',  RefundConfirmedListener::class);
```

`InProcessDriver::dispatch()` calls `Event::dispatch($eventName, $payload)` — the registered listeners receive `$payload` as their first constructor/handle argument.

---

## Integration event interface extension

`IntegrationEventInterface` gains an optional method. Existing implementors are unaffected:

```php
// Existing: all implementations have these
public function eventName(): string;
public function payload(): array;
// ...

// New (P11): checked via method_exists() in OutboxEventPublisher
public function causationId(): ?string; // returns null for root events
```

`OutboxEventPublisher::buildRow()` addition:

```php
'causation_id' => method_exists($event, 'causationId') ? $event->causationId() : null,
```

---

## Constraints and invariants

1. **No wallet balance mutation** — listeners create notifications and allocations only; no `WalletModuleContract` calls.
2. **No ledger postings** — `LedgerModuleContract` is NOT called from P11.
3. **Audited replay** — every `replay` call writes an audit record before responding 200.
4. **At-least-once delivery** — replay does not guarantee exactly-once; consumer idempotency (unique constraints) absorbs duplicates.
5. **Authorization enforced server-side** — `operations.dead_letter.manage` checked before any read or write.
6. **No payload in list response** — PII minimisation per FR-015 / constitution P6.
