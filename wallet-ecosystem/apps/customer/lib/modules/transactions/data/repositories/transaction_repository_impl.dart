import 'dart:convert';
import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/paginated_result.dart';
import '../../domain/entities/transaction.dart';
import '../../domain/entities/payment_history_filter.dart';
import '../../domain/entities/payment_receipt.dart';
import '../../domain/entities/refund_request.dart';
import '../../domain/repositories/transaction_repository.dart';
import '../../infrastructure/payment_cache_store.dart';
import '../models/transaction_dto.dart';
import '../models/payment_history_filter_dto.dart';
import '../models/receipt_dto.dart';

class AccessDeniedException implements Exception {
  final String message;
  const AccessDeniedException(this.message);
}

class TransactionRepositoryImpl implements TransactionRepository {
  final Dio _dio;
  final PaymentCacheStore _cache;

  TransactionRepositoryImpl({
    required ApiClient apiClient,
    required PaymentCacheStore cache,
  })  : _dio = apiClient.dio,
        _cache = cache;

  @override
  Future<PaginatedResult<PaymentHistoryItem>> fetchHistory(
      PaymentHistoryFilter filter) async {
    final params = PaymentHistoryFilterDto.toQueryParams(filter);
    final res = await _dio.get('/v1/payment/history', queryParameters: params);
    final rawJson = jsonEncode(res.data);
    // Cache only page 1 of the default filter for offline stale reads.
    if (filter.page == 1) {
      await _cache.writeHistoryPage('_default', rawJson);
    }
    return PaginatedResult.fromJson(
      res.data as Map<String, dynamic>,
      TransactionDto.fromJson,
    );
  }

  @override
  Future<PaymentHistoryItem> fetchDetail(String intentId) async {
    final res = await _dio.get('/v1/payment/history/$intentId');
    final rawJson = jsonEncode(res.data['data'] ?? res.data);
    await _cache.writeDetail(intentId, rawJson);
    return TransactionDto.fromJson(
        (res.data['data'] ?? res.data) as Map<String, dynamic>);
  }

  @override
  Future<PaymentReceipt> fetchReceipt(String intentId) async {
    try {
      final res = await _dio.get('/v1/payment/receipts/$intentId');
      return ReceiptDto.fromJson(
          (res.data['data'] ?? res.data) as Map<String, dynamic>);
    } on DioException catch (e) {
      if (e.response?.statusCode == 403) {
        throw const AccessDeniedException('not_owner');
      }
      rethrow;
    }
  }

  @override
  Future<ReceiptDownloadToken> requestDownloadToken(String intentId) async {
    try {
      final res =
          await _dio.post('/v1/payment/receipts/$intentId/download-token');
      return ReceiptDownloadTokenDto.fromJson(
          (res.data['data'] ?? res.data) as Map<String, dynamic>);
    } on DioException catch (e) {
      if (e.response?.statusCode == 403) {
        throw const AccessDeniedException('not_owner');
      }
      rethrow;
    }
  }

  @override
  Future<void> submitRefund(RefundRequest request) async {
    await _dio.post('/v1/payment/refunds', data: {
      'intent_id': request.intentId,
      'reason': request.reason,
      if (request.amountMinorUnits != null)
        'amount_minor_units': request.amountMinorUnits,
    });
  }

  @override
  Future<void> createSupportTicket(SupportTicketDraft draft) async {
    await _dio.post('/v1/support/tickets', data: {
      'payment_intent_id': draft.paymentIntentId,
      'subject': draft.subject,
      if (draft.description != null) 'description': draft.description,
      if (draft.attachmentPaths.isNotEmpty)
        'attachments': draft.attachmentPaths,
    });
  }
}
