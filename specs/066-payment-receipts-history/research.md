# Research: P19 — Receipts, Communication and Payment History APIs

**Date**: 2026-10-25
**Status**: Complete — all decisions resolved

---

## Decision 1: History Storage Pattern

**Decision**: Payment history is a **read-only aggregation** of existing tables — no new history table is created.

**Rationale**: `payment_intents` already stores `payer_actor_id`, `purpose_type`, `purpose_reference`, `organisation_id`, `amount_minor`, `currency_code`, and timestamps. `payment_attempts` stores `fee_minor` and `provider_code`. `payment_refunds` stores refund amounts and status. A new history table would duplicate data and introduce synchronisation risk.

**Alternatives considered**:
- **Separate history read model table** — rejected: would require event-sourced synchronisation, creating dual sources of truth for financial data.
- **Materialised view** — possible in PostgreSQL but introduces schema migration complexity; deferred to post-MVP optimisation if query performance demands it.

---

## Decision 2: Receipt Data Source

**Decision**: Receipt data is compiled **exclusively from ledger journal entries and fee snapshots at attempt time** — specifically `payment_attempts.fee_minor` for the confirmed fee and `payment_intents.amount_minor` for the original amount.

**Rationale**: Constitution Principle 4 (Financial Integrity) requires that receipts reconcile to the authoritative ledger. Mutable fields such as `payment_intents.metadata` must not be used as receipt sources.

**Alternatives considered**:
- Computing from intent + refund delta — rejected: would show different amounts than the ledger if a rounding edge case existed.

---

## Decision 3: Receipt Download Token Storage

**Decision**: Download tokens are stored in a new table `payment.receipt_download_tokens` in the `payment` PostgreSQL schema.

**Rationale**: Tokens need: (a) ownership check (requestor_id), (b) expiry enforcement, (c) single-use enforcement, (d) audit trail. Cache-only storage (as used by `Admin\ExportService`) is insufficient for receipt downloads because tokens must survive cache eviction and be auditable.

**Token design**:
- Token value: `bin2hex(random_bytes(32))` — 64-char hex, cryptographically random
- TTL: configurable via `payment.receipt.download_token_ttl_minutes` (default: 15)
- Single-use: `used_at` timestamp set on first redemption; second request returns 410

**Alternatives considered**:
- Signed JWT with expiry claim — avoided: requires key rotation management; DB-backed token gives direct revocation.
- Cache-only (Redis) — rejected: not auditable; eviction risk for security-sensitive token.

---

## Decision 4: Export Token Storage

**Decision**: Export requests and their download tokens are stored in `payment.payment_export_requests` with an async job to generate the CSV file.

**Rationale**: Exports may be large; generating them synchronously in an HTTP response is not viable. The async pattern from `Admin\ExportService` (Cache + Job) is a reference, but P19 uses DB persistence for auditability as required by FR-020.

**Export flow**:
1. `POST /v1/admin/payment/exports` → creates `PaymentExportRequest` row with `status=queued`, dispatches `GeneratePaymentExportJob`
2. Job generates CSV with formula safety, stores file via Storage, updates row with `token`, `row_count`, `generated_at`, `token_expires_at`
3. `GET /v1/admin/payment/exports/{exportId}` → returns status + download URL if ready
4. `GET /v1/admin/payment/exports/download/{token}` → redeems token, streams file, marks `downloaded_at`

---

## Decision 5: Receipt Notification Deduplication

**Decision**: Deduplication is enforced via a new table `payment.receipt_notification_deliveries` with a UNIQUE index on `event_idempotency_key`.

**Rationale**: The P11 outbox can replay events. Idempotency must be enforced at the notification handler level, not just at the delivery level. Storing the `event_idempotency_key` in a P19-owned table gives explicit control over deduplication without coupling to P11 internals.

**Key**: `event_idempotency_key` = the outbox event's `idempotency_key` field (set by `PaymentConfirmedEvent.correlationId` + event name).

**Alternatives considered**:
- Relying solely on P11 delivery deduplication — rejected: P11 deduplicates delivery attempts but a replayed outbox event with a new delivery record would still result in a second notification.

