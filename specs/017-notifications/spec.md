# Feature Specification: E17 — Notifications

**Feature Branch**: `017-notifications`

**Created**: 2026-09-25

**Status**: Draft

## Overview

The Notifications platform is the centralized delivery service for all user-facing communications in the wallet ecosystem. When a payment completes, funds arrive, a security event occurs, or a transactional alert is triggered by any upstream module, E17 is responsible for routing that signal to the right recipient on their preferred channel — push notification, SMS, email, or in-app message — reliably and without duplicates.

E17 is a system-internal service: other modules emit integration events (e.g., `QrPaymentCompletedEvent` from E16, `FundingCompletedEvent` from E08) and E17 consumes those events, resolves the recipient, selects delivery channels based on the user's saved preferences, renders the appropriate template, and dispatches via provider adapters. Users can configure their preferences for non-mandatory notifications; mandatory financial and security notifications are always delivered regardless of preferences. Failed deliveries are automatically retried with configurable backoff.

## Actors

- **Customer** — Wallet holder who receives payment confirmations, funding alerts, debit/credit notifications, and security messages on their registered devices and channels
- **Merchant** — Business user who receives payment received alerts and account activity notifications at the point of sale and on their registered devices
- **Agent** — Field agent who receives float top-up alerts, commission credit notifications, and operational messages
- **Platform (System)** — Subscribes to integration events from all upstream modules; routes, renders, and dispatches notifications; retries failed deliveries; records all outcomes

## User Scenarios & Testing

### User Story 0 — Transactional Notification Delivery (Priority: P0)

When a financial event occurs — a QR payment is received by a merchant, a customer's wallet is debited or credited, or an agent receives a commission — the affected party receives a real-time notification on each of their enabled delivery channels. The system creates a notification record immediately on event receipt, renders the appropriate template with event-specific variables (amount, merchant name, reference), and dispatches to push, SMS, and in-app channels in parallel. Delivery is durable: if a channel fails, it is retried independently without blocking the others.

**Why this priority**: The payment notification use case (E16 → E17) is the first live integration. A merchant's ability to confirm a payment at the point of sale depends on receiving a real-time notification. All other stories build on this core delivery path.

**Independent Test**: Publish a `QrPaymentCompletedEvent` with a valid merchant ID and amount. Within 5 seconds, the merchant's in-app notification inbox contains a new unread notification with the correct amount and merchant reference. If the merchant has a registered device, a push notification is also dispatched. No duplicate notification is created if the same event ID is published a second time.

**Acceptance Scenarios**:

1. **Given** a `QrPaymentCompletedEvent` is published with merchant ID, amount, and payment reference, **When** the notification platform receives the event, **Then** a `Notification` record is created with status `queued`; the recipient is resolved as the merchant; the delivery channels are determined from the merchant's preferences; and a delivery attempt is dispatched to each enabled channel within 5 seconds.
2. **Given** a customer's wallet is debited for a QR payment, **When** the debit event is received, **Then** the customer receives a notification on their enabled channels containing the amount debited, the merchant name, and the transaction reference.
3. **Given** a funding event is published (e.g., bank transfer received), **When** the event is processed, **Then** the recipient wallet owner receives a credit notification showing the amount and funding source.
4. **Given** a notification delivery attempt is dispatched to a push channel, **When** the push provider accepts the message, **Then** the `NotificationDelivery` status transitions to `sent`; subsequent confirmation from the provider transitions it to `delivered`.
5. **Given** the same event ID is published twice (e.g., due to outbox retry), **When** the second event is processed, **Then** no duplicate `Notification` record is created and no second delivery is dispatched; idempotency is preserved by event ID.
6. **Given** a recipient has no registered push device, **When** push delivery is attempted, **Then** the push channel is skipped; SMS and in-app channels proceed normally; no error is raised.

---

### User Story 1 — Delivery Status Tracking & Retry (Priority: P1)

Every notification delivery attempt is tracked per channel. When a delivery fails — due to a network timeout, an expired device token, or a provider outage — the system automatically schedules a retry with exponential backoff up to a configurable maximum. After all retries are exhausted, the delivery is marked permanently failed and an ops alert is raised so the platform team can investigate provider issues. Users can view the status of their in-app notifications and mark them as read.

**Why this priority**: Without reliable retry and status tracking, transient provider failures would silently drop critical financial notifications. Retry ensures the merchant always learns of a completed payment, even if the initial push fails.

