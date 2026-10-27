# Data Model: P23 — Payment History, Detail and Transaction Management

**Date**: 2026-10-26
**Branch**: `070-payment-history-detail`

---

## Entities

### 1. PaymentHistoryItem

Upgraded from the existing `Transaction` entity. Maps to the P19 `PaymentHistoryItem` schema.

| Field | Type | Notes |
|-------|------|-------|
| `id` | `String` | UUID v7 — `payment_intent_id` |
| `purposeType` | `String` | e.g. `wallet_funding`, `p2p_transfer`, `bill_payment` |
| `purposeReference` | `String` | Domain object ID |
| `displayTitle` | `String` | Human-readable title from server |
| `displaySubtitle` | `String?` | Secondary description |
| `amountMinor` | `int` | Monetary amount in minor units (never computed locally) |
| `currencyCode` | `String` | ISO 4217 |
| `direction` | `PaymentDirection` | `credit` / `debit` |
| `status` | `PaymentStatus` | Sealed enum (see below) |
| `captureStatus` | `String` | Discrete capture status row value |
| `accountingStatus` | `String` | Discrete accounting/ledger status row |
| `serviceStatus` | `String` | Discrete service-readiness row |
| `refundStatus` | `String?` | Discrete refund status row; null if never refunded |
| `refundEligible` | `bool` | Server-authoritative; never overridden locally |
| `domainDeepLink` | `DomainDeepLink` | Sealed: `AvailableLink` / `UnavailableLink` |
| `providerName` | `String?` | Display name of payment provider |
| `createdAt` | `DateTime` | UTC |
| `completedAt` | `DateTime?` | UTC; null if not yet completed |
| `receiptAvailable` | `bool` | Whether receipt endpoint is populated |

**State transitions**: `PaymentStatus` is read-only from the server; the app never transitions it locally.

```dart
enum PaymentDirection { credit, debit }

enum PaymentStatus {
  pending,
  processing,
  completed,
  failed,
  cancelled,
  expired,
  refunded,
  partiallyRefunded,
}
```

---

### 2. PaymentHistoryFilter

Value object representing the active filter state for the history list.

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `purposeType` | `String?` | `null` | Maps to P19 `purpose_type` query param |
| `status` | `PaymentStatus?` | `null` | Maps to P19 `status` enum |
| `dateFrom` | `DateTime?` | `null` | Inclusive; UTC |
| `dateTo` | `DateTime?` | `null` | Inclusive; UTC |
| `currencyCode` | `String?` | `null` | ISO 4217 |
| `page` | `int` | `1` | 1-indexed |
| `perPage` | `int` | `20` | Max 100 |

**Equality**: Two filters with identical field values are equal (value object semantics). Changing any field resets `page` to 1.

---

### 3. PaymentHistoryPage

Paginated result container.

| Field | Type | Notes |
|-------|------|-------|
| `items` | `List<PaymentHistoryItem>` | Current page items |
| `currentPage` | `int` | |
| `lastPage` | `int` | |
| `total` | `int` | Total matching records |
| `hasMore` | `bool` | `currentPage < lastPage` |
| `filter` | `PaymentHistoryFilter` | Filter that produced this page |

---

### 4. PaymentReceipt

Full receipt from `GET /v1/payment/receipts/{intentId}`.

| Field | Type | Notes |
|-------|------|-------|
| `intentId` | `String` | UUID v7 |
| `receiptNumber` | `String` | Human-readable reference |
| `item` | `PaymentHistoryItem` | Embedded summary |
| `lineItems` | `List<ReceiptLineItem>` | Fee breakdown, taxes |
| `providerReference` | `String?` | Masked in display; never logged |
| `issuedAt` | `DateTime` | UTC |
| `expiresAt` | `DateTime?` | If receipt has limited availability |

**Security**: `providerReference` is masked in display (last 4 chars only) and must never appear in log lines.

---

### 5. ReceiptLineItem

| Field | Type | Notes |
|-------|------|-------|
| `label` | `String` | e.g. `Transaction Fee`, `VAT` |
| `amountMinor` | `int` | Positive = charge; negative = discount |
| `currencyCode` | `String` | |

---

### 6. ReceiptDownloadToken

Single-use credential from `POST /v1/payment/receipts/{intentId}/download-token`.

| Field | Type | Notes |
|-------|------|-------|
| `token` | `String` | URL path segment credential — never cached or logged |
| `expiresAt` | `DateTime` | ~60 s from issue |
| `downloadUrl` | `String` | Full URL; open immediately via url_launcher |

