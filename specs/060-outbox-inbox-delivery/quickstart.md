# Quickstart: P11 — Outbox Inbox Notifications and Domain Delivery

**Feature**: [spec.md](spec.md) | **Contract**: [contracts/outbox-admin-contract.md](contracts/outbox-admin-contract.md) | **Data model**: [data-model.md](data-model.md)

**Stack**: Laravel 11 / PHP 8.4 / PostgreSQL (Docker Compose)

---

## Prerequisites

```bash
# From wallet-ecosystem/
docker compose up -d
docker compose exec api php artisan migrate --seed
# Seed creates: admin@olekard.local / Admin123! with operations.dead_letter.manage permission
```

---

## SC-001 — Crash durability: no lost durable intent

**Acceptance criterion**: Publishing a `payment.confirmed` event and crashing before dispatch causes the event to be retried and eventually delivered; no duplicate allocation is created on re-delivery.

**Steps**:

```bash
# 1. Seed: create a wallet, customer, and payment intent via API or seeder

# 2. Trigger a payment confirmation (sets status = 'confirmed' on payment attempt)
#    This calls OutboxEventPublisher::publish(new PaymentConfirmedEvent(...))
#    The row is committed to platform.outbox_events with status=pending

# 3. Kill the OutboxDispatcherJob worker before it dispatches
docker compose stop horizon

# 4. Verify event row exists in pending state
docker compose exec db psql -U app -d olecard \
  -c "SELECT id, event_name, status, attempts FROM platform.outbox_events WHERE event_name='payment.confirmed' ORDER BY created_at DESC LIMIT 1;"
# Expected: status=pending, attempts=0

# 5. Restart worker
docker compose start horizon

# 6. Wait ~5 seconds for dispatch, then verify
docker compose exec db psql -U app -d olecard \
  -c "SELECT status, attempts FROM platform.outbox_events WHERE event_name='payment.confirmed' ORDER BY created_at DESC LIMIT 1;"
# Expected: status=dispatched, attempts=1

# 7. Verify allocation was created exactly once
docker compose exec db psql -U app -d olecard \
  -c "SELECT count(*) FROM payment.payment_allocations WHERE idempotency_key='<allocationReference from event>';"
# Expected: count=1
```

**Pass criteria**: `status=dispatched`, allocation count = 1.

---

## SC-002 — Deduplication idempotency: repeated delivery causes no duplicate

**Acceptance criterion**: Delivering the same `payment.confirmed` event twice creates exactly one notification and one allocation.

```bash
# Unit test (no Docker required):
php artisan test --filter=PaymentConfirmedListenerTest::test_duplicate_delivery_creates_single_allocation
php artisan test --filter=PaymentConfirmedListenerTest::test_duplicate_delivery_creates_single_notification
# Both should PASS
```

**Docker verification**:

```bash
# Simulate duplicate by directly inserting the same payload twice and running the listener
docker compose exec api php artisan tinker --execute="
  \$payload = ['eventId' => '<uuid>', 'paymentIntentId' => '<uuid>', ...];
  (new \App\Modules\Payment\Application\EventHandlers\PaymentConfirmedListener())->handle(\$payload);
  (new \App\Modules\Payment\Application\EventHandlers\PaymentConfirmedListener())->handle(\$payload);
"
docker compose exec db psql -U app -d olecard \
  -c "SELECT count(*) FROM notification.notifications WHERE source_event_id='<uuid>' AND notification_type='payment_confirmed';"
# Expected: count=1
docker compose exec db psql -U app -d olecard \
  -c "SELECT count(*) FROM payment.payment_allocations WHERE idempotency_key='<allocationReference>';"
# Expected: count=1
```

**Pass criteria**: Counts are 1 regardless of delivery count.

---

## SC-003 — Causation chain: `causation_id` populated

**Acceptance criterion**: Events published by `OutboxEventPublisher` that implement `causationId()` have the field populated in `platform.outbox_events`.