**Independent Test**: Simulate a push provider failure for one delivery. Confirm the delivery status transitions to `failed`; a retry is scheduled and dispatched within the configured retry window. After the provider recovers, the retry succeeds and the delivery status transitions to `delivered`. Confirm that at no point is a duplicate notification sent to the user.

**Acceptance Scenarios**:

1. **Given** a push delivery attempt fails with a transient provider error, **When** the failure is recorded, **Then** a retry is automatically scheduled using exponential backoff (configurable initial delay, configurable max attempts); the `NotificationDelivery` attempt count increments on each retry.
2. **Given** all retry attempts for a delivery are exhausted, **When** the final attempt fails, **Then** the `NotificationDelivery` status is set to `permanently_failed`; an audit event is emitted; the platform operations team is alerted for investigation.
3. **Given** a push device token is reported as invalid/expired by the provider on a delivery attempt, **When** the failure is received, **Then** the token is invalidated and removed from the user's device registry; no further push deliveries are attempted to the invalid token; SMS delivery (already dispatched in parallel) proceeds independently if the user has SMS enabled.
4. **Given** a recipient requests their in-app notification history, **When** the request is received, **Then** the response contains a paginated list of their notifications ordered by timestamp descending, with read/unread status per item.
5. **Given** a recipient marks an in-app notification as read, **When** the request is confirmed, **Then** the notification's status transitions to `read` and the recipient's unread count decrements accordingly.

---

### User Story 2 — Notification Preferences (Priority: P1)

Users can configure how they receive non-mandatory notifications: which channels to enable (push, SMS, email), and whether to receive marketing/promotional messages. These preferences are stored per user and consulted at dispatch time. Mandatory financial and security notifications are always delivered and cannot be disabled. Marketing notifications default to opt-out and require explicit opt-in.

**Why this priority**: Privacy regulation and user trust require that users control their communication preferences. This story also gates the marketing channel — no marketing notification can be sent without explicit user consent.

**Independent Test**: A user disables SMS for payment notifications. Publish a payment event for that user. Confirm a push and in-app notification are dispatched but no SMS is sent. Re-enable SMS; publish another payment event; confirm all three channels are used.

**Acceptance Scenarios**:

1. **Given** an authenticated user opens their notification preferences, **When** they disable a non-mandatory channel (e.g., SMS) for a notification category (e.g., payment received), **Then** the preference is persisted; subsequent notifications of that category skip the SMS channel for this user.
2. **Given** a user attempts to disable a mandatory notification category (e.g., security alerts, financial debit/credit), **When** the request is submitted, **Then** the system rejects the change with an appropriate error; mandatory notifications remain enabled.
3. **Given** a user has opted out of marketing notifications, **When** a marketing event is published for that user, **Then** no notification is created or dispatched to any channel; the event is silently discarded.
4. **Given** a new user is registered, **When** their initial preferences are created, **Then** all mandatory categories are enabled on all registered channels by default; marketing notifications default to opted-out.
5. **Given** a user opts in to marketing notifications, **When** the preference is saved, **Then** subsequent marketing events for that user are dispatched to their preferred channels.
6. **Given** a user updates their preferences, **When** a notification is already in-flight (queued), **Then** the in-flight delivery completes using the preference at time of dispatch; the new preference applies to all subsequent notifications.

---

### User Story 3 — Mandatory Financial Notifications (Priority: P0)

Certain notification categories are non-negotiable: debits, credits, failed payments, security alerts (new device login, PIN change, suspicious activity), and regulatory compliance messages must always reach the user. The system enforces this by flagging these categories as mandatory and bypassing user preference checks at dispatch time. These notifications are dispatched to all of the user's registered channels, regardless of per-category opt-out settings.

**Why this priority**: Regulatory and compliance requirements mandate that users are notified of all financial movements on their accounts. A debit notification that is silently dropped because a user opted out of push notifications is a compliance failure and a support liability.

**Independent Test**: A user disables all notification channels in their preferences. Publish a debit event for that user. Confirm that at least one mandatory delivery is dispatched (in-app always available; push/SMS if device/phone is registered). Confirm the preference override is reflected in the audit log.

**Acceptance Scenarios**:

1. **Given** a financial debit event is published for a user who has disabled all optional notification channels, **When** the notification platform processes the event, **Then** the notification is still created and dispatched on all available channels, bypassing the user's opt-out settings; the audit log records that mandatory override was applied.
2. **Given** a security alert event is published (e.g., new device registered, password changed), **When** the event is received, **Then** the notification is dispatched on all channels regardless of user preferences; the notification content clearly identifies the security action taken and includes a reference for contacting support if the action was unauthorized.
3. **[Deferred — compliance-management epic]** **Given** a regulatory compliance message is required (e.g., account terms update), **When** the event is published, **Then** the notification is delivered to all users in the affected group; a delivery confirmation record is kept per user. *E17 provides the delivery infrastructure; the business logic for selecting recipient groups and triggering compliance messages is deferred to a future compliance-management epic. This scenario is not testable within E17 scope.*
4. **Given** a mandatory notification is successfully delivered, **When** the delivery is confirmed, **Then** a compliance audit record is written indicating the notification type, recipient, channel, and confirmation timestamp.

---

### Edge Cases

1. **Duplicate event from outbox retry** — The same integration event ID is received twice; idempotency check on event ID prevents a second `Notification` record from being created; second processing is a no-op.
2. **User has all non-mandatory channels disabled** — For non-mandatory, non-marketing notifications where no enabled channels exist: no `NotificationDelivery` records are created; the notification status is set to `failed`; a structured warning log entry is emitted with key `notification.no_channels_enabled`. Mandatory notifications always reach all available channels regardless of user preferences (see FR-E17-008). In-app is always available as a fallback for mandatory and security notifications.
3. **Template variable missing at render time** — Notification is rendered with a safe default (`[Unknown]`) for the missing variable; delivery proceeds; an ops alert is raised to update the template mapping.
4. **Provider outage (FCM, SMS gateway)** — Delivery fails for all push/SMS attempts; retry runs with exponential backoff; after max retries, delivery is marked `permanently_failed`; ops team alerted; in-app channel unaffected by provider state.
5. **User deregisters a device mid-delivery** — The delivery to the deregistered token fails at the provider; token invalidation is recorded; no retry to the same token; delivery to remaining channels continues.
6. **Notification for a user account that no longer exists** — Notification creation fails at recipient resolution; event is logged with `recipient_not_found` error; no delivery is attempted; audit entry recorded.
7. **High volume burst (e.g., mass commission batch)** — Notification creation and dispatch are queue-backed; the queue absorbs burst traffic; delivery latency may increase under peak load but no notifications are dropped.
8. **Marketing notification sent to opted-out user** — Dispatch is blocked at preference check; no `NotificationDelivery` record is created; event is discarded with a structured log entry.

---

## Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-E17-000 | The system must consume integration events from upstream modules (E08 funding, E09 transfer, E10 withdrawal, E14 commission, E15 bill payment, E16 QR payment, E03 security) and route each event type to the appropriate notification category |
| FR-E17-001 | Supported delivery channels: push notification (mobile devices), SMS, and in-app (platform inbox); email is supported as a secondary channel |
| FR-E17-002 | Notification creation and delivery must be idempotent — the same upstream event ID must not produce more than one `Notification` record or more than one delivery attempt per channel |
| FR-E17-003 | Each notification must be persisted in a `pending` or `queued` state before any delivery is attempted; no fire-and-forget delivery |
| FR-E17-004 | Each channel delivery attempt is tracked in a `NotificationDelivery` record with status (queued, sent, delivered, failed, permanently_failed), attempt count, and last attempt timestamp |
| FR-E17-005 | Failed delivery attempts must be automatically retried with exponential backoff; retry count and initial delay must be configurable and not hard-coded |
| FR-E17-006 | After the configurable maximum retry count is exceeded, the delivery is marked `permanently_failed`; an audit event is emitted and an ops alert is raised |
| FR-E17-007 | Users can configure per-category delivery channel preferences for non-mandatory notification categories; preferences must be persisted and respected at dispatch time |
| FR-E17-008 | Mandatory notification categories (financial debit/credit, security events, compliance messages) must bypass user preference opt-outs and be delivered on all available channels |
| FR-E17-009 | Marketing/promotional notifications must default to opted-out for all users; a user must explicitly opt in before any marketing notification is dispatched to them |
| FR-E17-010 | Notification content is rendered from templates; templates support variable substitution and are managed centrally; template variables that cannot be resolved must fall back to a safe default |
| FR-E17-011 | Users must be able to retrieve their in-app notification inbox as a paginated, timestamped list; each notification has a read/unread state |
| FR-E17-012 | Users can mark in-app notifications as read individually or mark all as read; the unread count must be queryable and accurate |
| FR-E17-013 | Push device tokens are registered per user/device; invalid or expired tokens discovered during delivery must be automatically invalidated and removed |
| FR-E17-014 | All notification creation, delivery dispatch, delivery outcome (success/failure), preference changes, and mandatory override events must be auditable |
| FR-E17-015 | Delivery dispatch must be queue-backed to absorb burst traffic; peak volumes (e.g., bulk commission payouts) must not cause notification loss |

