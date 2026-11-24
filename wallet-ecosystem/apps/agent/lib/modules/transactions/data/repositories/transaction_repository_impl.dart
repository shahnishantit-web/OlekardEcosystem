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

class AccessDeniedException implements Exception {
  final String message;
  const AccessDeniedException(this.message);
}

class TransactionRepositoryImpl implements TransactionRepository {
  final Dio _dio;
  final PaymentCacheStore _cache;
  final String agentId;

  TransactionRepositoryImpl(
      {required Dio dio,
      required PaymentCacheStore cache,
      required this.agentId})
      : _dio = dio,
        _cache = cache;

  @override
  Future<PaginatedResult<PaymentHistoryItem>> fetchHistory(
      PaymentHistoryFilter filter) async {
    final params = <String, dynamic>{
      'page': filter.page,
      'per_page': filter.perPage,
      if (filter.dateFrom != null)
        'from': filter.dateFrom!.toIso8601String().substring(0, 10),
      if (filter.dateTo != null)
        'to': filter.dateTo!.toIso8601String().substring(0, 10),
      if (filter.status != null) 'status': filter.status!.name,
      if (filter.purposeType != null)
        'type': switch (filter.purposeType!) {
          PaymentPurposeType.fundingBankTransfer => 'funding',
          PaymentPurposeType.fundingAgentCashIn => 'cash_in',
          PaymentPurposeType.withdrawal => 'cash_out',
          _ => null,
        },
    }..removeWhere((key, value) => value == null);
    final res = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/float-wallet/transactions',
      queryParameters: params,
    );
    final data = res.data!;
    final rawJson = jsonEncode(data);
    if (filter.page == 1) {
      await _cache.writeHistoryPage('agent', rawJson);
    }
    final items = (data['data'] as List<dynamic>)
        .map((e) => AgentTransactionDto.fromJson(e as Map<String, dynamic>))
        .toList();
    final meta = data['meta'] as Map<String, dynamic>? ?? const {};
    return PaginatedResult(
      data: items,
      currentPage: (meta['page'] as num?)?.toInt() ?? filter.page,
      total: (meta['total'] as num?)?.toInt() ?? items.length,
      lastPage: (meta['pages'] as num?)?.toInt() ?? 1,
    );
  }

  @override
  Future<PaymentHistoryItem> fetchDetail(String intentId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/float-wallet/transactions/$intentId',
    );
    final item = (res.data!['data'] ?? res.data!) as Map<String, dynamic>;
    await _cache.writeDetail(intentId, jsonEncode(item));
    return AgentTransactionDto.fromJson(item);
  }

  @override
  Future<PaymentReceipt> fetchReceipt(String intentId) async {
    throw UnsupportedError('Receipts are not part of Agent float activity.');
  }

  @override
  Future<ReceiptDownloadToken> requestDownloadToken(String intentId) async {
    throw UnsupportedError(
        'Receipt downloads are not part of Agent float activity.');
  }

  @override
  Future<void> submitRefund(RefundRequest request) async {
    throw UnsupportedError(
        'Refunds are not available for Agent float activity.');
  }

  @override
  Future<void> createSupportTicket(SupportTicketDraft draft) async {
    throw UnsupportedError(
        'Payment-intent support is not part of Agent float activity.');
  }
}
