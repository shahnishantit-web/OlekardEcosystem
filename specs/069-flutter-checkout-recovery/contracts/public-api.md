# Public API Contract: packages/shared-payment

**Date**: 2026-10-26 | **Package**: `shared_payment`

This contract defines the stable public surface of the `packages/shared-payment` Flutter library. Internal implementation details under `src/` are not part of this contract and may change without notice.

---

## Public Exports (`lib/shared_payment.dart`)

```dart
// Entry points
export 'src/presentation/payment_checkout_sheet.dart'
    show PaymentCheckoutSheet, PaymentCheckoutMode, showPaymentCheckout;

// Result type
export 'src/domain/models/checkout_result.dart' show CheckoutResult;

// Input type
export 'src/application/payment_notifier.dart' show PaymentCheckoutRequest;

// Step-up abstraction (consuming apps must implement and provide this)
export 'src/application/step_up_service.dart'
    show StepUpService, stepUpServiceProvider;
```

**Everything else is private.** Domain models, API clients, notifiers, and repository providers are internal.

---

## Entry Points

### `showPaymentCheckout` (bottom sheet)

```dart
Future<CheckoutResult?> showPaymentCheckout({
  required BuildContext context,
  required String purposeType,
  required String purposeReference,
  required String currencyCode,
  required int estimatedAmountMinor,
  String? returnRoute,
})
```

Returns `null` if the user dismisses by swiping down. Returns `CheckoutResult` for all explicit outcomes (success, failure, cancel).

### `PaymentCheckoutSheet` (embeddable widget)

```dart
class PaymentCheckoutSheet extends ConsumerStatefulWidget {
  const PaymentCheckoutSheet({
    super.key,
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required int estimatedAmountMinor,
    PaymentCheckoutMode mode = PaymentCheckoutMode.sheet,
    String? returnRoute,
  });
}

enum PaymentCheckoutMode { sheet, page }
```

Use `mode: PaymentCheckoutMode.page` to embed as a full-screen route.

---

## CheckoutResult

```dart
class CheckoutResult {
  final bool succeeded;
  final String? paymentIntentId;
  final String? errorCode;
}
```

- `succeeded` is `true` only when payment reached `succeeded` terminal status.
- `paymentIntentId` is present whenever an intent was created (success, failure, or cancel after intent creation).
- `errorCode` is a domain code (e.g., `INSUFFICIENT_FUNDS`, `PROVIDER_UNAVAILABLE`), never a raw provider reference.

---

## StepUpService (required override)

Consuming apps must provide a concrete implementation via Riverpod override.

```dart
abstract interface class StepUpService {
  /// Acquire a step-up token for the given scope.
  /// Throws on failure (wrong PIN, biometric failure, etc.).
  Future<String> acquireToken({required String scope});
}

// Provider — must be overridden in ProviderScope:
final stepUpServiceProvider = Provider<StepUpService>((ref) {
  throw UnimplementedError('Override stepUpServiceProvider in ProviderScope');
});
```

**Example (customer app)**:
```dart
class CustomerStepUpService implements StepUpService {
  @override
  Future<String> acquireToken({required String scope}) async {
    // Show PinPadWidget, call /v1/auth/step-up/initiate + verify
    // Return step_up_access_token
  }
}

// In ProviderScope:
overrides: [stepUpServiceProvider.overrideWith((ref) => CustomerStepUpService(ref))]
```

---

## Consuming App Setup

### pubspec.yaml

```yaml
dependencies:
  shared_payment:
    path: ../../packages/shared-payment
```

### ProviderScope

```dart
ProviderScope(
  overrides: [
    stepUpServiceProvider.overrideWith((ref) => AppStepUpService(ref)),
  ],
  child: MyApp(),
)
```

### Recovery on cold start

The library handles cold-start recovery internally. On `initState`, `PaymentCheckoutSheet` reads the `payment_pending_intent_id` SharedPreferences key. If a pending intent exists, the sheet renders `RecoveringPending`/`RecoveringUnknown` states immediately rather than the options screen.

No additional setup is needed in the consuming app beyond mounting the widget.

---

## Constraints and Guarantees

| Guarantee | Details |
|-----------|---------|
| Amount not passed to library | `estimatedAmountMinor` is a filtering hint only; displayed amount comes from `PaymentIntent.amountMinor` (server) |
| Redirect URL params never trusted | App resume triggers polling; URL params are discarded |
| Provider locked after attempt | Once `ConfirmingPayment` entered and attempt submitted, provider cannot change |
| PIN never persisted | Step-up token is used in-memory and discarded |
| Polling timeout ≠ expiry | Library stays in PollingStatus on timeout; PaymentExpired is only set on server-confirmed `expired` status |
| Recovery states are distinct | Unknown, Pending, and Fulfilling show different messages; user is never shown false success |
