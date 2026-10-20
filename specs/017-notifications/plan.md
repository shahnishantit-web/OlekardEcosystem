# Implementation Plan — E17 Notifications

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — new `App\Modules\Notification` module; no stack deviations; channel adapters are plug-in via interfaces
- [x] No TBD value invented — retry policy, queue names, template variables, and channel drivers are all configurable from `ConfigurationServiceInterface`; no business values hard-coded
- [x] Authorization remains server-side — `notifications.preferences.manage` and `notifications.inbox.read` permissions enforced in controllers; mandatory override enforced in `NotificationPreferenceService`, not at client request
- [x] Financial integrity preserved — E17 does not post ledger entries; idempotency enforced via UNIQUE `(source_event_id, notification_type)`; concurrency tested (duplicate event dispatch)
- [x] Security/audit requirements incorporated — all notification lifecycle events audited; `notification.mandatory_override_applied` audit record for compliance; ops alert on permanent failure
- [x] QA strategy incorporated — unit tests for services and template rendering; integration tests for listener → delivery path; concurrency tests for duplicate event; SC-1–SC-10 quickstart scenarios
- [x] Event reliability — E17 consumes events via queued listeners; notification creation is idempotent; delivery jobs are queued via Horizon

---

## Technical Context

| Concern | Decision |
|---------|----------|
| Backend | Laravel / PHP 8.4+, `App\Modules\Notification` |
| Database | PostgreSQL, schema `notification` |
| Migration range | 017000–017500 |
| Queue | Redis + Laravel Horizon; `notifications-critical` (financial/security) and `notifications-bulk` (operational/marketing) |
| API | REST/OpenAPI, `/v1/notifications/preferences` and `/v1/notifications/inbox` |
| Push channel | `PushChannelInterface`; `LogPushChannel` as default (real FCM/APNS adapter in E1000) |
| SMS channel | `SmsChannelInterface`; `LogSmsChannel` as default |
| Email channel | `EmailChannelInterface`; `LogEmailChannel` as default |
| Template engine | `str_replace` with `{{variable_name}}` placeholders; missing vars fall back to `[Unknown]` |
| Idempotency | UNIQUE `(source_event_id, notification_type)` on `notification.notifications` |
| Retry backoff | Configurable: `notifications.retry.max_attempts` (default 5), `notifications.retry.initial_delay_seconds` (default 30); exponential doubling |
| Device tokens | Read from E03 via `DeviceRegistryInterface`; E17 calls back to invalidate expired tokens |
| Mandatory override | Enforced at `NotificationPreferenceService` layer; preference check bypassed for `mandatory = true` notifications |
| Cross-module deps | `AuditServiceInterface` (E03), `DeviceRegistryInterface` (E03 adapter), `ConfigurationServiceInterface` (E28) |

---

## Module Tree

