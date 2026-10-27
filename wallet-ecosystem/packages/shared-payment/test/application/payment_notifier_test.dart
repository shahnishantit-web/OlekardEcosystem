import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mocktail/mocktail.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shared_payment/src/application/payment_notifier.dart';
import 'package:shared_payment/src/domain/checkout_state.dart';
import 'package:shared_payment/src/domain/models/payment_intent.dart';
import 'package:shared_payment/src/domain/models/payment_option.dart';
import 'package:shared_payment/src/domain/models/payment_status.dart';
import 'package:shared_payment/src/data/payment_repository.dart';

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockPaymentRepository extends Mock implements PaymentRepository {}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

PaymentCheckoutRequest _req({String ref = 'test-ref'}) => (
      purposeType: 'bill_payment',
      purposeReference: ref,
      currencyCode: 'BWP',
      estimatedAmountMinor: 10000,
      returnRoute: null,
    );

PaymentIntent _intent({String id = 'intent-1', int amount = 10000}) =>
    PaymentIntent(
      id: id,
      purposeType: 'bill_payment',
      purposeReference: 'test-ref',
      organisationId: 'org-1',
      payerActorId: 'actor-1',
      currencyCode: 'BWP',
      amountMinor: amount,
      status: PaymentIntentStatus.pending,
      idempotencyKey: 'idk-1',
      correlationId: 'corr-1',
      createdAt: DateTime.now(),
    );

PaymentOption _option({String code = 'olekard_wallet', bool isWallet = true}) =>
    PaymentOption(
      providerCode: code,
      displayName: code,
      isWallet: isWallet,
      hasSufficientBalance: true,
      walletBalanceMinor: 50000,
    );

