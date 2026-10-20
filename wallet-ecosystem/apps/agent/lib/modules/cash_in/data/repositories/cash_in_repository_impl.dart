import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';
import 'package:wallet_agent/modules/cash_in/domain/repositories/cash_in_repository.dart';
import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';

class CashInRepositoryImpl implements CashInRepository {
  final Dio _dio;
  final _uuid = const Uuid();

  // Idempotency cache: key → result (prevents double-processing of 409 replays)
  final Map<String, CashInResult> _idempotencyCache = {};

  CashInRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<CustomerLookup> searchCustomer(String query) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/customers',
      queryParameters: {'q': query},
    );
    return CustomerLookupDto.fromJson(response.data!).toEntity();
  }

  @override
  Future<CashInResult> submitCashIn(CashInRequestDto request) async {
    final idempotencyKey = _uuid.v4();
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/agent/cash-in',
        data: request.toJson(),
        options: Options(headers: {'Idempotency-Key': idempotencyKey}),
      );
      final result = CashInResultDto.fromJson(response.data!).toEntity();
      _idempotencyCache[idempotencyKey] = result;
      return result;
    } on DioException catch (e) {
      if (e.response?.statusCode == 409) {
        // Idempotent replay — return cached or parsed result.
        final cached = _idempotencyCache[idempotencyKey];
        if (cached != null) return cached;
        return CashInResultDto.fromJson(
          e.response!.data as Map<String, dynamic>,
        ).toEntity();
      }
      rethrow;
    }
  }
}