```
wallet-ecosystem/services/api/app/Modules/Notification/
├── Application/
│   ├── Jobs/
│   │   ├── DispatchNotificationJob.php          # dispatches per-channel delivery jobs
│   │   ├── DeliverPushNotificationJob.php       # single push delivery + retry
│   │   ├── DeliverSmsNotificationJob.php        # single SMS delivery + retry
│   │   ├── DeliverEmailNotificationJob.php      # single email delivery + retry
│   │   └── DeliverInAppNotificationJob.php      # in-app delivery (always succeeds)
│   ├── Listeners/
│   │   ├── QrPaymentCompletedListener.php       # E16 event → 2 notifications (merchant + customer)
│   │   ├── FundingCompletedListener.php         # E08 event
│   │   ├── TransferCompletedListener.php        # E09 event
│   │   ├── WithdrawalProcessedListener.php      # E10 event
│   │   ├── CommissionCreditedListener.php       # E14 event
│   │   ├── BillPaymentCompletedListener.php     # E15 event
│   │   └── SecurityAlertListener.php            # E03 security events
│   └── Services/
│       ├── NotificationService.php              # createFromEvent(); idempotency gate
│       ├── NotificationPreferenceService.php    # preference CRUD; mandatory override
│       ├── NotificationDeliveryService.php      # channel routing; adapter dispatch
│       ├── NotificationTemplateService.php      # lookup + render with variable substitution
│       └── InAppNotificationService.php         # inbox query; mark-read; unread-count
├── Domain/
│   ├── Contracts/
│   │   ├── PushChannelInterface.php
│   │   ├── SmsChannelInterface.php
│   │   ├── EmailChannelInterface.php
│   │   ├── DeviceRegistryInterface.php          # getActiveTokensForUser(); invalidateToken()
│   │   └── UserContactServiceInterface.php      # userExists(); getPhoneForUser(); getEmailForUser()
│   ├── DTO/
│   │   ├── CreateNotificationCommand.php        # sourceEventId, sourceEventType, notificationType, category, mandatory, recipientId, variables, correlationId
│   │   ├── NotificationContent.php              # renderedSubject, renderedBody, supportedChannels: array
│   │   ├── PushDeliveryResult.php               # success, providerRef, errorCode, tokenInvalid
│   │   ├── SmsDeliveryResult.php                # success, providerRef, errorCode
│   │   └── EmailDeliveryResult.php              # success, providerRef, errorCode
│   ├── Enums/
│   │   ├── NotificationChannel.php              # push, sms, email, in_app
│   │   ├── NotificationCategory.php             # financial, security, operational, marketing
│   │   ├── NotificationStatus.php               # queued, dispatching, partially_delivered, delivered, failed
│   │   └── DeliveryStatus.php                   # queued, sent, delivered, failed, permanently_failed
│   └── Exceptions/
│       ├── DuplicateNotificationException.php   # source_event_id already processed (idempotency)
│       ├── TemplateNotFoundException.php        # template key not found or inactive
│       ├── MandatoryNotificationCannotBeDisabledException.php
│       └── RecipientNotFoundException.php       # user ID not resolvable
├── Infrastructure/
│   ├── Adapters/
│   │   ├── E03DeviceRegistryAdapter.php         # implements DeviceRegistryInterface; delegates to E03
│   │   └── E03UserContactAdapter.php            # implements UserContactServiceInterface; delegates to E03
│   ├── Channels/
│   │   ├── LogPushChannel.php                   # dev/test; logs to structured log; returns success
│   │   ├── LogSmsChannel.php
│   │   └── LogEmailChannel.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── NotificationPreferenceController.php   # GET/PUT /v1/notifications/preferences
│   │   │   └── InAppNotificationController.php        # GET/PATCH /v1/notifications/inbox
│   │   ├── Requests/
│   │   │   ├── UpdateNotificationPreferenceRequest.php
│   │   │   └── MarkNotificationsReadRequest.php
│   │   └── Resources/
│   │       ├── NotificationPreferenceResource.php
│   │       ├── NotificationSummaryResource.php
│   │       └── NotificationDetailResource.php
│   ├── Migrations/
│   │   ├── 2026_10_01_017000_create_notification_schema.php
│   │   ├── 2026_10_01_017100_create_notifications_table.php
│   │   ├── 2026_10_01_017200_create_notification_deliveries_table.php
│   │   ├── 2026_10_01_017300_create_notification_templates_table.php
│   │   ├── 2026_10_01_017400_create_notification_preferences_table.php
│   │   └── 2026_10_01_017500_seed_notification_templates.php
│   └── Models/
│       ├── NotificationModel.php
│       ├── NotificationDeliveryModel.php
│       ├── NotificationTemplateModel.php
│       └── NotificationPreferenceModel.php
└── Providers/
    └── NotificationServiceProvider.php
```

---

## Dependencies on Prior Epics

