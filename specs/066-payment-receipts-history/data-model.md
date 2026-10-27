# Data Model: P19 — Receipts, Communication and Payment History APIs

**Date**: 2026-10-25

---

## Overview

P19 introduces three new DB tables in the `payment` schema and two read-model projections (no DB table — derived at query time). All existing financial data is read-only from P19's perspective.

---

## New DB Tables

### `payment.receipt_download_tokens`

**Migration**: `2026_10_25_000024`
**Purpose**: Single-use, expiring tokens for authorised receipt downloads.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | `uuid` PK | NOT NULL | UUID v7 |
| `payment_intent_id` | `uuid` FK | NOT NULL | → `payment.payment_intents.id` |
| `requestor_id` | `varchar(100)` | NOT NULL | Actor ID of authenticated requester |
| `token` | `char(64)` UNIQUE | NOT NULL | `bin2hex(random_bytes(32))` |
| `used_at` | `timestamptz` | NULL | Set on first redemption |
| `expires_at` | `timestamptz` | NOT NULL | `created_at + config(payment.receipt.download_token_ttl_minutes)` |
| `created_at` | `timestamptz` | NOT NULL | |
| `updated_at` | `timestamptz` | NOT NULL | |

**Indexes**: `UNIQUE (token)`, `INDEX (payment_intent_id)`, `INDEX (requestor_id, created_at)`

**State transitions**:
- `pending` (used_at IS NULL AND expires_at > now) → `used` (used_at set) or `expired` (expires_at <= now)
- `used` and `expired` are terminal — no re-activation

**PHP model**: `App\Modules\Payment\Infrastructure\Models\ReceiptDownloadTokenModel`
**PHP enum** (derived, not stored): `App\Modules\Payment\Domain\Enums\ReceiptDownloadTokenStatus` (`pending`, `used`, `expired`)

---

### `payment.payment_export_requests`

**Migration**: `2026_10_25_000025`
**Purpose**: Audit record for export requests; tracks generation status and single-use download token.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | `uuid` PK | NOT NULL | UUID v7 |
| `requestor_id` | `varchar(100)` | NOT NULL | Actor ID |
| `scope_type` | `varchar(30)` | NOT NULL | `organisation` or `platform` |
| `organisation_id` | `varchar(100)` | NULL | NULL when scope_type = `platform` |
| `filters` | `jsonb` | NOT NULL | `{purpose_type?, currency_code?, date_from?, date_to?, status?}` |
| `status` | `varchar(30)` | NOT NULL | See enum below; DEFAULT `queued` |
| `token` | `char(64)` UNIQUE | NULL | Set after generation completes |
| `row_count` | `integer` | NULL | Set after generation completes |
| `generated_at` | `timestamptz` | NULL | Set after generation completes |
| `token_expires_at` | `timestamptz` | NULL | Set after generation completes |
| `downloaded_at` | `timestamptz` | NULL | Set on token redemption |
| `failure_reason` | `text` | NULL | Set if status = `failed` |
| `created_at` | `timestamptz` | NOT NULL | |
| `updated_at` | `timestamptz` | NOT NULL | |

**Indexes**: `UNIQUE (token)`, `INDEX (requestor_id, created_at)`, `INDEX (organisation_id, created_at)`

**State machine**:

```
queued → generating → ready → (downloaded via token)
              ↘ failed
ready → expired  (when token_expires_at <= now)
```

**PHP model**: `App\Modules\Payment\Infrastructure\Models\PaymentExportRequestModel`
**PHP enum**: `App\Modules\Payment\Domain\Enums\PaymentExportRequestStatus` (`queued`, `generating`, `ready`, `failed`, `expired`)

---

### `payment.receipt_notification_deliveries`

**Migration**: `2026_10_25_000026`
**Purpose**: Deduplication record for outbox event → notification dispatch. Prevents duplicate notifications on replay.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | `uuid` PK | NOT NULL | UUID v7 |
| `event_idempotency_key` | `varchar(200)` UNIQUE | NOT NULL | From outbox event; UNIQUE enforces deduplication |
| `event_type` | `varchar(100)` | NOT NULL | `payment.confirmed` or `refund.confirmed` |
| `payment_intent_id` | `uuid` | NOT NULL | Resolved from event |
| `notification_id` | `uuid` | NULL | Set when notification service creates the record |
| `status` | `varchar(30)` | NOT NULL | `dispatched`, `failed`, `skipped` |
| `template_slug` | `varchar(200)` | NULL | Sent template slug (for audit) |
| `template_version` | `varchar(50)` | NULL | Sent template version (for FR-018 audit) |
| `created_at` | `timestamptz` | NOT NULL | |
| `updated_at` | `timestamptz` | NOT NULL | |

**Indexes**: `UNIQUE (event_idempotency_key)`, `INDEX (payment_intent_id)`, `INDEX (event_type, created_at)`

**PHP model**: `App\Modules\Payment\Infrastructure\Models\ReceiptNotificationDeliveryModel`
**PHP enum**: `App\Modules\Payment\Domain\Enums\ReceiptNotificationDeliveryStatus` (`dispatched`, `failed`, `skipped`)

---

## Permissions Seeded (migration 000026)

These permissions are inserted into `identity.permissions` by the P19 migration:

| Permission | Role Default |
|---|---|
| `payment.history.read` | Consumer, Agent |
| `payment.history.read.organisation` | Org Admin, Finance |
| `payment.history.read.platform` | Platform Finance, Platform Admin |
| `payment.export.read.organisation` | Org Finance |
| `payment.export.read.platform` | Platform Finance |

---

## Read-Model Projections (no new tables)

