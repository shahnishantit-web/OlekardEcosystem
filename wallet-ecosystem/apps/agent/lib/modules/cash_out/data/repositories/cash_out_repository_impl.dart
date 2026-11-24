import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';
import 'package:wallet_agent/modules/cash_out/data/models/cash_out_request_dto.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/pickup_code_lookup.dart';
import 'package:wallet_agent/modules/cash_out/domain/repositories/cash_out_repository.dart';

class CashOutRepositoryImpl implements CashOutRepository {
  final Dio _dio;
  final String agentId;
  final _uuid = const Uuid();

  CashOutRepositoryImpl({required Dio dio, required this.agentId}) : _dio = dio;

  @override
  Future<PickupCodeLookup> lookupPickupCode(String code) async {
    throw UnsupportedError(
      'Pickup-code lookup is not available in the current API. Search for the customer instead.',
    );
  }

  @override
  Future<CustomerLookup> searchCustomer(String query) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/customers',
      queryParameters: {'search': query, 'per_page': 20},
    );
    final rows = response.data?['data'] as List<dynamic>? ?? const [];
    if (rows.isEmpty) {
      throw DioException(
        requestOptions: RequestOptions(path: '/v1/agents/$agentId/customers'),
        response: Response(
          requestOptions: RequestOptions(path: '/v1/agents/$agentId/customers'),
          statusCode: 404,
        ),
      );
    }
    final row = rows.first as Map<String, dynamic>;
    final customerId = row['id'] as String;
    final walletResponse = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/customers/$customerId/wallet',
    );
    final wallet = walletResponse.data?['data'] as Map<String, dynamic>? ??
        walletResponse.data!;
    return CustomerLookupDto(
      customerId: customerId,
      walletId: wallet['wallet_id'] as String,
      customerName:
          '${row['first_name'] ?? ''} ${row['last_name'] ?? ''}'.trim(),
      maskedWalletNumber: 'Wallet linked',
      accountStatus: row['status'] as String? ?? 'unknown',
    ).toEntity();
  }

  @override
  Future<CashOutResult> submitCashOut(CashOutRequestDto request) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agents/$agentId/customers/${request.customerId}/cash-out',
      data: request.toJson(),
      options: Options(headers: {'Idempotency-Key': _uuid.v4()}),
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    return CashOutResult(
      referenceNumber: data['reference'] as String? ?? data['id'] as String,
      amountMinorUnits: (data['amount'] as num).toInt(),
      feeMinorUnits: 0,
      currencyCode: data['currency_code'] as String,
      customerName: request.customerName,
      agentName: 'Agent',
      agentLocationCode: '',
      timestamp: DateTime.parse(data['created_at'] as String),
      status: data['status'] as String,
    );
  }
}
