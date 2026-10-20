# Flutter Widget Contract: PaymentCheckoutSheet (E48)

**File**: `apps/customer/lib/modules/payment/presentation/payment_checkout_sheet.dart`
(Mirrored to `apps/agent/lib/modules/payment/` for the Agent app)

---

## Widget Signature

```dart
/// Shared payment checkout bottom sheet / full-screen flow.
///
/// Usage:
/// ```dart
/// await showPaymentCheckout(
///   context: context,
///   purposeType: 'funeral_contribution',
///   purposeReference: '01929f3b-...',
///   currency: 'BWP',
///   estimatedAmountMinor: 10000,  // advisory only — server re-validates
/// );
/// ```
class PaymentCheckoutSheet extends ConsumerStatefulWidget {
  final String purposeType;
  final String purposeReference;
  final String currencyCode;
  final int estimatedAmountMinor;  // displayed before options load; replaced by server value
  final PaymentCheckoutMode mode;  // sheet (default) | page

  const PaymentCheckoutSheet({
    required this.purposeType,
    required this.purposeReference,
    required this.currencyCode,
    required this.estimatedAmountMinor,
    this.mode = PaymentCheckoutMode.sheet,
  });
}

enum PaymentCheckoutMode { sheet, page }

/// Convenience function to show the checkout as a bottom sheet.
Future<PaymentCheckoutResult?> showPaymentCheckout({
  required BuildContext context,
  required String purposeType,
  required String purposeReference,
  required String currencyCode,
  required int estimatedAmountMinor,
})
```

---

## State Machine (PaymentCheckoutState)

```dart
sealed class PaymentCheckoutState {}

class LoadingOptions extends PaymentCheckoutState {}

class OptionsLoaded extends PaymentCheckoutState {
  final PaymentContext context;
  final List<PaymentOption> options;
  final PaymentOption? selectedOption;
}

class ConfirmingPayment extends PaymentCheckoutState {
  final PaymentOption selectedOption;
}

class AwaitingRedirect extends PaymentCheckoutState {
  final String redirectUrl;
  final String paymentIntentId;
}

class PollingStatus extends PaymentCheckoutState {
  final String paymentIntentId;
  final PaymentIntentStatus lastKnownStatus;
}

class PaymentSucceeded extends PaymentCheckoutState {
  final String paymentIntentId;
  final int amountMinor;
  final String currencyCode;
}

class PaymentFailed extends PaymentCheckoutState {
  final String errorCode;
  final String message;
  final bool canRetry;
}

class PaymentCancelled extends PaymentCheckoutState {}
class PaymentExpired extends PaymentCheckoutState {}
class InsufficientFunds extends PaymentCheckoutState {
  final int requiredMinor;
  final int availableMinor;
}
class StepUpRequired extends PaymentCheckoutState {
  final String paymentIntentId;
}
```

---

## Key Behaviour Rules

1. **Provider list is always from API** — never hard-coded in widget code.
2. **After redirect return** — widget MUST call `GET /v1/payment/intents/:id/status`. The redirect URL parameters are NEVER parsed for payment confirmation.
3. **Wallet balance display** — shown from API response only (`walletBalanceMinor` in `PaymentOption`). Widget never calls Wallet module directly.
4. **Insufficient funds** — wallet option shows disabled state + "Insufficient Balance" badge when `!option.hasSufficientBalance`.
5. **Deep link restoration** — `payment_intent_id` is persisted to `SharedPreferences` before opening redirect. On app return (cold start or resume), checkout widget reads this key and transitions to `PollingStatus`.
6. **Polling interval** — 3 seconds for first 5 polls, then 10 seconds, with exponential backoff cap at 30 seconds. Stop polling when status `isTerminal`.
7. **Step-up** — if attempt returns `STEP_UP_REQUIRED`, widget transitions to `StepUpRequired` state and shows the existing PIN/biometric challenge flow from E31 auth module.

---

## Return Value

```dart
class PaymentCheckoutResult {
  final bool succeeded;
  final String? paymentIntentId;
  final String? errorCode;
}
```

Calling code checks `result.succeeded` before fulfilling business-side action (e.g., navigating to confirmation screen). The actual business fulfilment (ticket issuance, etc.) is driven by the server-side `payment.confirmed` event, NOT by the client-side result.

---

## Riverpod Provider

```dart
// domain/payment_notifier.dart

@riverpod
class PaymentNotifier extends _$PaymentNotifier {
  @override
  FutureOr<PaymentCheckoutState> build({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required int estimatedAmountMinor,
  }) async {
    final options = await ref.read(paymentRepositoryProvider)
        .getOptions(purposeType, purposeReference, currencyCode, estimatedAmountMinor);
    return OptionsLoaded(context: options.context, options: options.options);
  }

  Future<void> selectOption(PaymentOption option) { ... }
  Future<void> confirmPayment({String? stepUpToken}) { ... }
  Future<void> pollStatus(String paymentIntentId) { ... }
  Future<void> cancelPayment() { ... }
}
```

---

## Test Contract

Widget tests MUST cover all `PaymentCheckoutState` transitions using mocked `PaymentRepository`. Required test scenarios:

1. Options load successfully — both wallet and mock bank shown
2. Wallet option: insufficient funds → `InsufficientFunds` state
3. Wallet option: payment succeeds → `PaymentSucceeded` state
4. Wallet option: step-up required → `StepUpRequired` state
5. Bank option: redirect opened → `AwaitingRedirect` state
6. Bank option: return from redirect → poll → `PaymentSucceeded` state
7. Bank option: return from redirect → poll → `PaymentFailed` state
8. Network error during options load → error state with retry
9. Intent expired during polling → `PaymentExpired` state
10. Cancel button during `OptionsLoaded` → `PaymentCancelled` state
