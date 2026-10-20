import 'dart:io';

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart';
import 'package:wallet_agent/modules/customer_onboarding/data/models/customer_registration_dto.dart';

class CustomerOnboardingRepositoryImpl
    implements CustomerOnboardingRepository {
  final Dio _dio;
  final _uuid = const Uuid();

  CustomerOnboardingRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<RegistrationResult> registerCustomer(CustomerProfile profile) async {
    final idempotencyKey = _uuid.v4();
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/customers',
      data: CustomerRegistrationRequestDto.fromProfile(profile).toJson(),
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return RegistrationResultDto.fromJson(response.data!).toEntity();
  }

  @override
  Future<void> submitKycDocuments({
    required String customerId,
    required File frontImage,
    File? backImage,
  }) async {
    final formData = FormData.fromMap({
      'front_image': await MultipartFile.fromFile(
        frontImage.path,
        filename: 'id_front.jpg',
      ),
      if (backImage != null)
        'back_image': await MultipartFile.fromFile(
          backImage.path,
          filename: 'id_back.jpg',
        ),
    });
    await _dio.post<void>(
      '/v1/agent/customers/$customerId/kyc',
      data: formData,
    );
  }

  @override
  Future<CustomerStatus> getCustomerStatus(String customerId) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/customers/$customerId',
    );
    final data = response.data!;
    return CustomerStatus(
      customerId: data['id'] as String,
      customerName: data['name'] as String,
      phoneNumber: data['phone'] as String,
      accountStatus: data['account_status'] as String,
      kycStatus: data['kyc_status'] as String,
    );
  }
}
