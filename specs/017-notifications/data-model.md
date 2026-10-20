# Data Model — E17 Notifications

**Schema**: `notification` (PostgreSQL schema-per-module)
**Migration range**: 017000–017500

---

## Entities

### 1. Notification

Represents a single notification destined for one recipient, triggered by one upstream event. Created once per `(source_event_id, notification_type)` combination; idempotent on replay.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID v7 | PK | |
| `source_event_id` | UUID | NOT NULL (UNIQUE with `notification_type`) | Event ID from the upstream integration event; forms idempotency key |
| `source_event_type` | varchar(100) | NOT NULL | PHP class name of the upstream event (e.g., `QrPaymentCompletedEvent`) |
| `notification_type` | varchar(100) | NOT NULL | Template key used to render this notification (e.g., `payment.qr.received`) |
| `category` | enum | NOT NULL | `financial`, `security`, `operational`, `marketing` |
| `mandatory` | boolean | NOT NULL, DEFAULT false | When true, bypasses user preference opt-outs |
| `recipient_id` | UUID | NOT NULL | User ID of the notification recipient |
| `subject` | text | NOT NULL | Rendered subject string |
| `body` | text | NOT NULL | Rendered body string |
| `status` | enum | NOT NULL, DEFAULT `queued` | `queued`, `dispatching`, `partially_delivered`, `delivered`, `failed` |
| `read_at` | timestamp | NULLABLE | Set when user marks in-app notification as read; NULL = unread |
| `correlation_id` | UUID | NOT NULL | Propagated from the upstream event; used for tracing |
| `created_at` | timestamp | NOT NULL | |
| `updated_at` | timestamp | NOT NULL | |

**Indexes**:
- UNIQUE `(source_event_id, notification_type)` — idempotency gate
- INDEX `(recipient_id, created_at DESC)` — inbox query
- INDEX `(recipient_id, read_at)` — unread count query
- INDEX `(status, category)` — ops monitoring / retry queries

**Constraints**:
- `category = 'marketing'` rows require `mandatory = false`

---

### 2. NotificationDelivery

Per-channel delivery attempt record for a `Notification`. Multiple rows per notification (one per channel). Tracks the full delivery lifecycle including retry state.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID v7 | PK | |
| `notification_id` | UUID | FK → `notification.notifications(id)`, NOT NULL | |
| `channel` | enum | NOT NULL | `push`, `sms`, `email`, `in_app` |
| `status` | enum | NOT NULL, DEFAULT `queued` | `queued`, `sent`, `delivered`, `failed`, `permanently_failed` |
| `attempt_count` | smallint | NOT NULL, DEFAULT 0 | Incremented on each attempt |
| `last_attempt_at` | timestamp | NULLABLE | |
| `next_retry_at` | timestamp | NULLABLE | Set by retry scheduler after failure |
| `provider_ref` | varchar(255) | NULLABLE | Provider-assigned message ID (for push/SMS delivery confirmation) |
| `error_code` | varchar(50) | NULLABLE | Provider or internal error code on failure |
| `error_message` | varchar(500) | NULLABLE | Human-readable failure description (not exposed to users) |
| `created_at` | timestamp | NOT NULL | |
| `updated_at` | timestamp | NOT NULL | |

**Indexes**:
- INDEX `(notification_id)` — join from notification
- INDEX `(status, next_retry_at)` — retry scheduler lookup
- INDEX `(notification_id, channel)` UNIQUE — one delivery record per channel per notification

---

### 3. NotificationTemplate

