import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../domain/checkout_state.dart';
import '../domain/models/next_action.dart';
import '../domain/models/payment_intent.dart';
import '../domain/models/payment_option.dart';
import '../domain/models/payment_status.dart';
import '../domain/models/recovery_record.dart';
import '../data/payment_repository.dart';

typedef PaymentCheckoutRequest = ({
  String purposeType,
  String purposeReference,
  String currencyCode,
  int estimatedAmountMinor,
  String? returnRoute,
});

class PaymentNotifier extends AsyncNotifier<PaymentCheckoutState> {
  final PaymentCheckoutRequest _arg;
  PaymentNotifier(this._arg);

  // Idempotency keys — _intentKey reused on retry; _attemptKey rotated only on STEP_UP_REQUIRED.
  final String _intentKey = const Uuid().v4();
  String _attemptKey = const Uuid().v4();

  int _pollGeneration = 0;
  bool _disposed = false;
  bool _attemptSubmitted = false;

  PaymentIntent? _intent;
  OptionsLoaded? _selection;

  @override
  Future<PaymentCheckoutState> build() async {
    _disposed = false;
    ref.onDispose(() {
      _disposed = true;
      _pollGeneration++;
    });

    // US4: check for a persisted in-flight intent before fetching options.
    final record = await RecoveryRecord.read();
    if (record != null) {
      _logTransition(null, 'RecoveringPending', intentId: record.intentId);
      unawaited(_startRecovery(record.intentId));
      return RecoveringPending(paymentIntentId: record.intentId);
    }

    final options = await ref.read(paymentRepositoryProvider).getOptions(
          purposeType:          _arg.purposeType,
          purposeReference:     _arg.purposeReference,
          currencyCode:         _arg.currencyCode,
          estimatedAmountMinor: _arg.estimatedAmountMinor,
        );

    if (options.isEmpty) {
      _logTransition(null, 'EmptyOptions');
      return EmptyOptions();
    }
    _logTransition(null, 'OptionsLoaded');
    return OptionsLoaded(options: options);
  }

  Future<void> selectOption(PaymentOption option) async {
    // FR-011: provider replacement blocked once attempt submitted.
    if (_attemptSubmitted) return;
    final current = state.value;
    if (current is OptionsLoaded) {
      state = AsyncData(OptionsLoaded(
        options:        current.options,
        intent:         current.intent,
        selectedOption: option,
      ));
    }
  }