**Lifecycle constraint**: This entity must not be stored, must not appear in analytics events, and must not be reconstructed from cache. It is discarded after `url_launcher` opens the URL.

---

### 7. DomainDeepLink (sealed)

Sealed class representing the `domain_deep_link` field from P19.

```dart
sealed class DomainDeepLink {
  const DomainDeepLink();
}

final class AvailableLink extends DomainDeepLink {
  final String url;          // absolute URL or in-app GoRouter path
  final String? label;       // optional display label
  const AvailableLink({required this.url, this.label});
}

final class UnavailableLink extends DomainDeepLink {
  final String reason;       // e.g. "domain_retired"
  const UnavailableLink({required this.reason});
}
```

---

### 8. RefundRequest

POST body sent to E62 `POST /v1/payment/refunds`.

| Field | Type | Notes |
|-------|------|-------|
| `paymentIntentId` | `String` | Stable reference from `PaymentHistoryItem.id` |
| `reason` | `RefundReason` | Server-validated enum |
| `amountMinor` | `int?` | For partial refunds; null = full refund |
| `idempotencyKey` | `String` | UUID v4 generated per request |

**Constraint**: `amountMinor` must not exceed the original `PaymentHistoryItem.amountMinor`. This validation is enforced server-side; the app presents the server error verbatim.

---

### 9. SupportTicketDraft

POST body sent to E22 `POST /v1/support/tickets`.

| Field | Type | Notes |
|-------|------|-------|
| `paymentIntentId` | `String` | Stable reference — pre-filled, not editable |
| `subject` | `String` | Pre-filled from `PaymentHistoryItem.displayTitle`; editable |
| `description` | `String` | User-typed |
| `attachmentPaths` | `List<String>` | Local file paths (PDF/PNG/JPEG) — uploaded via signed URL |

**Attachment constraint**: MIME type check on client (user-facing error if wrong type); enforced independently server-side. Max attachment size is server-enforced; client shows upload progress.

---

### 10. PaymentCacheEntry

Serialisable record stored in `flutter_secure_storage`.

| Field | Type | Notes |
|-------|------|-------|
| `key` | `String` | `payment_history_{userId}` or `payment_detail_{intentId}` |
| `payload` | `String` | JSON-encoded `PaymentHistoryPage` or `PaymentHistoryItem` |
| `cachedAt` | `DateTime` | UTC; used to compute stale age |
| `staleAfterSeconds` | `int` | 300 (5 min) for list; 600 (10 min) for detail |

**Eviction**: Full eviction (all keys with `payment_` prefix) on logout or account switch. Stale-read serves the cached payload with `isStale: true` in the notifier state when network is unavailable.

---

## Relationships

```
PaymentHistoryPage
  └── items: List<PaymentHistoryItem>
            ├── domainDeepLink: DomainDeepLink (sealed)
            └── refundEligible: bool

PaymentReceipt
  ├── item: PaymentHistoryItem (summary)
  └── lineItems: List<ReceiptLineItem>

ReceiptDownloadToken (ephemeral — not related to PaymentReceipt in storage)

RefundRequest → references PaymentHistoryItem.id
SupportTicketDraft → references PaymentHistoryItem.id
PaymentCacheEntry → wraps PaymentHistoryPage | PaymentHistoryItem
```

---

## State Machines

### PaymentHistoryNotifier States

```
Initial
  → PaymentHistoryLoading (on first build)
    → PaymentHistoryLoaded(items, filter, pagination, isStale: false)
    → PaymentHistoryEmpty(filter)
    → PaymentHistoryError(message, isOffline: true)   ← serves stale if available
  PaymentHistoryLoaded
    → PaymentHistoryRefreshing(currentItems)           ← pull-to-refresh
    → PaymentHistoryLoaded (next page appended)
    → PaymentHistoryLoaded (filter changed, reset page)
```

### PaymentDetailNotifier States

```
Initial
  → PaymentDetailLoading
    → PaymentDetailLoaded(item, statusRows, deepLink)
    → PaymentDetailError(message)
  PaymentDetailLoaded
    → PaymentDetailRefundConfirm(item)               ← user taps Refund
    → PaymentDetailSupportDraft(item)                ← user taps Support
```

### ReceiptAccessNotifier States

```
Initial
  → ReceiptAccessLoading
    → ReceiptReady(receipt)
    → ReceiptAccessDenied(reason)                    ← 403 from server
    → ReceiptAccessError(message)
  ReceiptReady
    → ReceiptDownloadRequested(receipt)              ← user taps Download
      → ReceiptDownloadReady(downloadUrl, expiresAt) ← token obtained; open immediately
      → ReceiptDownloadError(message)
```