Defines the content structure and allowed channels for each notification type. Seeded by migration; updated via E19 Admin Portal.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID v7 | PK | |
| `key` | varchar(100) | NOT NULL, UNIQUE | Template key (e.g., `payment.qr.received`) |
| `category` | enum | NOT NULL | Same enum as `notifications.category` |
| `subject_template` | text | NOT NULL | e.g., `Payment received: {{amount}} {{currency}}` |
| `body_template` | text | NOT NULL | Full body with `{{variable}}` placeholders |
| `supported_channels` | JSON | NOT NULL | Array of channel names supported by this template |
| `variables` | JSON | NOT NULL | Object mapping variable name → description |
| `active` | boolean | NOT NULL, DEFAULT true | Inactive templates are skipped at render time |
| `created_at` | timestamp | NOT NULL | |
| `updated_at` | timestamp | NOT NULL | |

**Seeded templates**:

| Key | Category | Variables |
|-----|----------|-----------|
| `payment.qr.received` | financial | `amount`, `currency`, `customer_ref`, `payment_ref` |
| `payment.qr.sent` | financial | `amount`, `currency`, `merchant_name`, `payment_ref` |
| `payment.funding.received` | financial | `amount`, `currency`, `source`, `reference` |
| `payment.transfer.received` | financial | `amount`, `currency`, `sender_ref`, `reference` |
| `payment.transfer.sent` | financial | `amount`, `currency`, `recipient_ref`, `reference` |
| `payment.withdrawal.processed` | financial | `amount`, `currency`, `reference` |
| `payment.commission.credited` | financial | `amount`, `currency`, `period`, `reference` |
| `payment.bill.paid` | financial | `amount`, `currency`, `biller`, `reference` |
| `security.new_device` | security | `device_name`, `platform`, `occurred_at` |
| `security.password_changed` | security | `occurred_at`, `support_ref` |
| `security.suspicious_activity` | security | `detail`, `occurred_at`, `support_ref` |

---

### 4. NotificationPreference

One row per user. Controls which channels they receive per notification category. Mandatory categories (financial, security) are always delivered regardless of stored preference — enforcement is at `NotificationPreferenceService` layer, not at DB level.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID v7 | PK | |
| `user_id` | UUID | NOT NULL, UNIQUE | User whose preferences these are |
| `push_enabled_categories` | JSON | NOT NULL | Array of category values enabled for push; default all non-mandatory |
| `sms_enabled_categories` | JSON | NOT NULL | Array of category values enabled for SMS |
| `email_enabled_categories` | JSON | NOT NULL | Array of category values enabled for email |
| `marketing_opted_in` | boolean | NOT NULL, DEFAULT false | |
| `created_at` | timestamp | NOT NULL | |
| `updated_at` | timestamp | NOT NULL | |

**Default values on user registration**:
- `push_enabled_categories`: `["financial", "security", "operational"]`
- `sms_enabled_categories`: `["financial", "security"]`
- `email_enabled_categories`: `["security"]`
- `marketing_opted_in`: `false`

**Note**: The `financial` and `security` category entries in the JSON arrays are advisory — `NotificationPreferenceService::isChannelEnabledForCategory()` always returns `true` for these categories regardless of the stored JSON.

---

## Entity Relationships

```
notifications (1) ─────────── (many) notification_deliveries
                                        channel, status, attempt_count

notifications (many) ──── (reference) notification_templates
                           via notification_type = template.key

notifications (many) ──── (reference) notification_preferences
                           via recipient_id = preference.user_id
```

---

## State Machines

### Notification.status

```
queued
  → dispatching   (DispatchNotificationJob starts)
  → delivered     (all channel deliveries succeed)
  → partially_delivered (≥1 channel delivered, ≥1 permanently_failed)
  → failed        (all channels permanently_failed)
```

### NotificationDelivery.status

```
queued
  → sent               (channel adapter accepted the message)
  → delivered          (provider confirmation or in_app completion)
  → failed             (attempt failed; retry budget remaining)
  → permanently_failed (max retries exhausted)
```

---

## Not Owned by E17

- Device push tokens — owned by E03 (`devices.device_tokens` or equivalent); accessed via `DeviceRegistryInterface`
- User account / phone / email — owned by E01/E03; accessed via `UserProfileServiceInterface` or event payload
