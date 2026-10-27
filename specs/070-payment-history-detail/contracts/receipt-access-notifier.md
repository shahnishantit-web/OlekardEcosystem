# Contract: ReceiptAccessNotifier

**Feature**: P23 — Payment History, Detail and Transaction Management
**Date**: 2026-10-26

---

## Provider Signature

```dart
// Keyed by payment intent ID
final receiptAccessProvider = AsyncNotifierProvider.family<
  ReceiptAccessNotifier,
  ReceiptAccessState,
  String  // intentId
>(() => ReceiptAccessNotifier());
```

---

## State Sealed Class

```dart
sealed class ReceiptAccessState {
  const ReceiptAccessState();
}

final class ReceiptAccessLoading extends ReceiptAccessState {
  const ReceiptAccessLoading();
}

final class ReceiptReady extends ReceiptAccessState {
  final PaymentReceipt receipt;
  const ReceiptReady({required this.receipt});
}

final class ReceiptDownloadRequested extends ReceiptAccessState {
  final PaymentReceipt receipt;
  const ReceiptDownloadRequested({required this.receipt});
}

// Token obtained; widget MUST call url_launcher immediately and transition away
final class ReceiptDownloadReady extends ReceiptAccessState {
  final PaymentReceipt receipt;
  final String downloadUrl;     // open via url_launcher; never store or log
  final DateTime expiresAt;
  const ReceiptDownloadReady({
    required this.receipt,
    required this.downloadUrl,
    required this.expiresAt,
  });
}

final class ReceiptDownloadError extends ReceiptAccessState {
  final PaymentReceipt receipt;
  final String message;
  const ReceiptDownloadError({
    required this.receipt,
    required this.message,
  });
}

final class ReceiptAccessDenied extends ReceiptAccessState {
  final String reason;    // e.g. "not_owner", "receipt_expired"
  const ReceiptAccessDenied({required this.reason});
}

final class ReceiptAccessError extends ReceiptAccessState {
  final String message;
  const ReceiptAccessError({required this.message});
}
```

---

## Notifier Methods

```dart
abstract class ReceiptAccessNotifier
    extends FamilyAsyncNotifier<ReceiptAccessState, String> {

  /// Load receipt for [intentId] (the family arg)
  @override
  Future<ReceiptAccessState> build(String intentId);

  /// Request a single-use download token; transitions to ReceiptDownloadRequested
  /// then ReceiptDownloadReady or ReceiptDownloadError
  Future<void> requestDownload();

  /// Called by the widget AFTER url_launcher.launch() to reset to ReceiptReady
  /// (clears the ephemeral token from in-memory state)
  void onDownloadLaunched();
}
```

---

## Security Constraints

1. `ReceiptDownloadReady.downloadUrl` must never be:
   - Written to `flutter_secure_storage`
   - Written to `shared_preferences`
   - Included in any analytics event payload
   - Included in any log line
2. `onDownloadLaunched()` must be called within the same widget build cycle as the url_launcher call — the `downloadUrl` field in state is ephemeral.
3. The `ReceiptScreen` must activate `FLAG_SECURE` (Android) / `SecureScreen` (iOS) via `flutter_windowmanager` before the receipt data is rendered.
4. Cross-account access (403 from server) must transition to `ReceiptAccessDenied` — never serve a cached receipt belonging to a different account.

---

## Acceptance Criteria

| Scenario | Expected State |
|----------|---------------|
| Receipt loaded successfully | `ReceiptReady(receipt)` |
| User taps Download | `ReceiptDownloadRequested` → `ReceiptDownloadReady` |
| Token fetch fails (network) | `ReceiptDownloadError(receipt, message)` |
| 403 from receipt endpoint | `ReceiptAccessDenied(reason: "not_owner")` |
| Receipt expired | `ReceiptAccessDenied(reason: "receipt_expired")` |
| `onDownloadLaunched()` called | State resets to `ReceiptReady(receipt)` |

---

## Widget Contract: ReceiptScreen

**Required widget behaviors**:
- Activates `FLAG_SECURE` / `SecureScreen` on `initState`; deactivates on `dispose`
- Renders `CircularProgressIndicator` during `ReceiptAccessLoading`
- `ReceiptReady`: shows receipt number, issue date, line items, total; Download button
- `ReceiptDownloadReady`: immediately calls `url_launcher.launchUrl(Uri.parse(downloadUrl))`; then calls `notifier.onDownloadLaunched()`; shows "Opening PDF…" indicator
- `ReceiptAccessDenied`: shows "Access Denied" error with reason text; no back-navigation override
- `providerReference` rendered as masked: asterisks + last 4 characters only
- Line items: `amountMinor` formatted via server-returned `currencyCode`; no local currency arithmetic
- Accessible: each line item is a `Semantics(label: "${item.label}: ${formattedAmount}")` node
- Minimum 44dp touch target on Download button

---

## Support-from-Transaction Contract

For US5 (P2), the widget contract is a form overlay/sheet, not a separate notifier.

```dart
class SupportTicketDraftSheet extends StatefulWidget {
  final String paymentIntentId;          // non-editable; shown as reference
  final String prefilledSubject;         // from PaymentHistoryItem.displayTitle
  // submits via E22 createSupportTicket; attachments via attachEvidence
}
```

**Attachment constraints** (enforced in the sheet widget, not a separate notifier):
- File picker filters: PDF, PNG, JPEG
- Client-side MIME check before upload; user-facing error if unsupported
- Server enforces independently
- Upload progress shown per attachment