| Epic | Interface Used | Purpose |
|------|---------------|---------|
| E03 | `AuditServiceInterface` | Audit notification lifecycle events (created, delivered, failed, mandatory override) |
| E03 | `DeviceRegistryInterface` (via E03DeviceRegistryAdapter) | Read active push device tokens for recipient; invalidate expired tokens |
| E03 | `UserContactServiceInterface` (via E03UserContactAdapter) | Verify recipient exists; retrieve phone and email for SMS/email delivery |
| E04 | Permissions seeder | Seed `notifications.preferences.manage`, `notifications.inbox.read`, `notifications.admin.send` |
| E08 | `FundingTransactionCompleted` | Consumed by `FundingCompletedListener` (class name verified 2026-09-25) |
| E09 | `TransferCompleted` | Consumed by `TransferCompletedListener`; outbox event pattern established (class name verified 2026-09-25) |
| E10 | `WithdrawalCompleted` | Consumed by `WithdrawalProcessedListener` (class name verified 2026-09-25) |
| E14 | `CommissionPaidEvent` | Consumed by `CommissionCreditedListener` (class name verified 2026-09-25) |
| E15 | `ServicePaymentCompletedEvent` | Consumed by `BillPaymentCompletedListener` (class name verified 2026-09-25) |
| E16 | `QrPaymentCompletedEvent` | Consumed by `QrPaymentCompletedListener`; first live integration |
| E28 | `ConfigurationServiceInterface` | Read retry config, queue names, channel drivers at runtime |

---

## Database Migrations

| File | Change |
|------|--------|
| `017000_create_notification_schema.php` | `CREATE SCHEMA IF NOT EXISTS notification` |
| `017100_create_notifications_table.php` | `notification.notifications` with UNIQUE `(source_event_id, notification_type)` |
| `017200_create_notification_deliveries_table.php` | `notification.notification_deliveries` with UNIQUE `(notification_id, channel)` |
| `017300_create_notification_templates_table.php` | `notification.notification_templates` with UNIQUE `key` |
| `017400_create_notification_preferences_table.php` | `notification.notification_preferences` with UNIQUE `user_id` |
| `017500_seed_notification_templates.php` | Seed 11 templates for financial + security categories (see data-model.md) |

---

## Routes

### Customer/Merchant/Agent-Facing (auth:sanctum)

```
GET    /v1/notifications/preferences           → NotificationPreferenceController@show
PUT    /v1/notifications/preferences           → NotificationPreferenceController@update
GET    /v1/notifications/inbox                 → InAppNotificationController@index
GET    /v1/notifications/inbox/unread-count    → InAppNotificationController@unreadCount
GET    /v1/notifications/inbox/{id}            → InAppNotificationController@show
PATCH  /v1/notifications/inbox/read           → InAppNotificationController@markRead
```

All routes require `notifications.preferences.manage` or `notifications.inbox.read` permission. Routes are scoped to the authenticated user — no cross-user access.

---

## Service Responsibilities

### NotificationService (Application Service)

- `createFromEvent(CreateNotificationCommand $cmd): NotificationModel`
  1. Idempotency check: query `notifications` for `(source_event_id, notification_type)` — if exists, return existing record
  2. Resolve recipient: confirm user exists; throw `RecipientNotFoundException` if not
  3. Render template: `NotificationTemplateService::render($cmd->notificationType, $cmd->variables)`; throw `TemplateNotFoundException` if template inactive or missing
  4. Persist `NotificationModel` with status `queued`
  5. Determine queue: `mandatory` → `notifications-critical`; else → `notifications-bulk`
  6. Dispatch `DispatchNotificationJob::dispatch($notification->id)->onQueue($queue)`
  7. Audit: `AuditServiceInterface::record(action: 'notification.created', ...)`
  8. Return `NotificationModel`

### NotificationPreferenceService (Application Service)

