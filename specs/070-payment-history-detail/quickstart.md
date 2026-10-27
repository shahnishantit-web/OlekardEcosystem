# Quickstart Validation Guide: P23 — Payment History, Detail and Transaction Management

**Date**: 2026-10-26
**Branch**: `070-payment-history-detail`

---

## Prerequisites

1. Docker services running: `cd wallet-ecosystem && docker compose up -d`
2. Customer app seeded: `admin@olekard.local` / `Admin123!`; mobile dev user `+26771000001` / PIN `123456`
3. Agent dev user `+26771000002` / PIN `123456`
4. At least 3 completed payment transactions in the seeded database (E08/E09 seeders)
5. At least 1 payment with `refund_eligible = true` in seed data
6. Flutter SDK available; run from `apps/customer/` or `apps/agent/`

See:
- [data-model.md](./data-model.md) for entity field definitions
- [contracts/payment-history-notifier.md](./contracts/payment-history-notifier.md) for state expectations
- [contracts/payment-detail-notifier.md](./contracts/payment-detail-notifier.md)
- [contracts/receipt-access-notifier.md](./contracts/receipt-access-notifier.md)

---

## Validation Scenarios

### SC-01: History List Loads from API

**Goal**: First open of payment history renders items from P19 API.

```sh
# Run widget test
flutter test test/modules/transactions/presentation/payment_history_screen_test.dart \
  --name "SC-01"
```

**Setup**: Mock `paymentHistoryProvider` to return `PaymentHistoryLoaded` with 3 seeded items.

**Expected**: 
- Screen renders 3 list tiles
- Each tile shows `displayTitle`, formatted `amountMinor`, `direction` indicator
- No stale banner visible (`isStale: false`)
- Each tile has minimum 44dp touch target

---

### SC-02: Filter by Status

**Goal**: Applying a status filter reloads the list with matching items only.

```sh
flutter test test/modules/transactions/presentation/payment_history_screen_test.dart \
  --name "SC-02"
```

**Setup**: Mock returns 5 items for no-filter, 2 items for `status = COMPLETED`.

**Expected**:
- `PaymentHistoryFilterBar` renders status chip row
- Tapping "Completed" chip calls `applyFilter(PaymentHistoryFilter(status: PaymentStatus.completed))`
- List transitions to `PaymentHistoryLoading` then `PaymentHistoryLoaded(items.length == 2)`

---

### SC-03: Offline Stale Cache Served with Banner

**Goal**: When network is unavailable, stale-cache items are served with a visible banner.

```sh
flutter test test/modules/transactions/application/payment_history_notifier_test.dart \
  --name "SC-03"
```

**Setup**: 
1. Pre-populate `PaymentCacheStore` with 3 cached items
2. Mock `TransactionRepository.fetchHistory()` to throw `DioException` (connection refused)

**Expected**:
- `PaymentHistoryError(isOffline: true, staleItems: [3 items], staleAt: ...)` state
- Widget renders 3 tiles + "Last updated: [staleAt]" stale banner
- Banner has `Semantics(liveRegion: true)`

---

### SC-04: Detail Screen Shows 4 Status Rows

**Goal**: Payment detail renders capture, accounting, service, and refund status as distinct rows.

```sh
flutter test test/modules/transactions/presentation/payment_detail_screen_test.dart \
  --name "SC-04"
```

**Setup**: Mock `paymentDetailProvider` with a `PaymentDetailLoaded` containing all 4 status row values.

**Expected**:
- 4 `PaymentStatusRow` widgets rendered with correct labels and values
- Status rows are discrete `Semantics` nodes (screen reader can navigate each)
- Refund row hidden when `refundStatus == null`

---

### SC-05: Domain Deep Link — Available and Unavailable

**Goal**: Tappable deep-link tile renders for `AvailableLink`; greyed tile for `UnavailableLink`.

```sh
flutter test test/modules/transactions/presentation/payment_detail_screen_test.dart \
  --name "SC-05"
```

**Setup A**: `deepLink = AvailableLink(url: "/checkout/order/abc123", label: "View Order")`
**Setup B**: `deepLink = UnavailableLink(reason: "domain_retired")`

**Expected A**: `DomainDeepLinkTile` is tappable, calls `context.go("/checkout/order/abc123")`
**Expected B**: `DomainDeepLinkTile` is non-interactive, shows "Unavailable" label

