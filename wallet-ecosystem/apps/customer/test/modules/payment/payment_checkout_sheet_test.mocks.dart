// ignore_for_file: type=lint
// ignore_for_file: override_on_non_overriding_member
// ignore_for_file: argument_type_not_assignable
import 'dart:async' as _i2;
import 'package:mockito/mockito.dart' as _i3;
import 'package:shared_payment/src/data/payment_repository.dart';
import 'package:shared_payment/src/domain/models/payment_intent.dart';
import 'package:shared_payment/src/domain/models/payment_option.dart';
import 'package:shared_payment/src/domain/models/payment_status.dart';

// Parameters are nullable to allow anyNamed() matchers in when() calls.
class MockPaymentRepository extends _i3.Mock implements PaymentRepository {
  MockPaymentRepository() {
    _i3.throwOnMissingStub(this);
  }

  @override
  _i2.Future<List<PaymentOption>> getOptions({
    required String? purposeType,
    required String? purposeReference,
    required String? currencyCode,
    required int? estimatedAmountMinor,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#getOptions, [], {
          #purposeType: purposeType,
          #purposeReference: purposeReference,
          #currencyCode: currencyCode,
          #estimatedAmountMinor: estimatedAmountMinor,
        }),
        returnValue: _i2.Future<List<PaymentOption>>.value(<PaymentOption>[]),
      ) as _i2.Future<List<PaymentOption>>);

  @override
  _i2.Future<PaymentIntent> createIntent({
    required String? purposeType,
    required String? purposeReference,
    required String? currencyCode,
    required String? idempotencyKey,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#createIntent, [], {
          #purposeType: purposeType,
          #purposeReference: purposeReference,
          #currencyCode: currencyCode,
          #idempotencyKey: idempotencyKey,
        }),
        returnValue: _i2.Future<PaymentIntent>.value(
          PaymentIntent(
            id: '',
            purposeType: '',
            purposeReference: '',
            organisationId: '',
            payerActorId: '',
            currencyCode: '',
            amountMinor: 0,
            status: PaymentIntentStatus.pending,
            idempotencyKey: '',
            correlationId: '',
            createdAt: DateTime(2026),
          ),
        ),
      ) as _i2.Future<PaymentIntent>);

  @override
  _i2.Future<Map<String, dynamic>> initiateAttempt({
    required String? intentId,
    required String? providerCode,
    required String? idempotencyKey,
    String? stepUpToken,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#initiateAttempt, [], {
          #intentId: intentId,
          #providerCode: providerCode,
          #idempotencyKey: idempotencyKey,
          #stepUpToken: stepUpToken,
        }),
        returnValue:
            _i2.Future<Map<String, dynamic>>.value(<String, dynamic>{}),
      ) as _i2.Future<Map<String, dynamic>>);

  @override
  _i2.Future<PaymentIntentStatus> getStatus(String? intentId) =>
      (super.noSuchMethod(
        Invocation.method(#getStatus, [intentId]),
        returnValue: _i2.Future<PaymentIntentStatus>.value(
            PaymentIntentStatus.pending),
      ) as _i2.Future<PaymentIntentStatus>);
}
