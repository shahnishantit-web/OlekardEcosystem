import 'package:dio/dio.dart';
import '../../domain/entities/transfer_result.dart';
import '../../domain/repositories/transfer_repository.dart';
import '../../../../core/api/api_client.dart';

class TransferRepositoryImpl implements TransferRepository {
  final Dio _dio;

  TransferRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<WalletLookupResult> lookupWallet(String walletNumber) async {
    final res = await _dio.get('/v1/customer/wallets/lookup', queryParameters: {'wallet_number': walletNumber});
    return WalletLookupResult(
      walletNumber: res.data['wallet_number'] as String,
      accountName: res.data['account_name'] as String,
    );
  }

  @override
  Future<TransferPreview> previewTransfer({
    required String recipientWalletNumber,
    required int amountMinorUnits,
    required String currencyCode,
  }) async {
    final res = await _dio.post('/v1/customer/transfers/preview', data: {
      'recipient_wallet_number': recipientWalletNumber,
      'amount_minor_units': amountMinorUnits,
      'currency_code': currencyCode,
    });
    final d = res.data as Map<String, dynamic>;
    return TransferPreview(
      recipientName: d['recipient_name'] as String,
      recipientWalletNumber: d['recipient_wallet_number'] as String,
      amountMinorUnits: d['amount_minor_units'] as int,
      feeMinorUnits: d['fee_minor_units'] as int? ?? 0,
      currencyCode: d['currency_code'] as String,
    );
  }

  @override
  Future<TransferResult> executeTransfer(TransferRequestDto request) async {
    final res = await _dio.post(
      '/v1/customer/transfers',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
    );
    final d = (res.statusCode == 409 ? res.data['data'] : res.data) as Map<String, dynamic>;
    return TransferResult(
      transactionId: d['transaction_id'] as String,
      referenceNumber: d['reference_number'] as String,
      status: TransferStatus.completed,
      amountMinorUnits: d['amount_minor_units'] as int,
      feeMinorUnits: d['fee_minor_units'] as int? ?? 0,
      currencyCode: d['currency_code'] as String,
      recipientName: d['recipient_name'] as String,
      recipientWalletNumber: d['recipient_wallet_number'] as String,
      createdAt: DateTime.parse(d['created_at'] as String),
    );
  }
}