  Future<void> confirmPayment({String? stepUpToken}) async {
    final value = state.value;
    final current = value is StepUpRequired ? _selection : value;
    if (current is! OptionsLoaded || current.selectedOption == null) return;
    _selection = current;

    final option = current.selectedOption!;
    state = const AsyncLoading();

    try {
      // Step 1: create intent (server resolves amount; amount_minor NOT sent).
      final intent = _intent ??
          await ref.read(paymentRepositoryProvider).createIntent(
                purposeType:      _arg.purposeType,
                purposeReference: _arg.purposeReference,
                currencyCode:     _arg.currencyCode,
                idempotencyKey:   _intentKey,
              );
      _intent = intent;
      _logTransition(null, 'ConfirmingPayment',
          intentId: intent.id,
          extra: 'intent-created providerCode=${option.providerCode}');
      if (_disposed) return;

      state = AsyncData(ConfirmingPayment(selectedOption: option, intent: intent));

      // Step 2: initiate attempt.
      final attemptResult =
          await ref.read(paymentRepositoryProvider).initiateAttempt(
                intentId:       intent.id,
                providerCode:   option.providerCode,
                idempotencyKey: _attemptKey,
                stepUpToken:    stepUpToken,
              );
      _attemptSubmitted = true;
      _logTransition('ConfirmingPayment', 'attempt-submitted',
          intentId: intent.id,
          extra: 'attempt-submitted providerCode=${option.providerCode}');
      if (_disposed) return;

      final nextAction = NextAction.fromJson(attemptResult);

      switch (nextAction) {
        case WalletPinAction():
          // Synchronous wallet payment — poll for confirmation.
          await pollStatus(intent.id, intent: intent);

        case RedirectAction(
            redirectUrl: final url,
            allowedReturnSchemes: final schemes,
          ):
          await RecoveryRecord.write(intent.id);
          final newState = AwaitingRedirect(
            redirectUrl:          url,
            paymentIntentId:      intent.id,
            allowedReturnSchemes: schemes,
          );
          _logTransition('ConfirmingPayment', 'AwaitingRedirect', intentId: intent.id);
          state = AsyncData(newState);

        case SdkLaunchAction(sdkId: final id):
          await RecoveryRecord.write(intent.id);
          // SDK launch flows always poll on foreground; no scheme allowlist needed.
          _logTransition('ConfirmingPayment', 'AwaitingRedirect(sdk)', intentId: intent.id);
          state = AsyncData(AwaitingRedirect(
            redirectUrl:     id,
            paymentIntentId: intent.id,
          ));

        case AppSwitchAction(deepLinkUrl: final url):
          await RecoveryRecord.write(intent.id);
          // App-switch flows always poll on foreground.
          _logTransition('ConfirmingPayment', 'AwaitingRedirect(appSwitch)', intentId: intent.id);
          state = AsyncData(AwaitingRedirect(
            redirectUrl:     url,
            paymentIntentId: intent.id,
          ));

        case ProviderUnavailableAction(reason: final reason):
          _logTransition('ConfirmingPayment', 'PaymentFailed(providerUnavailable)',
              intentId: intent.id);
          state = AsyncData(PaymentFailed(
            errorCode: 'PROVIDER_UNAVAILABLE',
            message:   reason,
            canRetry:  false,
          ));
      }
    } on Object catch (e) {
      if (_disposed) return;
      final errorCode = _extractErrorCode(e);
      if (errorCode == 'STEP_UP_REQUIRED') {
        // Rotate attempt key for the new step-up attempt.
        _attemptKey = const Uuid().v4();
        final intentId = _intent?.id ?? '';
        _logTransition(null, 'StepUpRequired', intentId: intentId);
        state = AsyncData(StepUpRequired(paymentIntentId: intentId));
      } else if (errorCode == 'INSUFFICIENT_FUNDS') {
        _logTransition(null, 'InsufficientFunds');
        state = AsyncData(InsufficientFunds(
          requiredMinor:  _arg.estimatedAmountMinor,
          availableMinor: 0,
        ));
      } else {
        _logTransition(null, 'PaymentFailed');
        state = AsyncData(PaymentFailed(
          errorCode: errorCode,
          message:   e.toString(),
          canRetry:  true,
        ));
      }
    }
  }

  /// Poll for authoritative status. Intervals: 3s × 5 then 10s up to 30 polls.
  /// Timeout stays in PollingStatus — NOT PaymentExpired (timeout ≠ expiry).
  Future<void> pollStatus(
    String paymentIntentId, {
    PaymentIntent? intent,
    bool isRecovery = false,
  }) async {
    final generation = ++_pollGeneration;
    final fromState = isRecovery ? 'recovery' : 'normal';
    _logTransition(fromState, 'PollingStatus', intentId: paymentIntentId);

    state = AsyncData(PollingStatus(
      paymentIntentId: paymentIntentId,
      lastKnownStatus: PaymentIntentStatus.processing,
    ));

    const maxPollCount   = 30;
    const shortInterval  = Duration(seconds: 3);
    const longInterval   = Duration(seconds: 10);
    const shortPollLimit = 5;

    for (var i = 0; i < maxPollCount; i++) {
      await Future<void>.delayed(
          i < shortPollLimit ? shortInterval : longInterval);
      if (_disposed || generation != _pollGeneration) return;

      try {
        final status =
            await ref.read(paymentRepositoryProvider).getStatus(paymentIntentId);
        if (_disposed || generation != _pollGeneration) return;

        if (status.isTerminal) {
          await RecoveryRecord.clear();
          final ts = _terminalState(
            status:          status,
            paymentIntentId: paymentIntentId,
            amountMinor:     intent?.amountMinor ?? 0,
            currencyCode:    intent?.currencyCode ?? _arg.currencyCode,
          );
          _logTransition('PollingStatus', ts.runtimeType.toString(),
              intentId: paymentIntentId,
              extra: 'outcome-confirmed status=${status.name}');
          state = AsyncData(ts);
          return;
        }

        if (isRecovery) {
          final rs = _statusToRecoveryState(status, paymentIntentId);
          _logTransition('PollingStatus', rs.runtimeType.toString(),
              intentId: paymentIntentId);
          state = AsyncData(rs);
        } else {
          state = AsyncData(PollingStatus(
            paymentIntentId: paymentIntentId,
            lastKnownStatus: status,
          ));
        }
      } on Object catch (_) {
        // Network error during poll — continue polling (NFR connectivity resilience).
      }
    }

    // Polling timeout is not evidence of expiry; stay in PollingStatus.
    if (isRecovery) {
      // Recovery timeout: stay in last recovery state.
    } else {
      state = AsyncData(PollingStatus(
        paymentIntentId: paymentIntentId,
        lastKnownStatus: PaymentIntentStatus.processing,
      ));
    }
  }

