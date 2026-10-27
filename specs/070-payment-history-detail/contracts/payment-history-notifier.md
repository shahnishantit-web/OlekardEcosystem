# Contract: PaymentHistoryNotifier

**Feature**: P23 — Payment History, Detail and Transaction Management
**Date**: 2026-10-26

---

## Provider Signature

```dart
// Provider family keyed by userId to scope cache and state
final paymentHistoryProvider = AsyncNotifierProvider.family<
  PaymentHistoryNotifier,
  PaymentHistoryState,
  String  // userId
>(() => PaymentHistoryNotifier());
```

---

## State Sealed Class

```dart
sealed class PaymentHistoryState {
  const PaymentHistoryState();
}

final class PaymentHistoryLoading extends PaymentHistoryState {
  const PaymentHistoryLoading();
}

final class PaymentHistoryLoaded extends PaymentHistoryState {
  final List<PaymentHistoryItem> items;
  final PaymentHistoryFilter filter;
  final PaymentHistoryPagination pagination;
  final bool isStale;           // true when served from encrypted cache
  final DateTime? cachedAt;     // non-null when isStale == true
  const PaymentHistoryLoaded({
    required this.items,
    required this.filter,
    required this.pagination,
    this.isStale = false,
    this.cachedAt,
  });
}

final class PaymentHistoryEmpty extends PaymentHistoryState {
  final PaymentHistoryFilter filter;
  const PaymentHistoryEmpty({required this.filter});
}

final class PaymentHistoryRefreshing extends PaymentHistoryState {
  final List<PaymentHistoryItem> currentItems;
  final PaymentHistoryFilter filter;
  const PaymentHistoryRefreshing({
    required this.currentItems,
    required this.filter,
  });
}

final class PaymentHistoryError extends PaymentHistoryState {
  final String message;
  final bool isOffline;
  final List<PaymentHistoryItem>? staleItems;   // non-null if stale cache available
  final DateTime? staleAt;
  const PaymentHistoryError({
    required this.message,
    required this.isOffline,
    this.staleItems,
    this.staleAt,
  });
}
```

---

## Notifier Methods

```dart
abstract class PaymentHistoryNotifier
    extends FamilyAsyncNotifier<PaymentHistoryState, String> {

  /// Initial load on first build with default filter (no filters)
  @override
  Future<PaymentHistoryState> build(String userId);

  /// Apply a new filter; resets to page 1 and reloads
  Future<void> applyFilter(PaymentHistoryFilter filter);

  /// Append the next page of results to [items]
  Future<void> loadNextPage();

  /// Pull-to-refresh: re-fetch current filter from page 1
  Future<void> refresh();
}
```

---

## Acceptance Criteria

| Scenario | Expected State |
|----------|---------------|
| API returns 3 items | `PaymentHistoryLoaded(items.length == 3, isStale: false)` |
| API returns 0 items | `PaymentHistoryEmpty(filter)` |
| Network unavailable, cache present | `PaymentHistoryError(isOffline: true, staleItems: [...])` |
| Network unavailable, no cache | `PaymentHistoryError(isOffline: true, staleItems: null)` |
| Filter changed | State transitions to `PaymentHistoryLoading` then reloads |
| Next page loaded | `items` list extended; `pagination.currentPage` incremented |
| On last page | `pagination.hasMore == false`; `loadNextPage()` is a no-op |

---

## Widget Contract: PaymentHistoryScreen

```dart
// Route path exported as constant
abstract final class PaymentRoutes {
  static const String historyPath = '/payments/history';
  static const String detailPath  = '/payments/history/:intentId';
  static const String receiptPath = '/payments/history/:intentId/receipt';
}
```

**Required widget behaviors**:
- Renders `CircularProgressIndicator` (semantics label: "Loading payment history") during `PaymentHistoryLoading`
- Renders stale-cache banner with `cachedAt` timestamp during `PaymentHistoryLoaded(isStale: true)`
- Renders `PaymentHistoryFilterBar` widget (see below)
- Each list tile minimum 44 × 44 dp touch target
- Accessible: each tile exposes `Semantics(label: "${item.displayTitle}, ${item.amountMinor} ${item.currencyCode}, ${item.status}")` 
- Supports `Semantics(liveRegion: true)` on the stale banner

**PaymentHistoryFilterBar contract**:
```dart
// Stateless widget; parent reads filter state from provider
class PaymentHistoryFilterBar extends StatelessWidget {
  final PaymentHistoryFilter currentFilter;
  final ValueChanged<PaymentHistoryFilter> onFilterChanged;
}
```
