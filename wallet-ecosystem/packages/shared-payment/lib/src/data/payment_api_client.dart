import 'package:dio/dio.dart';
import '../domain/models/payment_intent.dart';
import '../domain/models/payment_option.dart';
import '../domain/models/payment_status.dart';

class PaymentApiClient {
  final Dio _dio;

  PaymentApiClient({required Dio dio}) : _dio = dio;

  /// GET /api/v1/payment/options
  Future<List<PaymentOption>> getOptions({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required int estimatedAmountMinor,
  }) async {
    final res = await _dio.get('/api/v1/payment/options', queryParameters: {
      'purpose_type':          purposeType,
      'purpose_reference':     purposeReference,
      'currency_code':         currencyCode,
      'estimated_amount_minor': estimatedAmountMinor,
    });
    final list = res.data['data'] as List<dynamic>;
    return list
        .map((e) => PaymentOption.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// POST /api/v1/payment/intents — amount_minor NOT sent; server resolves from purpose_reference
  Future<PaymentIntent> createIntent({
    required String purposeType,
    required String purposeReference,
    required String currencyCode,
    required String idempotencyKey,
  }) async {
    final res = await _dio.post(
      '/api/v1/payment/intents',
      data: {
        'purpose_type':      purposeType,
        'purpose_reference': purposeReference,
        'currency_code':     currencyCode,
      },
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return PaymentIntent.fromJson(res.data['data'] as Map<String, dynamic>);
  }

  /// POST /api/v1/payment/intents/{intentId}/attempts
  Future<Map<String, dynamic>> initiateAttempt({
    required String intentId,
    required String providerCode,
    required String idempotencyKey,
    String? stepUpToken,
  }) async {
    final body = <String, dynamic>{
      'provider_code': providerCode,
      if (stepUpToken != null) 'step_up_token': stepUpToken,
    };
    final res = await _dio.post(
      '/api/v1/payment/intents/$intentId/attempts',
      data: body,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return res.data['data'] as Map<String, dynamic>;
  }

  /// GET /api/v1/payment/intents/{intentId}/status
  Future<PaymentIntentStatus> getStatus(String intentId) async {
    final res = await _dio.get('/api/v1/payment/intents/$intentId/status');
    final statusStr = res.data['data']['status'] as String;
    return PaymentIntentStatus.fromJson(statusStr);
  }
}