- `getOrCreateForUser(string $userId): NotificationPreferenceModel` — upsert default preferences on first call
- `updateForUser(string $userId, array $payload): NotificationPreferenceModel`
  - Validates that `financial` and `security` are present in any submitted non-empty channel array
  - Throws `MandatoryNotificationCannotBeDisabledException` on violation
  - Audits: `notification.preference_updated`; `notification.marketing_opted_in` / `notification.marketing_opted_out` when `marketing_opted_in` changes
- `getEnabledChannels(string $userId, NotificationCategory $category, bool $mandatory, ?string $notificationId = null): array<NotificationChannel>`
  - If `$mandatory === true`, returns all physically available channels (push if device token exists via `DeviceRegistryInterface`, SMS if phone exists via `UserContactServiceInterface`, email if email exists via `UserContactServiceInterface`, in_app always); emits `notification.mandatory_override_applied` audit when stored preference would have excluded a channel
  - Else returns channels from stored preference arrays that include `$category`
  - Marketing category: returns empty array if `marketing_opted_in === false`

### NotificationDeliveryService (called from DispatchNotificationJob)

- `dispatchToChannels(NotificationModel $notification): void`
  1. Set `$notification->status` → `dispatching`; save
  2. Load recipient preferences via `NotificationPreferenceService::getEnabledChannels($notification->recipient_id, $notification->category, $notification->mandatory, $notification->id)`
  3. For each enabled channel, create a `NotificationDeliveryModel` (status `queued`)
  4. Dispatch per-channel job: `DeliverPushNotificationJob` / `DeliverSmsNotificationJob` / `DeliverEmailNotificationJob` / `DeliverInAppNotificationJob`
  5. All channel jobs run in parallel (different jobs, no ordering dependency)

### Per-Channel Delivery Jobs (DeliverPushNotificationJob etc.)

- Each job implements `ShouldQueue` with:
  - `$tries` = `config('notifications.retry.max_attempts', 5)`
  - `$backoff` = exponential array starting at `config('notifications.retry.initial_delay_seconds', 30)`
- `handle()` flow:
  1. Load `NotificationDeliveryModel`; increment `attempt_count`; set `last_attempt_at`
  2. Call channel adapter (`PushChannelInterface::send()` etc.)
  3. On success: status → `sent` (or `delivered` for in_app); update `provider_ref`; update parent `NotificationModel.status`
  4. On `tokenInvalid` from push: call `DeviceRegistryInterface::invalidateToken()`; status → `failed` (no retry — token is gone)
  5. On transient failure: status → `failed`; throw exception → Horizon reschedules with backoff
  6. On max retries exceeded (caught by `failed()` callback): status → `permanently_failed`; audit `notification.permanently_failed`; alert ops via structured log at ERROR level
- `DeliverInAppNotificationJob`: always marks as `delivered` synchronously; no retries needed

### NotificationTemplateService (Application Service)

- `render(string $key, array $variables): NotificationContent`
  1. Load template from `notification.notification_templates` where `key = $key AND active = true`; throw `TemplateNotFoundException` if absent
  2. Render subject and body using `str_replace` over `{{variable_name}}` placeholders
  3. For each placeholder not found in `$variables`: substitute `[Unknown]`; emit structured log warning with template key and missing variable name
  4. Return `NotificationContent(renderedSubject, renderedBody, supportedChannels)`

### InAppNotificationService (Application Service)

- `listForUser(string $userId, array $filters): LengthAwarePaginator`
- `getUnreadCount(string $userId): int`
- `markRead(string $userId, array $ids): int` — marks given IDs (or all) as read; returns count
- `findForUser(string $userId, string $notificationId): NotificationModel` — throws `NotFound` if ID belongs to another user

---

## Event Listeners

Each listener is thin: map event fields → `CreateNotificationCommand`; call `NotificationService::createFromEvent()`.

### QrPaymentCompletedListener (E16)

