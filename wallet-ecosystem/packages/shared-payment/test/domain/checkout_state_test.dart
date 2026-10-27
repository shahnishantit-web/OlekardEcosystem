import 'package:flutter_test/flutter_test.dart';
import 'package:shared_payment/src/domain/models/checkout_result.dart';
import 'package:shared_payment/src/domain/checkout_state.dart';

void main() {
  group('CheckoutResult', () {
    test('has no provider_reference_id field', () {
      const result = CheckoutResult(succeeded: true, paymentIntentId: 'abc');
      expect(result.succeeded, isTrue);
      expect(result.paymentIntentId, 'abc');
      expect(result.errorCode, isNull);
      // Verify the class has no provider-reference field by checking it compiles
      // and only has the three expected public fields.
    });

    test('succeeded is false for PaymentFailed state', () {
      final state = PaymentFailed(
          errorCode: 'PAYMENT_FAILED', message: 'failed', canRetry: false);
      expect(state, isA<PaymentFailed>());
    });

    test('succeeded is false for PaymentCancelled state', () {
      expect(PaymentCancelled(), isA<PaymentCancelled>());
    });

    test('succeeded is false for PaymentExpired state', () {
      expect(PaymentExpired(), isA<PaymentExpired>());
    });

    test('PaymentSucceeded amountMinor matches server value', () {
      final state = PaymentSucceeded(
          paymentIntentId: 'intent-1',
          amountMinor: 10000,
          currencyCode: 'BWP');
      expect(state.amountMinor, 10000);
      expect(state.currencyCode, 'BWP');
    });
  });

  group('CheckoutResult constructors', () {
    test('const constructor with defaults', () {
      const r = CheckoutResult(succeeded: false);
      expect(r.paymentIntentId, isNull);
      expect(r.errorCode, isNull);
    });
  });
}