---

## Decision 6: Notification Listener Registration

**Decision**: Two new listener classes in `Notification` module: `PaymentConfirmedReceiptListener` and `RefundConfirmedReceiptListener`, following the exact pattern of `FundingCompletedListener`.

**Rationale**: Existing listeners (e.g., `FundingCompletedListener`) use `NotificationService::createFromEvent()` with `CreateNotificationCommand`. This pattern is well-established and tested. The deduplication record is written inside the listener before dispatching.

**Event wiring**: Listeners are registered in `NotificationServiceProvider` (or equivalent), not in `PaymentServiceProvider` — keeping notification responsibility in the Notification module.

---

## Decision 7: Formula Injection Safety

**Decision**: Apply RFC 4180 CSV quoting; additionally prefix any field value beginning with `=`, `+`, `-`, `@`, `\t`, `\r` with a single quote (`'`) character.

**Rationale**: OWASP CSV injection prevention best practice. The single-quote prefix is universally recognised by Excel, Google Sheets and LibreOffice Calc as a text prefix and prevents formula execution.

**Implementation**: A `CsvFormulaSanitiser` value object with a static `sanitise(string $value): string` method, applied to every text field at write time.

---

## Decision 8: Domain Deep Link Format

**Decision**: The `domain_deep_link` field uses the format `{purpose_type}://{purpose_reference}`. The mobile client resolves the routing. P19 does not validate whether the link is currently resolvable.

**Rationale**: Domain routing tables change (modules can be retired or renamed). P19 cannot maintain a registry of valid deep link patterns for every domain. The mobile shell is responsible for routing unknown schemes gracefully.

**Handling retired domains**: If a `purpose_type` is in a configured `payment.deep_link.retired_purposes` list, the field is returned as `{"status": "unavailable", "reason": "domain_retired"}`.

---

## Decision 9: Notification Template Keys

**Decision**: Two new notification template slugs are registered:
- `payment.receipt.payment_confirmed` — for `payment.confirmed` event notifications
- `payment.receipt.refund_confirmed` — for `refund.confirmed` event notifications

**Rationale**: Template versioning is handled by the existing `NotificationTemplateModel` — templates are fetched by slug and version at send time; the sent version is stored in `receipt_notification_deliveries` for audit.

---

## Decision 10: Migration Sequence

**Decision**: P19 migrations start at `2026_10_25_000024` (continuing from P18's `000023`).

| Migration | Description |
|-----------|-------------|
| `2026_10_25_000024` | `receipt_download_tokens` table |
| `2026_10_25_000025` | `payment_export_requests` table |
| `2026_10_25_000026` | `receipt_notification_deliveries` table + 5 P19 permissions |

---

## Decision 11: Permissions

The following new permissions are seeded in `identity.permissions`:

| Permission | Description |
|---|---|
| `payment.history.read` | Consumer/agent: view own payment history |
| `payment.history.read.organisation` | Org admin: view organisation-scoped history |
| `payment.history.read.platform` | Platform finance: cross-organisation history |
| `payment.export.read.organisation` | Request and download org-scoped CSV export |
| `payment.export.read.platform` | Request and download platform-scoped CSV export |

---

## Predecessor Evidence Summary

| Predecessor | Status | Key Contracts Used |
|---|---|---|
| P08 Ledger Ack | COMPLETE/RELEASE READY | `PaymentConfirmedEvent` (payment_intent_id, fee_minor, payer_actor_id, amount_minor) |
| P11 Outbox Delivery | COMPLETE/RELEASE READY | `OutboxEventPublisher`, `NotificationService::createFromEvent()`, `CreateNotificationCommand` |
| P15 Shared Refund Engine | COMPLETE/RELEASE READY | `RefundConfirmedEvent` (payment_intent_id, refund_amount_minor, payer not on event — resolved from intent) |

**Note on refund event**: `RefundConfirmedEvent` does not carry `payer_actor_id` directly — P19 must resolve the payer by loading `PaymentIntentModel.payer_actor_id` using `paymentIntentId` from the event.