Creates **two** notifications for one event:
1. `CreateNotificationCommand` for merchant: type `payment.qr.received`, mandatory `true`, variables `{amount, currency, customer_ref, payment_ref}`
2. `CreateNotificationCommand` for customer: type `payment.qr.sent`, mandatory `true`, variables `{amount, currency, merchant_name, payment_ref}`

Both commands carry the same `source_event_id` but different `notification_type` values — idempotency UNIQUE key handles re-dispatch correctly.

---

## Permissions

Seeded in the permissions table (new entries):

| Permission | Description |
|------------|-------------|
| `notifications.preferences.manage` | User manages their own notification preferences |
| `notifications.inbox.read` | User reads their own in-app notification inbox |
| `notifications.admin.send` | Admin sends broadcast or compliance notifications |

---

## Audit Events

| Action | Triggered by |
|--------|-------------|
| `notification.created` | `NotificationService::createFromEvent()` |
| `notification.delivered` | Per-channel job on successful delivery |
| `notification.permanently_failed` | Per-channel job `failed()` callback |
| `notification.mandatory_override_applied` | `NotificationPreferenceService::getEnabledChannels()` when mandatory = true bypasses opt-out |
| `notification.preference_updated` | `NotificationPreferenceService::updateForUser()` |
| `notification.marketing_opted_in` | `NotificationPreferenceService::updateForUser()` when `marketing_opted_in` flips to true |
| `notification.marketing_opted_out` | `NotificationPreferenceService::updateForUser()` when `marketing_opted_in` flips to false |

---

## Financial / Idempotency Controls

| Control | Mechanism |
|---------|-----------|
| Duplicate event prevention | UNIQUE `(source_event_id, notification_type)` on `notification.notifications` |
| Duplicate delivery prevention | UNIQUE `(notification_id, channel)` on `notification.notification_deliveries` |
| Concurrency | `firstOrCreate` with unique constraint catch on `NotificationService::createFromEvent()` |
| In-app delivery atomicity | `DeliverInAppNotificationJob` marks delivery as `delivered` in same transaction that creates the delivery row |

---

## Observability

- Correlation ID: propagated from upstream event (`$cmd->correlationId`) through `NotificationModel` → all delivery jobs → audit records
- Structured logs: all service method entry/exit; delivery attempt with channel, status, `provider_ref`; `marketing_discarded` flag when event is dropped; missing template variable warning
- Metrics:
  - `notification.created` (labels: `category`)
  - `notification.delivered` (labels: `channel`, `category`)
  - `notification.failed` (labels: `channel`, `error_code`)
  - `notification.retry` (labels: `channel`)
  - `notification.permanently_failed` (labels: `channel`)
- All financial and security events are auditable (P13)

---

## OpenAPI Documentation

`openapi/v1/notifications.yaml` — covers all 6 endpoints:
- `GET/PUT /v1/notifications/preferences`
- `GET /v1/notifications/inbox`, `GET /v1/notifications/inbox/unread-count`, `GET /v1/notifications/inbox/{id}`, `PATCH /v1/notifications/inbox/read`

Error envelope follows the platform standard (consistent with E09–E16).

---

## Constitution Check (Post-Design)

All 7 checks pass. No deviations or ADRs required.

- All configurable values (retry counts, delays, queue names, channel drivers) read from `ConfigurationServiceInterface` — P3 ✅
- UNIQUE `(source_event_id, notification_type)` prevents duplicate notifications — P4/P9 ✅
- `DeviceRegistryInterface` and channel interfaces are clean cross-module contracts — P7 ✅
- Queued listeners consume upstream events reliably; delivery jobs re-queue on transient failure — P9 ✅
- All notification lifecycle events are audited; mandatory override audit record for compliance — P13 ✅
- No new stack choices; provider adapters are plug-in via interfaces; real adapters deferred to E1000 — P14 ✅
- Concurrency tests: duplicate event dispatch, parallel delivery jobs — P4/P12 ✅
