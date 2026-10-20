# Research — E17 Notifications

## 1. Push Channel Adapter Strategy

**Decision**: Define `PushChannelInterface` in E17's domain; ship a `LogPushChannel` (logs to structured log) as the default implementation. Real FCM/APNS adapter lives in E1000 (Provider-Adapter-Foundation) and is bound via the service provider at deploy time.

**Rationale**: The spec's Assumption 1 explicitly defers provider SDK binding to E1000. Shipping a log adapter now keeps E17 self-contained, testable, and free of provider credentials. The interface contract is stable — E1000 binds to it without touching E17's domain.

**Alternatives considered**: (a) Inline FCM SDK — rejected, couples E17 to a specific vendor and violates the provider-adapter-foundation pattern. (b) Laravel Notifications — rejected, couples delivery logic to Laravel's built-in Notifiable trait and obscures per-channel status tracking needed for compliance audit.

---

## 2. Retry Strategy

**Decision**: Use Laravel job `$backoff` property returning an exponential array. Configuration keys: `notifications.retry.max_attempts` (default: 5) and `notifications.retry.initial_delay_seconds` (default: 30). Backoff sequence (seconds): `[30, 60, 120, 240, 480]`. After all attempts exhausted, fire `NotificationPermanentlyFailedEvent` → audit record + ops alert.

**Rationale**: Laravel Horizon manages retry scheduling natively. Using config keys keeps the retry policy runtime-adjustable from Platform Configuration (E28), satisfying P3. The 5-attempt / 30s-initial schedule covers transient provider outages (typical FCM outage recovery < 15 minutes) without indefinitely queuing failed jobs.

**Alternatives considered**: (a) Fixed delay — rejected, not adaptive to burst outages. (b) Database-scheduled retry poller — rejected, adds complexity when Horizon already handles this.

---

## 3. Idempotency Key Structure for Multi-Recipient Events

**Decision**: UNIQUE constraint on `(source_event_id, notification_type)`. A single `QrPaymentCompletedEvent` produces two notifications: one for the merchant (`payment.qr.received`) and one for the customer (`payment.qr.sent`). The composite key `(event_id, 'payment.qr.received')` and `(event_id, 'payment.qr.sent')` are distinct rows — both idempotent independently.

**Rationale**: Allows one event to fan out to multiple recipients without collision, while still preventing duplicate delivery on outbox re-play.

**Alternatives considered**: (a) Single `source_event_id` UNIQUE — rejected, prevents the required fan-out for merchant + customer. (b) Per-delivery idempotency only — rejected, a duplicate event would still create a second `Notification` record, which would create a second in-app inbox entry.

---

## 4. Device Token Access Pattern

**Decision**: E17 defines `DeviceRegistryInterface` in its own `Domain/Contracts/`. The binding in `NotificationServiceProvider` points to `E03DeviceRegistryAdapter`, which delegates to E03's `DeviceManagementService`. E17 calls `getActiveTokensForUser(string $userId): DeviceToken[]`. When a push provider reports a token invalid, E17 calls `invalidateToken(string $tokenId)` which delegates to E03 to persist the invalidation.

**Rationale**: This keeps E17 from owning device state while maintaining a clean, test-mockable boundary. The adapter pattern is consistent with how E16 accesses E12 via `MerchantReadServiceInterface`.

**Alternatives considered**: (a) E17 maintains its own copy of device tokens synced via events — rejected, creates data ownership ambiguity and sync lag. (b) Direct model query from E03's table — rejected, violates module boundary (P7).

---

## 5. Template Engine Approach

**Decision**: `NotificationTemplateService::render(string $templateKey, array $variables): RenderedNotification` uses simple `str_replace` with `{{variable_name}}` placeholders. Templates are stored in `notification.notification_templates` seeded by migration. Missing variables fall back to `[Unknown]` with a structured log warning.

**Rationale**: Notification templates are short, predictable strings. Blade or Twig adds compilation overhead and security surface for user-supplied template injection. The `{{variable_name}}` pattern is self-documenting and trivially testable.

**Alternatives considered**: (a) Laravel Blade — rejected, overkill for single-line subject/body; requires view files or eval-string rendering. (b) Mustache/Twig — rejected, third-party dependency for a solved problem.

---

## 6. Queue Routing for Notification Priority

**Decision**: Two Horizon queues:
- `notifications-critical` — financial and security category notifications (mandatory); processed first
- `notifications-bulk` — operational and marketing category notifications

Horizon supervisor config: `notifications-critical` queue gets higher `balance_max_shift` and dedicated processes. Laravel queue dispatch uses `->onQueue('notifications-critical')` for mandatory notifications in `DispatchNotificationJob`.

**Rationale**: Ensures mandatory financial/security notifications are not delayed by a bulk marketing burst. Horizon already supports multi-queue processing; no additional infrastructure required.

**Alternatives considered**: (a) Single queue — rejected, marketing burst could delay financial notification delivery past the 5-second SC-E17-001 target. (b) Redis priority queue — rejected, Horizon's queue-per-priority is simpler to operate and configure.

---

## 7. Event Listener Registration

**Decision**: E17 listens to integration events from upstream modules using Laravel's `EventServiceProvider` pattern (registered in `NotificationServiceProvider`). All listeners implement `ShouldQueue` and are dispatched on the `notifications-critical` or `notifications-bulk` queue based on the event category. Event-to-listener mapping:

| Event | Listener | Category |
|-------|----------|----------|
| `QrPaymentCompletedEvent` (E16) | `QrPaymentCompletedListener` | financial (mandatory) |
| `FundingCompletedEvent` (E08) | `FundingCompletedListener` | financial (mandatory) |
| `TransferCompletedEvent` (E09) | `TransferCompletedListener` | financial (mandatory) |
| `WithdrawalProcessedEvent` (E10) | `WithdrawalProcessedListener` | financial (mandatory) |
| `CommissionCreditedEvent` (E14) | `CommissionCreditedListener` | financial (mandatory) |
| `BillPaymentCompletedEvent` (E15) | `BillPaymentCompletedListener` | financial (mandatory) |
| `SecurityAlertEvent` (E03) | `SecurityAlertListener` | security (mandatory) |

Each listener: maps event fields → `CreateNotificationCommand`; calls `NotificationService::createFromEvent()`.

**Rationale**: Queue-backed listeners decouple E17 from upstream module processing latency. Queued listeners also give Horizon retry semantics if the listener itself fails transiently.

---

## 8. In-App Channel Delivery

**Decision**: The `in_app` channel does not go through an external adapter. When `NotificationDeliveryService` dispatches to `in_app`, it directly marks the `NotificationDeliveryModel` status as `delivered` and sets the `Notification.read_at = null` (unread). No external call is made — in-app delivery is always synchronous and always succeeds. The user reads notifications at their next app open.

**Rationale**: In-app notifications are stored in the database — delivery is creating the row. There is no external provider to fail. This makes in-app the unconditional fallback channel (spec Assumption 5).

---

## All NEEDS CLARIFICATION items resolved

No outstanding unknowns. All decisions above are aligned with existing project patterns (E08–E16) and the approved technology stack.