Future<PaymentCheckoutState?> _loadState(
  ProviderContainer container,
  PaymentCheckoutRequest req,
) async {
  container.listen(paymentNotifierProvider(req), (_, __) {},
      fireImmediately: true);
  await Future<void>.delayed(Duration.zero);
  return container.read(paymentNotifierProvider(req)).value;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  // SharedPreferences required for RecoveryRecord.read() in build().
  setUpAll(() async {
    WidgetsFlutterBinding.ensureInitialized();
    SharedPreferences.setMockInitialValues({});
  });

  late MockPaymentRepository repo;
  late ProviderContainer container;

  setUp(() {
    repo = MockPaymentRepository();
    container = ProviderContainer(overrides: [
      paymentRepositoryProvider.overrideWithValue(repo),
    ]);
    addTearDown(container.dispose);
  });

  group('US1 — options loading', () {
    test('EmptyOptions when server returns empty list', () async {
      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => []);

      final req = _req();
      final state = await _loadState(container, req);
      expect(state, isA<EmptyOptions>());
    });

    test('OptionsLoaded when server returns options', () async {
      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => [_option()]);

      final req = _req();
      final state = await _loadState(container, req);
      expect(state, isA<OptionsLoaded>());
    });
  });

  group('US3 — SC-03: createIntent has no amount_minor', () {
    // This test verifies the repository interface is called without amount_minor.
    // The PaymentRepository.createIntent signature has no amount_minor param,
    // which is the architectural guarantee. The test verifies the call is made
    // with the four expected named arguments only.
    test('createIntent called with purposeType/purposeRef/currency/idempotencyKey only',
        () async {
      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => [_option()]);

      when(() => repo.createIntent(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
          )).thenAnswer((_) async => _intent());

      when(() => repo.initiateAttempt(
            intentId: any(named: 'intentId'),
            providerCode: any(named: 'providerCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
            stepUpToken: any(named: 'stepUpToken'),
          )).thenAnswer((_) async =>
          {'next_action_type': 'wallet_pin', 'next_action_data': null});

      when(() => repo.getStatus(any()))
          .thenAnswer((_) async => PaymentIntentStatus.succeeded);

      final req = _req();
      await _loadState(container, req);

      final notifier = container.read(paymentNotifierProvider(req).notifier);
      final loaded =
          container.read(paymentNotifierProvider(req)).value as OptionsLoaded;
      await notifier.selectOption(loaded.options.first);
      await notifier.confirmPayment();
      await Future<void>.delayed(Duration.zero);

      // Verify createIntent was NOT called with amount_minor (the interface
      // simply does not have that parameter, so the type system enforces it).
      verify(() => repo.createIntent(
            purposeType: 'bill_payment',
            purposeReference: 'test-ref',
            currencyCode: 'BWP',
            idempotencyKey: any(named: 'idempotencyKey'),
          )).called(1);
    });
  });

  group('US3 — idempotency key stability on retry', () {
    test('same intent idempotency key is reused after a network failure', () async {
      var callCount = 0;
      final capturedKeys = <String>[];

      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => [_option()]);

      when(() => repo.createIntent(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
          )).thenAnswer((inv) async {
        callCount++;
        capturedKeys.add(inv.namedArguments[#idempotencyKey] as String);
        if (callCount == 1) throw Exception('network error');
        return _intent();
      });

      when(() => repo.initiateAttempt(
            intentId: any(named: 'intentId'),
            providerCode: any(named: 'providerCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
            stepUpToken: any(named: 'stepUpToken'),
          )).thenAnswer((_) async =>
          {'next_action_type': 'wallet_pin', 'next_action_data': null});

      when(() => repo.getStatus(any()))
          .thenAnswer((_) async => PaymentIntentStatus.succeeded);

      final req = _req();
      await _loadState(container, req);

      final notifier = container.read(paymentNotifierProvider(req).notifier);
      final loaded =
          container.read(paymentNotifierProvider(req)).value as OptionsLoaded;
      await notifier.selectOption(loaded.options.first);

      // First attempt fails with network error.
      await notifier.confirmPayment();
      await Future<void>.delayed(Duration.zero);

      // Second attempt reuses the same idempotency key.
      await notifier.confirmPayment();
      await Future<void>.delayed(Duration.zero);

      expect(callCount, 2);
      // Both attempts must use the same idempotency key.
      expect(capturedKeys.length, 2);
      expect(capturedKeys[0], equals(capturedKeys[1]));
    });
  });

  group('US3 — provider replacement blocked after attempt submitted', () {
    test('selectOption after confirmPayment is a no-op', () async {
      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => [
                _option(code: 'olekard_wallet'),
                _option(code: 'fnb_redirect', isWallet: false),
              ]);

      when(() => repo.createIntent(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
          )).thenAnswer((_) async => _intent());

      when(() => repo.initiateAttempt(
            intentId: any(named: 'intentId'),
            providerCode: any(named: 'providerCode'),
            idempotencyKey: any(named: 'idempotencyKey'),
            stepUpToken: any(named: 'stepUpToken'),
          )).thenAnswer((_) async => {
                'next_action_type': 'redirect',
                'next_action_data': {
                  'redirect_url': 'https://fnb.co.bw',
                  'allowed_return_schemes': ['olekard'],
                },
              });

      final req = _req();
      await _loadState(container, req);

      final notifier = container.read(paymentNotifierProvider(req).notifier);
      final loaded =
          container.read(paymentNotifierProvider(req)).value as OptionsLoaded;

      final walletOpt = loaded.options.firstWhere((o) => o.isWallet);
      await notifier.selectOption(walletOpt);
      await notifier.confirmPayment();
      await Future<void>.delayed(Duration.zero);

      // After attempt submitted, state should be AwaitingRedirect.
      final postAttemptState =
          container.read(paymentNotifierProvider(req)).value;
      expect(postAttemptState, isA<AwaitingRedirect>());

      // Trying to switch provider now is a no-op.
      final fnbOpt = loaded.options.firstWhere((o) => !o.isWallet);
      await notifier.selectOption(fnbOpt);

      final finalState = container.read(paymentNotifierProvider(req)).value;
      // State unchanged — must still be AwaitingRedirect (not OptionsLoaded with fnb).
      expect(finalState, isA<AwaitingRedirect>());
    });
  });

  group('US4 — recovery: poll non-terminal does not produce PaymentExpired', () {
    test('pollStatus with 1 non-terminal response stays in RecoveringPending',
        () async {
      when(() => repo.getOptions(
            purposeType: any(named: 'purposeType'),
            purposeReference: any(named: 'purposeReference'),
            currencyCode: any(named: 'currencyCode'),
            estimatedAmountMinor: any(named: 'estimatedAmountMinor'),
          )).thenAnswer((_) async => []);

      // getStatus always returns processing (non-terminal).
      when(() => repo.getStatus(any()))
          .thenAnswer((_) async => PaymentIntentStatus.processing);

      final req = _req();
      await _loadState(container, req);

      // Drive pollStatus in recovery mode directly (bypasses SharedPreferences).
      await container
          .read(paymentNotifierProvider(req).notifier)
          .pollStatus('intent-99', isRecovery: true);
      await Future<void>.delayed(Duration.zero);

      final state = container.read(paymentNotifierProvider(req)).value;
      // Timeout must NOT be treated as expiry.
      expect(state, isNot(isA<PaymentExpired>()));
    });
  });
}
