import 'dart:async';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';
import 'models/payment_intent.dart';
import 'models/payment_option.dart';
import 'models/payment_status.dart';
import '../data/payment_repository.dart';

// ─── State types ──────────────────────────────────────────────────────────────

sealed class PaymentCheckoutState {}

class LoadingOptions extends PaymentCheckoutState {}

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

class AwaitingRedirect extends PaymentCheckoutState {
  final String redirectUrl;
  final String paymentIntentId;

  AwaitingRedirect({required this.redirectUrl, required this.paymentIntentId});
}

class PollingStatus extends PaymentCheckoutState {
  final String paymentIntentId;
  final PaymentIntentStatus lastKnownStatus;

  PollingStatus({required this.paymentIntentId, required this.lastKnownStatus});
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

  PaymentFailed(
      {required this.errorCode, required this.message, this.canRetry = false});
}

class PaymentCancelled extends PaymentCheckoutState {}

class PaymentExpired extends PaymentCheckoutState {}

class InsufficientFunds extends PaymentCheckoutState {
  final int requiredMinor;
  final int availableMinor;

  InsufficientFunds(
      {required this.requiredMinor, required this.availableMinor});
}

class StepUpRequired extends PaymentCheckoutState {
  final String paymentIntentId;

  StepUpRequired({required this.paymentIntentId});
}

// ─── Return value ────────────────────────────────────────────────────────────

class PaymentCheckoutResult {
  final bool succeeded;
  final String? paymentIntentId;
  final String? errorCode;

  const PaymentCheckoutResult({
    required this.succeeded,
    this.paymentIntentId,
    this.errorCode,
  });
}

// ─── SharedPreferences key for deep-link restoration ─────────────────────────

const _kPendingPaymentIntentId = 'payment_pending_intent_id';

// ─── Notifier ─────────────────────────────────────────────────────────────────

/// T119: Riverpod notifier driving PaymentCheckoutSheet state.
typedef PaymentCheckoutRequest = ({
  String purposeType,
  String purposeReference,
  String currencyCode,
  int estimatedAmountMinor
});

class PaymentNotifier extends AsyncNotifier<PaymentCheckoutState> {
  OptionsLoaded? _selection;
  PaymentIntent? _intent;
  final String _intentKey = const Uuid().v4();
  String _attemptKey = const Uuid().v4();
  int _pollGeneration = 0;
  bool _disposed = false;
  final PaymentCheckoutRequest request;
  PaymentNotifier(this.request);
  String get _purposeType => request.purposeType;
  String get _purposeReference => request.purposeReference;
  String get _currencyCode => request.currencyCode;
  int get _estimatedAmountMinor => request.estimatedAmountMinor;

  @override
  Future<PaymentCheckoutState> build() async {
    _disposed = false;
    ref.onDispose(() {
      _disposed = true;
      _pollGeneration++;
    });
    final options = await ref.read(paymentRepositoryProvider).getOptions(
          purposeType: _purposeType,
          purposeReference: _purposeReference,
          currencyCode: _currencyCode,
          estimatedAmountMinor: _estimatedAmountMinor,
        );
    return OptionsLoaded(options: options);
  }

  Future<void> selectOption(PaymentOption option) async {
    final current = state.value;
    if (current is OptionsLoaded) {
      state = AsyncData(OptionsLoaded(
        options: current.options,
        intent: current.intent,
        selectedOption: option,
      ));
    }
  }

  /// T119: Confirm payment for the selected option.
  /// Idempotency key is generated per intent creation attempt.
  Future<void> confirmPayment({String? stepUpToken}) async {
    final value = state.value;
    final current = value is StepUpRequired ? _selection : value;
    if (current is! OptionsLoaded || current.selectedOption == null) return;
    _selection = current;

    final option = current.selectedOption!;

    state = const AsyncLoading();

    try {
      // Step 1: create intent (server resolves the authoritative amount)
      final intent = _intent ??
          await ref.read(paymentRepositoryProvider).createIntent(
                purposeType: _purposeType,
                purposeReference: _purposeReference,
                currencyCode: _currencyCode,
                idempotencyKey: _intentKey,
              );
      _intent = intent;
      if (_disposed) return;

      state =
          AsyncData(ConfirmingPayment(selectedOption: option, intent: intent));

      // Step 2: initiate attempt
      final attemptResult =
          await ref.read(paymentRepositoryProvider).initiateAttempt(
                intentId: intent.id,
                providerCode: option.providerCode,
                idempotencyKey: _attemptKey,
                stepUpToken: stepUpToken,
              );

      final nextActionType = attemptResult['next_action_type'] as String?;
      if (_disposed) return;
      final nextActionData =
          attemptResult['next_action_data'] as Map<String, dynamic>?;

      if (nextActionType == 'redirect' && nextActionData != null) {
        final redirectUrl = nextActionData['redirect_url'] as String? ?? '';

        // Persist intent ID to SharedPreferences for deep-link restoration
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(_kPendingPaymentIntentId, intent.id);

        state = AsyncData(AwaitingRedirect(
          redirectUrl: redirectUrl,
          paymentIntentId: intent.id,
        ));
      } else {
        // Synchronous result (wallet) — check final status
        await pollStatus(intent.id, intent: intent);
      }
    } on Object catch (e) {
      if (_disposed) return;
      final errorCode = _extractErrorCode(e);
      if (errorCode == 'STEP_UP_REQUIRED') {
        _attemptKey = const Uuid().v4();
        final intentId = _intent?.id ?? '';
        state = AsyncData(StepUpRequired(paymentIntentId: intentId));
      } else if (errorCode == 'INSUFFICIENT_FUNDS') {
        state = AsyncData(InsufficientFunds(
          requiredMinor: _estimatedAmountMinor,
          availableMinor: 0,
        ));
      } else {
        state = AsyncData(PaymentFailed(
          errorCode: errorCode,
          message: e.toString(),
          canRetry: true,
        ));
      }
    }
  }