---

### SC-06: Receipt Download Token Used Immediately

**Goal**: Tapping Download obtains a single-use token and opens the URL via url_launcher; token is not cached.

```sh
flutter test test/modules/transactions/application/receipt_access_notifier_test.dart \
  --name "SC-06"
```

**Setup**: Mock `ReceiptRepository.requestDownloadToken()` to return `ReceiptDownloadToken(token: "t1", downloadUrl: "https://...", expiresAt: ...)`.

**Expected**:
1. `requestDownload()` → state becomes `ReceiptDownloadReady(downloadUrl: "https://...", ...)`
2. Widget calls `url_launcher.launchUrl(Uri.parse(downloadUrl))`
3. Widget calls `notifier.onDownloadLaunched()`
4. State resets to `ReceiptReady(receipt)`
5. Verify `PaymentCacheStore` does NOT contain the `downloadUrl` string

---

### SC-07: Cross-Account Receipt Denied

**Goal**: Customer B cannot access Customer A's receipt — server returns 403, app shows Access Denied.

```sh
flutter test test/modules/transactions/application/receipt_access_notifier_test.dart \
  --name "SC-07"
```

**Setup**: Mock `ReceiptRepository.fetchReceipt(intentId)` to throw a 403 `DioException`.

**Expected**: 
- State becomes `ReceiptAccessDenied(reason: "not_owner")`
- Widget renders "Access Denied" message
- No receipt data is displayed

---

### SC-08: Refund Button Conditional on refund_eligible

**Goal**: Refund button visible when server says `refund_eligible = true`; absent when `false`.

```sh
flutter test test/modules/transactions/presentation/payment_detail_screen_test.dart \
  --name "SC-08"
```

**Setup A**: `item.refundEligible = true`
**Setup B**: `item.refundEligible = false`

**Expected A**: "Request Refund" button rendered with 44dp minimum target; `initiateRefund()` called on tap
**Expected B**: No refund button in widget tree

---

### SC-09: Support Ticket Pre-Filled

**Goal**: Opening support from a transaction pre-fills the subject and locks the payment reference.

```sh
flutter test test/modules/transactions/presentation/support_ticket_sheet_test.dart \
  --name "SC-09"
```

**Setup**: Item with `id = "intent-123"`, `displayTitle = "Bill Payment - ZESA"`.

**Expected**:
- `SupportTicketDraftSheet` opens with `paymentIntentId = "intent-123"` (non-editable, shown as reference)
- Subject field pre-filled with "Bill Payment - ZESA" (editable)
- "Attach file" picker filters to PDF/PNG/JPEG only

---

### SC-10: Cache Fully Cleared on Logout

**Goal**: Logout event triggers full eviction of all `payment_` keys from `flutter_secure_storage`.

```sh
flutter test test/modules/transactions/infrastructure/payment_cache_store_test.dart \
  --name "SC-10"
```

**Setup**:
1. Write `payment_history_user1`, `payment_detail_intent-abc`, `payment_detail_intent-def` to mock secure storage
2. Fire logout event (simulate E03 session event bus)

**Expected**:
- `PaymentCacheStore.clearAll()` called
- All 3 keys absent from secure storage after eviction
- No non-payment keys affected

---

## Timing Assertions (Docker-gated)

These run against a live Docker environment and require network timing:

| Assertion | Target | Command |
|-----------|--------|---------|
| History first-page load (p95) | ≤ 800 ms | `flutter drive --target=test_driver/payment_history_perf_test.dart` |
| Receipt token fetch (p95) | ≤ 600 ms | `flutter drive --target=test_driver/receipt_token_perf_test.dart` |
| Stale cache read latency | ≤ 50 ms | Unit test with `Stopwatch` |

---

## Accessibility Validation (Manual / Docker-gated)

| Check | Tool | Pass Criteria |
|-------|------|---------------|
| 2× font scale (list screen) | Flutter Accessibility Inspector | No text overflow; all tiles readable |
| 2× font scale (detail screen) | Flutter Accessibility Inspector | Status rows reflow; no fixed-height overflow |
| Screen reader navigation | TalkBack / VoiceOver | All interactive elements announced; status rows discrete |
| Touch target size | Flutter DevTools | Min 44 × 44 dp on all interactive elements |
| Stale banner live region | TalkBack | Banner announced when it appears |
