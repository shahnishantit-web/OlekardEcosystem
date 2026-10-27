# Implementation Plan: P11 — Outbox Inbox Notifications and Domain Delivery

**Branch**: `060-outbox-inbox-delivery` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/060-outbox-inbox-delivery/spec.md`

---

## Summary

P11 closes four residual gaps in the outbox/inbox pipeline established by P08 and E27:

1. **Consumer wiring** — `payment.confirmed`, `payment.failed`, `payment.reversed`, and `refund.confirmed` events are dispatched via `InProcessDriver` (Laravel events) but no listeners exist to create notifications or allocations.
2. **Causation ID propagation** — `OutboxEventPublisher.buildRow()` hardcodes `causation_id = null`; no publishing context carries the prior event ID.
3. **Outbox queue health** — no endpoint exposes oldest-pending-age per event type; the existing `DeadLetterAdminController` (E27) operates on `DeadLetterItemModel`, not on `OutboxEventModel` dead-letter rows.
4. **Outbox dead-letter admin** — outbox events with `status = dead_letter` are not exposed through any admin API; replay requires direct DB manipulation.

No new wallet module methods, no new ledger entries, no new generic messaging platform. All changes are confined to the Payment, Platform, and Notification modules' application layers.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11)

**Primary Dependencies**:
- `App\Modules\Platform\Infrastructure\EventBus\InProcessDriver` — dispatches outbox events as Laravel events
- `App\Modules\Platform\Application\Services\OutboxEventPublisher` — writes events to `platform.outbox_events` atomically
- `App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel` — Eloquent model for `platform.outbox_events`
- `App\Modules\Notification\Application\Services\NotificationService` — creates notifications with `source_event_id` deduplication
- `App\Modules\Payment\Infrastructure\Models\PaymentAllocationModel` — idempotency via `idempotency_key` unique index
- `App\Modules\Platform\Domain\Contracts\IntegrationEventInterface` — minimal event contract
- `App\Jobs\OutboxDispatcherJob` — polls and dispatches outbox events; handles backoff and dead-letter

**Storage**: No new migrations required.
- `platform.outbox_events` — existing table; `causation_id` column already present but always null
- `notification.notifications` — existing table; `(source_event_id, notification_type)` unique index already present
- `payment.payment_allocations` — existing table; `idempotency_key` unique index already present

**Testing**: PHPUnit (Laravel) — Unit tests and Feature tests

**Target Platform**: Laravel 11 modular-monolith API backend (`wallet-ecosystem/services/api/`)

**Project Type**: Backend event wiring + API extension (listeners, service, controller, routes)

**Performance Goals**: Queue health endpoint < 200 ms p95 (aggregate query on outbox table with index on `status` + `event_name`)

**Constraints**:
- `WalletModuleContract` MUST NOT be modified
- `LedgerModuleContract` MUST NOT be called from this feature (P08 owns ledger posting)
- `IntegrationEventInterface` extension MUST be backward-compatible (default `null` for `causationId()`)
- Dead-letter replay MUST be audited; no silent state mutation
- No exactly-once transport guarantee claimed
- Reuse existing notification deduplication (`source_event_id`) and allocation idempotency (`idempotency_key`); do not introduce a third deduplication mechanism

**Scale/Scope**: ~6 new PHP files + 2 route additions; zero new migrations; zero schema changes

---

## Constitution Check

| Principle | Requirement | Status |
|-----------|------------|--------|
| P2 — TBD Governance | No invented values; source references FD07/FD11/LC05 noted; event names from approved section 6 contract | ✅ PASS |
| P4 — Financial Integrity | No balance mutations; no ledger entries; notification + allocation use existing idempotent paths | ✅ PASS |
| P4 — Financial Integrity | Replay of dead-letter outbox events is audited; no double-debit possible (idempotency keys) | ✅ PASS |
| P5 — Identity/Authorization | Operations dead-letter and queue-health endpoints gated behind `operations.dead_letter.manage` permission | ✅ PASS |
| P7 — Domain Boundaries | Payment, Platform, and Notification modules each own their respective event handler/service; no cross-domain direct DB access | ✅ PASS |
| P9 — Event Reliability | Causation ID propagated from event context; consumer deduplication reuses existing unique constraints | ✅ PASS |
| P12 — Test-First Quality | Each story has acceptance criteria and PHPUnit tests | ✅ PASS |
| P13 — Observability | Audit record on every dead-letter replay; structured log on every stuck-processing reset (existing); queue health metrics exposed | ✅ PASS |
| P14 — No Silent Architecture Changes | `IntegrationEventInterface` extended with backward-compatible default; no new broker/platform | ✅ PASS |

**Gate result**: PASS — no violations. Proceed to Phase 0 research.

---

## Project Structure

### Documentation (this feature)

```text
specs/060-outbox-inbox-delivery/
├── plan.md              ← This file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/
│   └── outbox-admin-contract.md  ← Phase 1 output
└── tasks.md             ← Phase 2 output (/speckit-tasks — NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/
├── app/Modules/Platform/
│   ├── Domain/Contracts/
│   │   └── IntegrationEventInterface.php         ← MODIFIED (P11: +causationId(): ?string default null)
│   ├── Application/Services/
│   │   └── OutboxEventPublisher.php              ← MODIFIED (P11: buildRow sets causation_id from event)
│   │   └── OutboxQueueHealthService.php           ← NEW (P11: lag/age metrics per event_name)
│   └── Infrastructure/Http/Controllers/
│       └── OutboxAdminController.php              ← NEW (P11: health + outbox dead-letter routes)
│
├── app/Modules/Payment/
│   └── Application/EventHandlers/
│       └── PaymentConfirmedListener.php          ← NEW (P11: notification + allocation on payment.confirmed)
│       └── PaymentFailedListener.php             ← NEW (P11: notification on payment.failed)
│       └── PaymentReversedListener.php           ← NEW (P11: notification on payment.reversed)
│       └── RefundConfirmedListener.php           ← NEW (P11: notification on refund.confirmed)
│       └── PaymentEventSubscriber.php            ← NEW (P11: registers all 4 listeners)
│
├── app/Modules/Notification/
│   └── Application/Commands/
│       └── CreatePaymentNotificationCommand.php  ← NEW (P11: DTO for notification creation from payment event)
│
└── routes/modules/
    └── reliability.php                           ← MODIFIED (P11: add outbox health + outbox dead-letter routes)
```

**Structure decision**: All changes are confined to the Platform, Payment, and Notification module application layers. No new modules. No new migrations. Listener registration via `PaymentServiceProvider`.

---

## Complexity Tracking

> No constitution violations requiring justification.
