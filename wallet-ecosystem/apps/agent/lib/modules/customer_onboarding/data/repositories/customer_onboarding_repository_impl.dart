import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart';
import 'package:wallet_agent/modules/customer_onboarding/data/models/customer_registration_dto.dart';

class CustomerOnboardingRepositoryImpl implements CustomerOnboardingRepository {
  final Dio _dio;
  final String agentId;
  final _uuid = const Uuid();

  CustomerOnboardingRepositoryImpl({required Dio dio, required this.agentId})
      : _dio = dio;

  @override
  Future<RegistrationResult> registerCustomer(CustomerProfile profile) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agents/$agentId/customers',
      data: CustomerRegistrationRequestDto.fromProfile(profile).toJson(),
      options: Options(headers: {'Idempotency-Key': _uuid.v4()}),
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    return RegistrationResultDto.fromJson(data).toEntity();
  }

  @override
  Future<void> initiateKyc(String customerId) async {
    await _dio.post<void>(
      '/v1/agents/$agentId/customers/$customerId/kyc',
      options: Options(headers: {'Idempotency-Key': _uuid.v4()}),
    );
  }

  @override
  Future<CustomerStatus> getCustomerStatus(String customerId) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agents/$agentId/customers/$customerId',
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    return CustomerStatus(
      customerId: data['id'] as String,
      customerName: data['name'] as String,
      phoneNumber: data['phone'] as String,
      accountStatus: data['account_status'] as String,
      kycStatus: data['kyc_status'] as String,
    );
  }
}
