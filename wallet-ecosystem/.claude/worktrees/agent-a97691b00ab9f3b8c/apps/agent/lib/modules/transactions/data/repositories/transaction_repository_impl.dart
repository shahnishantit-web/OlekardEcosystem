import 'package:dio/dio.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_agent/modules/transactions/data/models/transaction_dto.dart';

class TransactionRepositoryImpl implements TransactionRepository {
  final Dio _dio;
  TransactionRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<PaginatedResult<AgentTransaction>> getTransactions({
    int page = 1,
    String? type,
    DateTime? from,
    DateTime? to,
  }) async {
    final params = <String, dynamic>{'page': page};
    if (type != null) params['type'] = type;
    if (from != null) params['from'] = from.toIso8601String();
    if (to != null) params['to'] = to.toIso8601String();

    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/transactions',
      queryParameters: params,
    );
    final data = response.data!;
    final items = (data['data'] as List<dynamic>)
        .map((e) => AgentTransactionDto.fromJson(e as Map<String, dynamic>)
            .toEntity())
        .toList();
    return PaginatedResult(
      data: items,
      currentPage: data['current_page'] as int,
      total: data['total'] as int,
      lastPage: data['last_page'] as int,
    );
  }

  @override
  Future<AgentTransaction> getTransactionDetail(String id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/transactions/$id',
    );
    return AgentTransactionDto.fromJson(response.data!).toEntity();
  }
}