### PaymentHistoryRecord

Derived at query time by joining existing tables. Not persisted.

**Source tables**:
- `payment.payment_intents` (PI) — payer_actor_id, purpose_type, purpose_reference, organisation_id, amount_minor, currency_code, status, created_at, updated_at
- `payment.payment_attempts` (PA) — most recent confirmed attempt: fee_minor, provider_code, completed_at
- `payment.payment_refunds` (PR) — most recent non-failed refund: refund_amount_minor, status, confirmed_at

**Derived fields**:

| Field | Source |
|-------|--------|
| `payment_reference` | `PI.id` (UUID v7 formatted) |
| `purpose_type` | `PI.purpose_type` |
| `purpose_reference` | `PI.purpose_reference` |
| `domain_deep_link` | constructed: `"{PI.purpose_type}://{PI.purpose_reference}"` (server-side, FR-026) |
| `original_amount_minor` | `PI.amount_minor` |
| `currency_code` | `PI.currency_code` |
| `fee_amount_minor` | `PA.fee_minor` (confirmed attempt) or `PI.fee_quote_minor` (if no confirmed attempt yet) |
| `total_charged_minor` | `PI.amount_minor + PA.fee_minor` (payer cost, not net-of-fee) |
| `payment_status` | mapped from `PI.status` → safe set (FR-003) |
| `refund_status` | derived from `PR.status` → `none`, `pending`, `confirmed`, `failed` |
| `refund_amount_minor` | `PR.refund_amount_minor` or `null` |
| `provider_label` | `PA.provider_code` (display-formatted by config map) |
| `payment_date` | `PA.completed_at` (or `PI.created_at` if no confirmed attempt) |
| `organisation_id` | `PI.organisation_id` |

**Payment status safe mapping** (FR-003):

| `PI.status` | Exposed as |
|-------------|-----------|
| `pending` | `processing` |
| `processing` | `processing` |
| `confirmed` | `confirmed` (unless refund active — see refund logic) |
| `confirmed` + PR.status = `requested`/`approved`/`processing` | `refund_pending` |
| `confirmed` + PR.status = `confirmed` | `refunded` |
| `failed` | `failed` |
| `cancelled` | `cancelled` |
| `expired` | `cancelled` |

### ReceiptRecord

Derived at receipt request time from immutable data. Not persisted.

**Source tables**:
- `payment.payment_intents` — same as above (read immutable intent facts)
- `payment.payment_attempts` (confirmed attempt) — `fee_minor`, `completed_at`
- `payment.payment_refunds` (all non-failed refunds) — refund history for receipt summary

**Fields**:

| Field | Source |
|-------|--------|
| `payment_reference` | `PI.id` |
| `provider_label` | from `PA.provider_code` |
| `original_amount_minor` | `PI.amount_minor` |
| `currency_code` | `PI.currency_code` |
| `fee_amount_minor` | `PA.fee_minor` (confirmed attempt — immutable after ledger post) |
| `total_charged_minor` | `PI.amount_minor + PA.fee_minor` |
| `payment_date` | `PA.completed_at` |
| `purpose_summary` | short text from `PI.purpose_type` (display label, not full domain data) |
| `refund_summary` | list of `{amount_minor, status, confirmed_at}` per refund |
| `receipt_status` | `confirmed` always (404 returned for unconfirmed intents, FR-008) |

**Critical note** (FR-009): No field in `ReceiptRecord` is derived from `PI.metadata` or any mutable field. Only `fee_minor` from the confirmed `payment_attempt` row and `amount_minor` from the intent creation (immutable once created).

---

## Application Services (P19-owned)

| Service | Namespace | Responsibility |
|---------|-----------|---------------|
| `PaymentHistoryService` | `Application\Services` | Query payment_intents scoped to payer; build `PaymentHistoryRecord` projections |
| `ReceiptService` | `Application\Services` | Compile `ReceiptRecord` for a confirmed intent; enforce ownership |
| `ReceiptDownloadTokenService` | `Application\Services` | Issue, validate and redeem `ReceiptDownloadToken`; enforce expiry and single-use |
| `PaymentExportService` | `Application\Services` | Create `PaymentExportRequest`, dispatch job, validate scoping permissions |
| `CsvFormulaSanitiser` | `Domain\ValueObjects` | Static `sanitise(string): string` applying formula-injection safety (FR-021) |

## Jobs (P19-owned)

| Job | Queue | Responsibility |
|-----|-------|---------------|
| `GeneratePaymentExportJob` | `payment` | Read scoped history, apply sanitisation, write CSV to Storage, update `PaymentExportRequest` |

## Listeners (Notification module — P19 adds)

| Listener | Event Subscribed | Module |
|----------|-----------------|--------|
| `PaymentConfirmedReceiptListener` | `payment.confirmed` | `App\Modules\Notification\Listeners` |
| `RefundConfirmedReceiptListener` | `refund.confirmed` | `App\Modules\Notification\Listeners` |

Both listeners write to `payment.receipt_notification_deliveries` before dispatching to deduplicate. `payer_actor_id` for refund events is resolved from `payment_intents.payer_actor_id` using `payment_intent_id` from the event (the event itself does not carry `payer_actor_id`).

---

## Migration Sequence Summary

| Number | File | Description |
|--------|------|-------------|
| `000024` | `2026_10_25_000024_create_receipt_download_tokens_table.php` | `receipt_download_tokens` |
| `000025` | `2026_10_25_000025_create_payment_export_requests_table.php` | `payment_export_requests` |
| `000026` | `2026_10_25_000026_create_receipt_notification_deliveries_table.php` | `receipt_notification_deliveries` + 5 permissions |
