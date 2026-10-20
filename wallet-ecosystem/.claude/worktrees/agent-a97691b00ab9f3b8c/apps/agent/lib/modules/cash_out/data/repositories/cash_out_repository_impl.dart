import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';
import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/pickup_code_lookup.dart';
import 'package:wallet_agent/modules/cash_out/domain/repositories/cash_out_repository.dart';
import 'package:wallet_agent/modules/cash_out/data/models/cash_out_request_dto.dart';

class CashOutRepositoryImpl implements CashOutRepository {
  final Dio _dio;
  final _uuid = const Uuid();
  final Map<String, CashOutResult> _idempotencyCache = {};

  CashOutRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<PickupCodeLookup> lookupPickupCode(String code) async {
    try {
      final response = await _dio.get<Map<String, dynamic>>(
        '/v1/agent/cash-out/lookup',
        queryParameters: {'code': code},
      );
      return PickupCodeLookupDto.fromJson(response.data!).toEntity();
    } on DioException catch (e) {
      if (e.response?.statusCode == 410) {
        throw PickupCodeExpiredException(code);
      }
      if (e.response?.statusCode == 409) {
        throw PickupCodeRedeemedException(code);
      }
      rethrow;
    }
  }

  @override
  Future<CustomerLookup> searchCustomer(String query) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/customers',
      queryParameters: {'q': query},
    );
    return CustomerLookupDto.fromJson(response.data!).toEntity();
  }

  @override
  Future<CashOutResult> submitCashOut(CashOutRequestDto request) async {
    final idempotencyKey = _uuid.v4();
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/agent/cash-out',
        data: request.toJson(),
        options: Options(headers: {'Idempotency-Key': idempotencyKey}),
      );
      final result = CashOutResultDto.fromJson(response.data!).toEntity();
      _idempotencyCache[idempotencyKey] = result;
      return result;
    } on DioException catch (e) {
      if (e.response?.statusCode == 409) {
        final cached = _idempotencyCache[idempotencyKey];
        if (cached != null) return cached;
        return CashOutResultDto.fromJson(
          e.response!.data as Map<String, dynamic>,
        ).toEntity();
      }
      rethrow;
    }
  }
}
