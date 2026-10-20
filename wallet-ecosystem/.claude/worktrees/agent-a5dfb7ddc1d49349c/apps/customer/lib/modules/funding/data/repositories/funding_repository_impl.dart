import 'package:dio/dio.dart';
import '../../domain/entities/funding_result.dart';
import '../../domain/repositories/funding_repository.dart';
import '../../../../core/api/api_client.dart';

class FundingRepositoryImpl implements FundingRepository {
  final Dio _dio;

  FundingRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<FundingResult> initiateFunding(FundRequestDto request) async {
    final res = await _dio.post(
      '/v1/customer/wallets/me/fund',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
    );
    final data = (res.statusCode == 409 ? res.data['data'] : res.data) as Map<String, dynamic>;
    return _fromJson(data);
  }

  @override
  Future<Map<String, dynamic>> previewFunding({
    required int amountMinorUnits,
    required String currencyCode,
    required FundingMethod method,
  }) async {
    final res = await _dio.post('/v1/customer/wallets/me/fund/preview', data: {
      'amount_minor_units': amountMinorUnits,
      'currency_code': currencyCode,
      'method': method == FundingMethod.bankTransfer ? 'bank_transfer' : 'agent_cash_in',
    });
    return res.data as Map<String, dynamic>;
  }

  FundingResult _fromJson(Map<String, dynamic> j) => FundingResult(
        transactionId: j['transaction_id'] as String,
        referenceNumber: j['reference_number'] as String,
        status: FundingResultStatus.initiated,
        amountMinorUnits: j['amount_minor_units'] as int,
        currencyCode: j['currency_code'] as String,
        redirectUrl: j['redirect_url'] as String?,
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}
