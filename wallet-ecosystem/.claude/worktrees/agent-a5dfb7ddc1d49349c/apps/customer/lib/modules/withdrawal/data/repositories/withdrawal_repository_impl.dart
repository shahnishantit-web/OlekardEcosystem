import 'package:dio/dio.dart';
import '../../domain/entities/withdrawal_result.dart';
import '../../domain/repositories/withdrawal_repository.dart';
import '../../../../core/api/api_client.dart';

class WithdrawalRepositoryImpl implements WithdrawalRepository {
  final Dio _dio;

  WithdrawalRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<WithdrawalPreview> previewWithdrawal({
    required String agentWalletNumber,
    required int amountMinorUnits,
    required String currencyCode,
  }) async {
    final res = await _dio.post('/v1/customer/withdrawals/preview', data: {
      'agent_wallet_number': agentWalletNumber,
      'amount_minor_units': amountMinorUnits,
      'currency_code': currencyCode,
    });
    final d = res.data as Map<String, dynamic>;
    return WithdrawalPreview(
      amountMinorUnits: d['amount_minor_units'] as int,
      feeMinorUnits: d['fee_minor_units'] as int? ?? 0,
      currencyCode: d['currency_code'] as String,
      agentName: d['agent_name'] as String,
    );
  }

  @override
  Future<WithdrawalResult> executeWithdrawal(WithdrawRequestDto request) async {
    final res = await _dio.post(
      '/v1/customer/withdrawals',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
    );
    final d = (res.statusCode == 409 ? res.data['data'] : res.data) as Map<String, dynamic>;
    return WithdrawalResult(
      transactionId: d['transaction_id'] as String,
      referenceNumber: d['reference_number'] as String,
      status: WithdrawalStatus.readyForPickup,
      amountMinorUnits: d['amount_minor_units'] as int,
      feeMinorUnits: d['fee_minor_units'] as int? ?? 0,
      currencyCode: d['currency_code'] as String,
      agentName: d['agent_name'] as String,
      pickupCode: d['pickup_code'] as String?,
      createdAt: DateTime.parse(d['created_at'] as String),
    );
  }
}
