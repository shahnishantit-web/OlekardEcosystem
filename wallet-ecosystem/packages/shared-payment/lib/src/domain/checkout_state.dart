import 'models/payment_option.dart';
import 'models/payment_intent.dart';
import 'models/payment_status.dart';

sealed class PaymentCheckoutState {}

class LoadingOptions extends PaymentCheckoutState {}

class EmptyOptions extends PaymentCheckoutState {}

class OptionsLoaded extends PaymentCheckoutState {
  final List<PaymentOption> options;
  final PaymentIntent? intent;
  final PaymentOption? selectedOption;

  OptionsLoaded({required this.options, this.intent, this.selectedOption});
}

class ConfirmingPayment extends PaymentCheckoutState {
  final PaymentOption selectedOption;
  final PaymentIntent intent;

  ConfirmingPayment({required this.selectedOption, required this.intent});
}

// Covers redirect, sdk_launch, and app_switch flows.
// allowedReturnSchemes is empty for sdk_launch/app_switch (always poll on resume).
class AwaitingRedirect extends PaymentCheckoutState {
  final String redirectUrl;
  final String paymentIntentId;
  final List<String> allowedReturnSchemes;

  AwaitingRedirect({
    required this.redirectUrl,
    required this.paymentIntentId,
    this.allowedReturnSchemes = const [],
  });
}

class PollingStatus extends PaymentCheckoutState {
  final String paymentIntentId;
  final PaymentIntentStatus lastKnownStatus;

  PollingStatus({required this.paymentIntentId, required this.lastKnownStatus});
}

class InsufficientFunds extends PaymentCheckoutState {
  final int requiredMinor;
  final int availableMinor;

  InsufficientFunds({required this.requiredMinor, required this.availableMinor});
}

class StepUpRequired extends PaymentCheckoutState {
  final String paymentIntentId;

  StepUpRequired({required this.paymentIntentId});
}

class RecoveringUnknown extends PaymentCheckoutState {
  final String paymentIntentId;
  final String supportReference;

  RecoveringUnknown({required this.paymentIntentId, required this.supportReference});
}

class RecoveringPending extends PaymentCheckoutState {
  final String paymentIntentId;

  RecoveringPending({required this.paymentIntentId});
}

class RecoveringFulfilling extends PaymentCheckoutState {
  final String paymentIntentId;

  RecoveringFulfilling({required this.paymentIntentId});
}

class PaymentSucceeded extends PaymentCheckoutState {
  final String paymentIntentId;
  final int amountMinor;
  final String currencyCode;

  PaymentSucceeded({
    required this.paymentIntentId,
    required this.amountMinor,
    required this.currencyCode,
  });
}

class PaymentFailed extends PaymentCheckoutState {
  final String errorCode;
  final String message;
  final bool canRetry;

  PaymentFailed({
    required this.errorCode,
    required this.message,
    this.canRetry = false,
  });
}

class PaymentCancelled extends PaymentCheckoutState {}

class PaymentExpired extends PaymentCheckoutState {}