```bash
# Unit test (no Docker required):
php artisan test --filter=OutboxEventPublisherTest::test_causation_id_populated_when_event_implements_method
php artisan test --filter=OutboxEventPublisherTest::test_causation_id_null_when_event_lacks_method
# Both should PASS
```

**Docker verification** (for a payment.confirmed event):

```bash
docker compose exec db psql -U app -d olecard \
  -c "SELECT causation_id FROM platform.outbox_events WHERE event_name='payment.confirmed' ORDER BY created_at DESC LIMIT 1;"
# Expected: causation_id is NULL for P11 (root event; listener→child threading deferred to P12)
```

---

## SC-004 — Dead-letter admin: list and replay

**Acceptance criterion**: An admin with `operations.dead_letter.manage` can list dead-letter outbox events and replay them; replay is audited.

```bash
# 1. Force an event to dead_letter state
docker compose exec db psql -U app -d olecard \
  -c "UPDATE platform.outbox_events SET status='dead_letter', attempts=5 WHERE event_name='payment.confirmed' ORDER BY created_at DESC LIMIT 1 RETURNING id;"
# Note the ID

# 2. Authenticate as admin
TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.token')

# 3. List dead-letter events
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/operations/outbox/dead-letters | jq .
# Expected: data array with the dead_letter event

# 4. Replay the event
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/operations/outbox/dead-letters/<id>/replay | jq .
# Expected: status=pending, attempts=0

# 5. Verify audit record
docker compose exec db psql -U app -d olecard \
  -c "SELECT action, metadata FROM platform.audit_logs WHERE action='outbox.dead_letter.replay' ORDER BY created_at DESC LIMIT 1;"
# Expected: action=outbox.dead_letter.replay, metadata includes event_name, aggregate_id
```

**Pass criteria**: List returns the dead-letter row; replay returns status=pending; audit record exists.

---

## SC-005 — Queue health endpoint

**Acceptance criterion**: The queue health endpoint returns lag metrics per event type within 200 ms.

```bash
# Authenticate
TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.token')

# Query health
time curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/operations/outbox/health | jq .
# Expected: data array with event_name, pending_count, dead_letter_count, oldest_pending_age_seconds
# Expected: response time < 200ms
```

**Pass criteria**: HTTP 200 with correct data shape; response within 200 ms.

---

## SC-006 — Notification delivery (all 4 event types)

**Acceptance criterion**: Each of the 4 payment event types triggers a notification to the payer with no PII in the outbox payload beyond actor ID.

```bash
# Unit tests (no Docker required):
php artisan test --filter=PaymentEventListenerTest
# Expected: 4 passing tests (one per event type)
```

**Docker verification**:

```bash
# After triggering each payment flow, verify notifications exist
docker compose exec db psql -U app -d olecard \
  -c "SELECT notification_type, count(*) FROM notification.notifications WHERE notification_type IN ('payment_confirmed','payment_failed','payment_reversed','refund_confirmed') GROUP BY notification_type;"
# Expected: all 4 types present with count >= 1
```

---

## Unit test quick-run (no Docker required)

```bash
cd wallet-ecosystem/services/api/

# All P11 unit tests
php artisan test tests/Unit/Modules/Payment/EventHandlers/ \
               tests/Unit/Modules/Platform/Services/OutboxQueueHealthServiceTest.php \
               tests/Unit/Modules/Platform/Services/OutboxEventPublisherTest.php

# Expected: ~20 tests, all pass
```

---

## PHPStan (Docker required for Horizon dependencies)

```bash
cd wallet-ecosystem/services/api/
./vendor/bin/phpstan analyse \
  app/Modules/Payment/Application/EventHandlers/ \
  app/Modules/Platform/Application/Services/OutboxQueueHealthService.php \
  app/Modules/Platform/Infrastructure/Http/Controllers/OutboxAdminController.php \
  --level=8
# Expected: 0 errors
```

---

## Full feature test (Docker required)

```bash
cd wallet-ecosystem/services/api/
php artisan test tests/Feature/Modules/Payment/OutboxDeliveryTest.php
# Expected: all scenarios pass
```
