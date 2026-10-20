# Quickstart Validation Guide — E17 Notifications

## Prerequisites

```bash
docker compose exec api bash
php artisan migrate --path=database/migrations/017*
php artisan db:seed --class=NotificationTemplateSeeder
php artisan horizon:start &
```

Seed data needed:
- A registered `customer` user with phone number and device token
- A registered `merchant` user with a device token
- An `agent` user with device token

---

## SC-1: QR Payment → Merchant receives in-app notification

**Trigger**: Dispatch a `QrPaymentCompletedEvent` with `merchant_id`, `customer_id`, `amount = 250_00` (minor units), `currency = 'BWP'`, `payment_reference = 'PAY-0001'`.

```php
// In Tinker
$event = new \App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent(
    merchantId: 'MERCHANT-UUID',
    customerId: 'CUSTOMER-UUID',
    amount: 250_00,
    fee: 5_00,
    currency: 'BWP',
    paymentReference: 'PAY-0001',
    receiptRef: 'REC-0001',
    occurredAt: now(),
);
event($event);
```

**Expected** (within 5 seconds):
1. `notification.notifications` has a row: `notification_type = 'payment.qr.received'`, `recipient_id = MERCHANT-UUID`, `status = 'delivered'` (or `'dispatching'`)
2. `notification.notification_deliveries` has a row for `channel = 'in_app'` with `status = 'delivered'`
3. `GET /v1/notifications/inbox` for the merchant returns the notification with `subject` containing `250.00 BWP`

---

## SC-2: Same event published twice → no duplicate notification

**Trigger**: Publish the same `QrPaymentCompletedEvent` from SC-1 a second time (identical `event_id`).

**Expected**:
- No new row in `notification.notifications` for the same `(source_event_id, 'payment.qr.received')` pair
- Merchant inbox still shows exactly 1 notification from this event
- No second push/SMS dispatch logged

---

## SC-3: Customer also receives notification for same payment

**Expected** (from SC-1's event):
1. `notification.notifications` also has a row: `notification_type = 'payment.qr.sent'`, `recipient_id = CUSTOMER-UUID`
2. Customer's inbox contains notification with `subject` containing merchant name and `250.00 BWP`

---

## SC-4: User disables SMS for operational category → operational notification skips SMS

```bash
curl -X PUT /v1/notifications/preferences \
  -H "Authorization: Bearer <customer-token>" \
  -d '{"sms_enabled_categories": ["financial", "security"], "push_enabled_categories": ["financial", "security", "operational"], "email_enabled_categories": ["security"], "marketing_opted_in": false}'
```

Then dispatch an `operational` category notification.

**Expected**:
- `notification_deliveries` has a row for `push` (delivered) but NOT for `sms`

---

## SC-5: Attempt to disable financial category → rejected

```bash
curl -X PUT /v1/notifications/preferences \
  -H "Authorization: Bearer <customer-token>" \
  -d '{"sms_enabled_categories": ["security"], "push_enabled_categories": ["security"], "email_enabled_categories": [], "marketing_opted_in": false}'
```

**Expected**:
- `HTTP 422` with error code `MANDATORY_CATEGORY_REQUIRED`
- Preferences unchanged in database

---

## SC-6: Push delivery fails → retry → success

**Setup**: Bind `PushChannelInterface` to `FailOnceLogPushChannel` (fails first call, succeeds on second).

Dispatch any financial notification for a user with a registered device.

**Expected**:
1. First `notification_deliveries` row: `status = 'failed'`, `attempt_count = 1`, `next_retry_at` set
2. After retry executes: `status = 'delivered'`, `attempt_count = 2`
3. No duplicate notification record created

---

## SC-7: All retries exhausted → permanently_failed + audit

**Setup**: Bind `PushChannelInterface` to `AlwaysFailLogPushChannel`. Set `notifications.retry.max_attempts = 2` in config.

Dispatch a financial push notification.

**Expected**:
1. `notification_deliveries.status = 'permanently_failed'` after 2 attempts
2. Audit log contains `action = 'notification.permanently_failed'`
3. `notification.status = 'failed'` (if no other channels succeed)

---

## SC-8: Marketing notification to opted-out user → discarded

```bash
# Ensure user has marketing_opted_in = false (default)
```

Dispatch a `marketing` category event for the user.

**Expected**:
- No row created in `notification.notifications` for this user
- No `notification_deliveries` row
- Structured log entry contains `marketing_discarded = true`

---

## SC-9: Mandatory notification to user with all channels "disabled" → in-app always delivered

**Setup**: Update preferences to have empty arrays for push/sms/email categories.

Dispatch a `financial` category notification for the user.

**Expected**:
1. Despite preference arrays not listing `financial`, `mandatory = true` notification is dispatched to all channels
2. `in_app` delivery row exists with `status = 'delivered'`
3. Audit log contains `action = 'notification.mandatory_override_applied'`

---

## SC-10: Unread count updates correctly

```bash
# Get initial unread count
GET /v1/notifications/inbox/unread-count
# → {"data": {"unread_count": 3}}

# Mark one as read
PATCH /v1/notifications/inbox/read
Body: {"ids": ["<notification-id>"]}

# Get updated count
GET /v1/notifications/inbox/unread-count
# → {"data": {"unread_count": 2}}
```

---

## References

- Data model: [data-model.md](data-model.md)
- API contracts: [contracts/inbox-api.md](contracts/inbox-api.md), [contracts/preferences-api.md](contracts/preferences-api.md)
- Channel interfaces: [contracts/channel-interfaces.md](contracts/channel-interfaces.md)