  /// T119: Poll for authoritative status after redirect return.
  /// Intervals: 3s × 5 polls, then 10s with cap at 30s.
  /// NEVER treats redirect URL params as payment proof.
  Future<void> pollStatus(String paymentIntentId,
      {PaymentIntent? intent}) async {
    final generation = ++_pollGeneration;
    state = AsyncData(PollingStatus(
      paymentIntentId: paymentIntentId,
      lastKnownStatus: PaymentIntentStatus.processing,
    ));

    const maxPollCount = 30;
    const shortInterval = Duration(seconds: 3);
    const longInterval = Duration(seconds: 10);
    const shortPollLimit = 5;

    for (var i = 0; i < maxPollCount; i++) {
      await Future<void>.delayed(
          i < shortPollLimit ? shortInterval : longInterval);
      if (_disposed || generation != _pollGeneration) return;

      try {
        final status = await ref
            .read(paymentRepositoryProvider)
            .getStatus(paymentIntentId);
        if (_disposed || generation != _pollGeneration) return;

        if (status.isTerminal) {
          // Clear pending intent from SharedPreferences
          final prefs = await SharedPreferences.getInstance();
          await prefs.remove(_kPendingPaymentIntentId);

          state = AsyncData(_terminalState(
            status: status,
            paymentIntentId: paymentIntentId,
            amountMinor: intent?.amountMinor ?? 0,
            currencyCode: intent?.currencyCode ?? _currencyCode,
          ));
          return;
        }

        state = AsyncData(PollingStatus(
          paymentIntentId: paymentIntentId,
          lastKnownStatus: status,
        ));
      } on Object catch (_) {
        // Network error during poll — continue polling
      }
    }

    // A polling timeout is not evidence that a payment expired.
    state = AsyncData(PollingStatus(
        paymentIntentId: paymentIntentId,
        lastKnownStatus: PaymentIntentStatus.processing));
  }

  /// Restore state from a deep link / cold-start return.
  Future<void> restoreFromDeepLink() async {
    final prefs = await SharedPreferences.getInstance();
    final intentId = prefs.getString(_kPendingPaymentIntentId);
    if (intentId != null) {
      await pollStatus(intentId);
    }
  }

  Future<void> cancelPayment() async {
    _pollGeneration++;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kPendingPaymentIntentId);
    state = AsyncData(PaymentCancelled());
  }

  PaymentCheckoutState _terminalState({
    required PaymentIntentStatus status,
    required String paymentIntentId,
    required int amountMinor,
    required String currencyCode,
  }) =>
      switch (status) {
        PaymentIntentStatus.succeeded => PaymentSucceeded(
            paymentIntentId: paymentIntentId,
            amountMinor: amountMinor,
            currencyCode: currencyCode,
          ),
        PaymentIntentStatus.expired => PaymentExpired(),
        PaymentIntentStatus.cancelled => PaymentCancelled(),
        _ => PaymentFailed(
            errorCode: status.name.toUpperCase(),
            message: 'Payment did not succeed.',
            canRetry: status == PaymentIntentStatus.failed,
          ),
      };

  String _extractErrorCode(Object e) {
    final msg = e.toString();
    if (msg.contains('STEP_UP_REQUIRED')) return 'STEP_UP_REQUIRED';
    if (msg.contains('INSUFFICIENT_FUNDS')) return 'INSUFFICIENT_FUNDS';
    return 'UNKNOWN_ERROR';
  }
}

// Provider factory
final paymentNotifierProvider = AsyncNotifierProvider.autoDispose
    .family<PaymentNotifier, PaymentCheckoutState, PaymentCheckoutRequest>(
  PaymentNotifier.new,
);
