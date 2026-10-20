import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';
import '../../../../core/api/api_client.dart';
import '../../domain/entities/profile.dart';
import '../../domain/repositories/profile_repository.dart';

class ProfileRepositoryImpl implements ProfileRepository {
  final Dio _dio;

  ProfileRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<CustomerProfile> getProfile() async {
    final res = await _dio.get('/v1/users/profile');
    return _fromJson(res.data['data'] as Map<String, dynamic>);
  }

  @override
  Future<CustomerProfile> updateProfile(UpdateProfileRequest request) async {
    await _dio.put('/v1/users/profile/personal',
        data: request.toJson(),
        options: Options(headers: {'Idempotency-Key': const Uuid().v4()}));
    return getProfile();
  }

  CustomerProfile _fromJson(Map<String, dynamic> j) => CustomerProfile(
        id: j['user_id'] as String,
        firstName: j['first_name'] as String,
        lastName: j['last_name'] as String,
        email: j['email'] as String? ?? '',
        phoneNumber: j['mobile_number'] as String?,
        avatarUrl: j['profile_photo_url'] as String?,
        kycStatus: j['kyc_status'] as String? ?? 'none',
        nationalId: j['national_id'] as String?,
        dateOfBirth: j['date_of_birth'] != null
            ? DateTime.tryParse(j['date_of_birth'] as String)
            : null,
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}
