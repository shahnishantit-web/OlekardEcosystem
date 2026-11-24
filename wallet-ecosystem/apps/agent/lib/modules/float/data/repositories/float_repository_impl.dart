import 'package:dio/dio.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';
import 'package:wallet_agent/modules/float/domain/repositories/float_repository.dart';
import 'package:wallet_agent/modules/float/data/models/float_dto.dart';

class FloatRepositoryImpl implements FloatRepository {
  final Dio _dio;
  final String agentId;

  FloatRepositoryImpl({required Dio dio, required this.agentId}) : _dio = dio;

  @override
  Future<FloatBalance> getBalance() async {
    final responses = await Future.wait([
      _dio.get<Map<String, dynamic>>('/v1/agents/$agentId/float-wallet'),
      _dio.get<Map<String, dynamic>>('/v1/agent/dashboard'),
    ]);
    final wallet = responses[0].data?['data'] as Map<String, dynamic>? ??
        responses[0].data!;
    final dashboard = responses[1].data?['data'] as Map<String, dynamic>? ??
        responses[1].data!;
    return FloatBalanceDto.fromJson({
      'wallet_id': wallet['wallet_id'],
      'balance': wallet['balance'],
      'currency_code': dashboard['float_currency_code'],
      'low_threshold_minor_units': dashboard['low_float_threshold_minor_units'],
    }).toEntity();
  }

  @override
  Future<PaginatedResult<FloatTransaction>> getTransactions({
    int page = 1,
    DateTime? fromDate,
    DateTime? toDate,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/float-wallet/transactions',
      queryParameters: {
        'page': page,
        'per_page': 20,
        if (fromDate != null)
          'from': fromDate.toIso8601String().substring(0, 10),
        if (toDate != null) 'to': toDate.toIso8601String().substring(0, 10),
      },
    );
    final data = response.data!;
    final items = (data['data'] as List<dynamic>)
        .map((e) =>
            FloatTransactionDto.fromJson(e as Map<String, dynamic>).toEntity())
        .toList();
    return PaginatedResult(
      data: items,
      currentPage: (data['meta'] as Map<String, dynamic>?)?['page'] as int? ??
          data['current_page'] as int? ??
          page,
      total: (data['meta'] as Map<String, dynamic>?)?['total'] as int? ??
          data['total'] as int? ??
          items.length,
      lastPage: (data['meta'] as Map<String, dynamic>?)?['pages'] as int? ??
          data['last_page'] as int? ??
          1,
    );
  }

  @override
  Future<FloatTopUpResult> requestTopUp(FloatTopUpRequest request) async {
    throw UnsupportedError(
        'Float funding must be performed by an authorised administrator.');
  }
}