## Key Entities

- **Notification** — A notification to be delivered to a recipient; has a source event ID (for idempotency), notification type/category, recipient user ID, rendered subject and body, status (`queued` → `dispatching` → `partially_delivered` / `delivered` / `failed`), creation timestamp, and a reference to the triggering event correlation ID
- **NotificationDelivery** — Per-channel delivery record for a single `Notification`; tracks channel (push / SMS / email / in-app), status (queued / sent / delivered / failed / permanently_failed), attempt count, provider response, and last attempt timestamp
- **NotificationTemplate** — Template definition for a notification type; contains name, category, subject template string, body template string, supported channels, and variable schema
- **NotificationPreference** — Per-user preference record; stores enabled/disabled channels per notification category and marketing opt-in status; created with safe defaults on user registration
- **DeviceToken** — Push notification device registration; links user ID and device ID to a provider token (FCM/APNS), platform (iOS/Android), and active/invalid status

## Success Criteria

| ID | Criterion | Target |
|----|-----------|--------|
| SC-E17-001 | Transactional notification delivery latency (payment received, debit/credit) | In-app and push dispatched within 5 seconds of the triggering event being received |
| SC-E17-002 | Mandatory financial notification delivery rate | 99% successfully delivered within 30 seconds of event (after retries) |
| SC-E17-003 | Idempotency | Zero duplicate notifications delivered for the same upstream event ID |
| SC-E17-004 | Retry reliability | All failed deliveries with remaining retry budget are automatically re-attempted without manual intervention |
| SC-E17-005 | Preference effectiveness | A user's channel preferences are honoured for all non-mandatory notifications dispatched after the preference is saved |
| SC-E17-006 | In-app inbox availability | A user's complete in-app notification history is retrievable at any time, with accurate unread count |

## Assumptions

1. Push notification delivery adapters (FCM for Android, APNS for iOS) and the SMS gateway adapter follow the provider-adapter-foundation pattern (E1000); E17 binds to the adapter interface and does not directly depend on any specific provider SDK. Provider credentials are managed via E28 Platform Configuration.
2. Device token registration and de-registration are managed by E03 Device & Session Management; E17 reads device tokens from E03's registry but does not own device lifecycle.
3. Notification template content (copy, subject lines, variable schemas) is authored and managed via E19 Admin Portal; E17 provides template storage, lookup, and rendering only.
4. Marketing notifications default to opted-out for all users; regulatory requirement assumed to be standard opt-in consent for marketing communications.
5. In-app notifications are the unconditional fallback channel — every user always has an in-app inbox regardless of device registrations or preference settings.
6. The notification processing pipeline is event-driven and queue-backed; E17 does not poll upstream modules. Events are delivered via the outbox pattern established in E09.
7. Read receipt acknowledgement from push/SMS providers is best-effort; `delivered` status is set when provider confirms acceptance, not end-device receipt.

## Out of Scope

- Device registration and push token management lifecycle (E03 Device & Session Management)
- Notification template authoring and marketing campaign management (E19 Admin Portal)
- Real-time WebSocket / Server-Sent Events push to web browsers (deferred to E33 Admin Application)
- Rich media push notifications (images, action buttons, deep links) — deferred to a future enhancement
- Email marketing platform integration (e.g., Mailchimp, SendGrid campaigns)
- Two-way SMS responses / USSD interaction
- In-app chat or direct messaging between users
- Notification analytics and campaign reporting (E24 Reporting & Analytics)
- Regulatory compliance broadcast messages (e.g., T&Cs updates, policy change notifications) — E17 provides the delivery infrastructure; the business logic for triggering compliance messages and selecting recipient groups is deferred to a future compliance-management epic
