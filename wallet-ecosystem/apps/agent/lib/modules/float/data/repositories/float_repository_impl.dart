import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';
import 'package:wallet_agent/modules/float/domain/repositories/float_repository.dart';
import 'package:wallet_agent/modules/float/data/models/float_dto.dart';

class FloatRepositoryImpl implements FloatRepository {
  final Dio _dio;
  final _uuid = const Uuid();

  FloatRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<FloatBalance> getBalance() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/float/balance',
    );
    return FloatBalanceDto.fromJson(response.data!).toEntity();
  }

  @override
  Future<PaginatedResult<FloatTransaction>> getTransactions({
    int page = 1,
    DateTime? fromDate,
    DateTime? toDate,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/float/transactions',
      queryParameters: {
        'page': page,
        if (fromDate != null) 'from_date': fromDate.toIso8601String().substring(0, 10),
        if (toDate != null) 'to_date': toDate.toIso8601String().substring(0, 10),
      },
    );
    final data = response.data!;
    final items = (data['data'] as List<dynamic>)
        .map((e) => FloatTransactionDto.fromJson(e as Map<String, dynamic>)
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
  Future<FloatTopUpResult> requestTopUp(FloatTopUpRequest request) async {
    final idempotencyKey = _uuid.v4();
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/float/top-up',
      data: {
        'amount_minor_units': request.amountMinorUnits,
        'currency_code': request.currencyCode,
      },
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return FloatTopUpResultDto.fromJson(response.data!).toEntity();
  }
}
