import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/models/payment_intent.dart';
import '../domain/models/payment_option.dart';
import '../domain/models/payment_status.dart';
import 'payment_api_client.dart';
import '../../../core/di/providers.dart';

/// T115: Repository interface and implementation wrapping PaymentApiClient.
abstract interface class PaymentRepository {
  Future<List<PaymentOption>> getOptions({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required int estimatedAmountMinor,
  });

  Future<PaymentIntent> createIntent({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required String idempotencyKey,
  });

  Future<Map<String, dynamic>> initiateAttempt({
    required String intentId,
    required String providerCode,
    required String idempotencyKey,
    String? stepUpToken,
  });

  Future<PaymentIntentStatus> getStatus(String intentId);
}

class PaymentRepositoryImpl implements PaymentRepository {
  final PaymentApiClient _client;

  PaymentRepositoryImpl({required PaymentApiClient client}) : _client = client;

  @override
  Future<List<PaymentOption>> getOptions({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required int estimatedAmountMinor,
  }) =>
      _client.getOptions(
        purposeType: purposeType,
        purposeReference: purposeReference,
        currencyCode: currencyCode,
        estimatedAmountMinor: estimatedAmountMinor,
      );

  @override
  Future<PaymentIntent> createIntent({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required String idempotencyKey,
  }) =>
      _client.createIntent(
        purposeType: purposeType,
        purposeReference: purposeReference,
        currencyCode: currencyCode,
        idempotencyKey: idempotencyKey,
      );

  @override
  Future<Map<String, dynamic>> initiateAttempt({
    required String intentId,
    required String providerCode,
    required String idempotencyKey,
    String? stepUpToken,
  }) =>
      _client.initiateAttempt(
        intentId: intentId,
        providerCode: providerCode,
        idempotencyKey: idempotencyKey,
        stepUpToken: stepUpToken,
      );

  @override
  Future<PaymentIntentStatus> getStatus(String intentId) =>
      _client.getStatus(intentId);
}

// Riverpod providers

final paymentApiClientProvider = Provider<PaymentApiClient>((ref) {
  return PaymentApiClient(apiClient: ref.watch(apiClientProvider));
});

final paymentRepositoryProvider = Provider<PaymentRepository>((ref) {
  return PaymentRepositoryImpl(client: ref.watch(paymentApiClientProvider));
});
