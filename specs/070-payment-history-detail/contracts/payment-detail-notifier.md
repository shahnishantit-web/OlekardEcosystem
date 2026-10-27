# Contract: PaymentDetailNotifier

**Feature**: P23 — Payment History, Detail and Transaction Management
**Date**: 2026-10-26

---

## Provider Signature

```dart
// Keyed by payment intent ID
final paymentDetailProvider = AsyncNotifierProvider.family<
  PaymentDetailNotifier,
  PaymentDetailState,
  String  // intentId
>(() => PaymentDetailNotifier());
```

---

## State Sealed Class

```dart
sealed class PaymentDetailState {
  const PaymentDetailState();
}

final class PaymentDetailLoading extends PaymentDetailState {
  const PaymentDetailLoading();
}

final class PaymentDetailLoaded extends PaymentDetailState {
  final PaymentHistoryItem item;
  final PaymentStatusRows statusRows;
  final DomainDeepLink? deepLink;   // null if not present in item
  const PaymentDetailLoaded({
    required this.item,
    required this.statusRows,
    this.deepLink,
  });
}

final class PaymentDetailRefundConfirm extends PaymentDetailState {
  final PaymentHistoryItem item;    // item.refundEligible must == true
  const PaymentDetailRefundConfirm({required this.item});
}

final class PaymentDetailRefundSubmitting extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailRefundSubmitting({required this.item});
}

final class PaymentDetailRefundSubmitted extends PaymentDetailState {
  final PaymentHistoryItem item;
  final String refundRequestId;     // from E62 response
  const PaymentDetailRefundSubmitted({
    required this.item,
    required this.refundRequestId,
  });
}

final class PaymentDetailSupportDraft extends PaymentDetailState {
  final PaymentHistoryItem item;
  const PaymentDetailSupportDraft({required this.item});
}

final class PaymentDetailError extends PaymentDetailState {
  final String message;
  const PaymentDetailError({required this.message});
}
```

---

## PaymentStatusRows Value Object

```dart
// Four discrete status rows shown in detail screen
final class PaymentStatusRows {
  final String captureStatus;          // e.g. "Captured", "Capture Pending"
  final String accountingStatus;       // e.g. "Posted", "Pending"
  final String serviceStatus;          // e.g. "Fulfilled", "Pending Fulfilment"
  final String? refundStatus;          // null if never refunded
  const PaymentStatusRows({
    required this.captureStatus,
    required this.accountingStatus,
    required this.serviceStatus,
    this.refundStatus,
  });
}
```

---

## Notifier Methods

```dart
abstract class PaymentDetailNotifier
    extends FamilyAsyncNotifier<PaymentDetailState, String> {

  /// Load detail for [intentId] (the family arg)
  @override
  Future<PaymentDetailState> build(String intentId);

  /// Transition to RefundConfirm — only callable when item.refundEligible == true
  Future<void> initiateRefund();

  /// Submit refund to E62; transitions through Submitting → Submitted | Error
  Future<void> submitRefund({required RefundReason reason, int? amountMinor});

  /// Transition to SupportDraft
  Future<void> openSupportDraft();

  /// Reset to Loaded state (cancel refund or support draft)
  Future<void> cancel();
}
```

---

## Acceptance Criteria

| Scenario | Expected State |
|----------|---------------|
| Detail loads successfully | `PaymentDetailLoaded(item, statusRows, deepLink?)` |
| `refundEligible == true` + user taps Refund | `PaymentDetailRefundConfirm` |
| `refundEligible == false` | Refund button absent (widget hides it) |
| Refund submitted | `PaymentDetailRefundSubmitting` → `PaymentDetailRefundSubmitted` |
| Support tapped | `PaymentDetailSupportDraft` |
| `deepLink` is `AvailableLink` | Tappable tile rendered |
| `deepLink` is `UnavailableLink` | Greyed tile with "unavailable" label |
| 403 from server | `PaymentDetailError("Access denied")` |
| Network error | `PaymentDetailError(message)` |

---

## Widget Contract: PaymentDetailScreen

**Required widget behaviors**:
- Renders 4 `PaymentStatusRow` tiles: Capture, Accounting, Service, Refund (hide Refund row if `refundStatus == null`)
- Refund button visible only when `item.refundEligible == true`; button has minimum 44dp touch target
- Deep link tile tappable when `deepLink is AvailableLink`; greyed and non-interactive when `deepLink is UnavailableLink`
- `providerReference` displayed as masked string (last 4 chars + asterisks) — never logged
- Receipt button present when `item.receiptAvailable == true`; navigates to `PaymentRoutes.receiptPath`
- Accessible: screen reader announces status row labels + values as discrete semantics nodes
- Font scale 2×: all text reflows without overflow (no fixed-height containers on text)
