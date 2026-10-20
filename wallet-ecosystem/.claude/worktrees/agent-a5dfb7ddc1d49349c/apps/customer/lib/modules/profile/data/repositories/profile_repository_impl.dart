import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../domain/entities/profile.dart';
import '../../domain/repositories/profile_repository.dart';

class ProfileRepositoryImpl implements ProfileRepository {
  final Dio _dio;

  ProfileRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<CustomerProfile> getProfile() async {
    final res = await _dio.get('/v1/customer/profile');
    return _fromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<CustomerProfile> updateProfile(UpdateProfileRequest request) async {
    final res = await _dio.patch('/v1/customer/profile', data: request.toJson());
    return _fromJson(res.data as Map<String, dynamic>);
  }

  CustomerProfile _fromJson(Map<String, dynamic> j) => CustomerProfile(
        id: j['id'] as String,
        firstName: j['first_name'] as String,
        lastName: j['last_name'] as String,
        email: j['email'] as String,
        phoneNumber: j['phone_number'] as String?,
        avatarUrl: j['avatar_url'] as String?,
        kycStatus: j['kyc_status'] as String? ?? 'pending',
        nationalId: j['national_id'] as String?,
        dateOfBirth: j['date_of_birth'] != null
            ? DateTime.tryParse(j['date_of_birth'] as String)
            : null,
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}