  Future<void> _startRecovery(String intentId) async {
    state = AsyncData(RecoveringPending(paymentIntentId: intentId));
    await _pollForRecovery(intentId);
  }

  Future<void> _pollForRecovery(String intentId) async {
    await pollStatus(intentId, isRecovery: true);
  }

  PaymentCheckoutState _statusToRecoveryState(
    PaymentIntentStatus status,
    String paymentIntentId,
  ) {
    return switch (status) {
      PaymentIntentStatus.pending ||
      PaymentIntentStatus.processing =>
        RecoveringPending(paymentIntentId: paymentIntentId),

      PaymentIntentStatus.awaitingAction ||
      PaymentIntentStatus.requiresConfirmation =>
        RecoveringUnknown(
          paymentIntentId:  paymentIntentId,
          supportReference: 'REF-${paymentIntentId.substring(0, 8)}',
        ),

      PaymentIntentStatus.fulfilling =>
        RecoveringFulfilling(paymentIntentId: paymentIntentId),

      _ => RecoveringUnknown(
          paymentIntentId:  paymentIntentId,
          supportReference: 'REF-${paymentIntentId.substring(0, 8)}',
        ),
    };
  }

  Future<void> cancelPayment() async {
    _pollGeneration++;
    await RecoveryRecord.clear();
    _logTransition(null, 'PaymentCancelled');
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
            amountMinor:     amountMinor,
            currencyCode:    currencyCode,
          ),
        PaymentIntentStatus.expired   => PaymentExpired(),
        PaymentIntentStatus.cancelled => PaymentCancelled(),
        _ => PaymentFailed(
            errorCode: status.name.toUpperCase(),
            message:   'Payment did not succeed.',
            canRetry:  status == PaymentIntentStatus.failed,
          ),
      };

  String _extractErrorCode(Object e) {
    final msg = e.toString();
    if (msg.contains('STEP_UP_REQUIRED'))   return 'STEP_UP_REQUIRED';
    if (msg.contains('INSUFFICIENT_FUNDS')) return 'INSUFFICIENT_FUNDS';
    return 'UNKNOWN_ERROR';
  }

  // NFR-004 + constitution P13: structured state-transition log.
  // PIN/step-up token MUST NOT appear here.
  void _logTransition(
    String? from,
    String to, {
    String? intentId,
    String? extra,
  }) {
    final ts = DateTime.now().toUtc().toIso8601String();
    final parts = <String>[
      'PaymentNotifier',
      if (from != null) 'from=$from',
      'to=$to',
      if (intentId != null) 'intentId=$intentId',
      if (extra != null) extra,
      'ts=$ts',
    ];
    debugPrint('[payment] ${parts.join(' ')}');
  }
}

final paymentNotifierProvider = AsyncNotifierProvider.autoDispose
    .family<PaymentNotifier, PaymentCheckoutState, PaymentCheckoutRequest>(
  PaymentNotifier.new,
);
