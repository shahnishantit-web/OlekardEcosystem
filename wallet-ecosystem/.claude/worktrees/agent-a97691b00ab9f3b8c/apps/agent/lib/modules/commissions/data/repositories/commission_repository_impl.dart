import 'package:dio/dio.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/commissions/domain/entities/commission_balance.dart';
import 'package:wallet_agent/modules/commissions/domain/repositories/commission_repository.dart';
import 'package:wallet_agent/modules/commissions/data/models/commission_dto.dart';

class CommissionRepositoryImpl implements CommissionRepository {
  final Dio _dio;
  CommissionRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<CommissionBalance> getBalance() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/commissions/balance',
    );
    return CommissionBalanceDto.fromJson(response.data!).toEntity();
  }

  @override
  Future<PaginatedResult<CommissionEntry>> getEntries({
    int page = 1,
    String? type,
    DateTime? fromDate,
    DateTime? toDate,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/commissions',
      queryParameters: {
        'page': page,
        if (type != null) 'type': type,
        if (fromDate != null) 'from_date': fromDate.toIso8601String().substring(0, 10),
        if (toDate != null) 'to_date': toDate.toIso8601String().substring(0, 10),
      },
    );
    final data = response.data!;
    final items = (data['data'] as List<dynamic>)
        .map((e) =>
            CommissionEntryDto.fromJson(e as Map<String, dynamic>).toEntity())
        .toList();
    return PaginatedResult(
      data: items,
      currentPage: data['current_page'] as int,
      total: data['total'] as int,
      lastPage: data['last_page'] as int,
    );
  }
}
