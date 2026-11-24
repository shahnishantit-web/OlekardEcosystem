import 'package:dio/dio.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/commissions/domain/entities/commission_balance.dart';
import 'package:wallet_agent/modules/commissions/domain/repositories/commission_repository.dart';
import 'package:wallet_agent/modules/commissions/data/models/commission_dto.dart';

class CommissionRepositoryImpl implements CommissionRepository {
  final Dio _dio;
  final String agentId;
  CommissionRepositoryImpl({required Dio dio, required this.agentId})
      : _dio = dio;

  @override
  Future<CommissionBalance> getBalance() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/commissions/summary',
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    return CommissionBalance(
      availableMinorUnits: (data['paid_total'] as num).toInt(),
      pendingMinorUnits: (data['pending_total'] as num).toInt(),
      currencyCode: data['currency_code'] as String,
    );
  }

  @override
  Future<PaginatedResult<CommissionEntry>> getEntries({
    int page = 1,
    String? type,
    DateTime? fromDate,
    DateTime? toDate,
  }) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/commissions',
      queryParameters: {
        'page': page,
        'per_page': 20,
        if (type != null && type != 'registration') 'transaction_type': type,
        if (fromDate != null)
          'from': fromDate.toIso8601String().substring(0, 10),
        if (toDate != null) 'to': toDate.toIso8601String().substring(0, 10),
      },
    );
    final data = response.data!;
    final items = (data['data'] as List<dynamic>)
        .map((e) =>
            CommissionEntryDto.fromJson(e as Map<String, dynamic>).toEntity())
        .toList();
    final meta = data['meta'] as Map<String, dynamic>? ?? const {};
    return PaginatedResult(
      data: items,
      currentPage: (meta['current_page'] as num?)?.toInt() ??
          (meta['page'] as num?)?.toInt() ??
          1,
      total: (meta['total'] as num?)?.toInt() ?? items.length,
      lastPage: (meta['last_page'] as num?)?.toInt() ??
          (meta['pages'] as num?)?.toInt() ??
          1,
    );
  }
}
