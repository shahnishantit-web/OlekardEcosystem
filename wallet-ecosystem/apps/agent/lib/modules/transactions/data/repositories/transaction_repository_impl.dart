import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_receipt.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/refund_request.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_agent/modules/transactions/data/models/transaction_dto.dart';
import 'package:wallet_agent/modules/transactions/data/models/payment_history_filter_dto.dart';
import 'package:wallet_agent/modules/transactions/data/models/receipt_dto.dart';

class AccessDeniedException implements Exception {
  final String message;
  const AccessDeniedException(this.message);
}

class TransactionRepositoryImpl implements TransactionRepository {
  final Dio _dio;
  final PaymentCacheStore _cache;

  TransactionRepositoryImpl({required Dio dio, required PaymentCacheStore cache})
      : _dio = dio,
        _cache = cache;

  @override
  Future<PaginatedResult<PaymentHistoryItem>> fetchHistory(
      PaymentHistoryFilter filter) async {
    final params = PaymentHistoryFilterDto.toQueryParams(filter);
    final res = await _dio.get<Map<String, dynamic>>(
      '/v1/payment/history',
      queryParameters: params,
    );
    final data = res.data!;
    final rawJson = jsonEncode(data);
    if (filter.page == 1) {
      await _cache.writeHistoryPage('_default', rawJson);
    }
    return PaginatedResult.fromJson(data, AgentTransactionDto.fromJson);
  }

  @override
  Future<PaymentHistoryItem> fetchDetail(String intentId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/v1/payment/history/$intentId',
    );
    final item = (res.data!['data'] ?? res.data!) as Map<String, dynamic>;
    await _cache.writeDetail(intentId, jsonEncode(item));
    return AgentTransactionDto.fromJson(item);
  }

  @override
  Future<PaymentReceipt> fetchReceipt(String intentId) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/v1/payment/receipts/$intentId',
      );
      return ReceiptDto.fromJson(
          (res.data!['data'] ?? res.data!) as Map<String, dynamic>);
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
      final res = await _dio.post<Map<String, dynamic>>(
        '/v1/payment/receipts/$intentId/download-token',
      );
      return ReceiptDownloadTokenDto.fromJson(
          (res.data!['data'] ?? res.data!) as Map<String, dynamic>);
    } on DioException catch (e) {
      if (e.response?.statusCode == 403) {
        throw const AccessDeniedException('not_owner');
      }
      rethrow;
    }
  }

  @override
  Future<void> submitRefund(RefundRequest request) async {
    await _dio.post<void>('/v1/payment/refunds', data: {
      'intent_id': request.intentId,
      'reason': request.reason,
      if (request.amountMinorUnits != null)
        'amount_minor_units': request.amountMinorUnits,
    });
  }

  @override
  Future<void> createSupportTicket(SupportTicketDraft draft) async {
    await _dio.post<void>('/v1/support/tickets', data: {
      'payment_intent_id': draft.paymentIntentId,
      'subject': draft.subject,
      if (draft.description != null) 'description': draft.description,
    });
  }
}
