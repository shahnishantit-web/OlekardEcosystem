import 'package:dio/dio.dart';
import '../../domain/entities/customer.dart';
import '../../domain/repositories/auth_repository.dart';
import '../models/auth_response_dto.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/security/secure_storage_service.dart';

class AuthException implements Exception {
  final String message;
  const AuthException(this.message);
}

class ValidationException implements Exception {
  final Map<String, List<String>> errors;
  const ValidationException(this.errors);
}

class AuthRepositoryImpl implements AuthRepository {
  final Dio _dio;
  final SecureStorageService _storage;

  AuthRepositoryImpl({required ApiClient apiClient, required SecureStorageService storage})
      : _dio = apiClient.dio,
        _storage = storage;

  @override
  Future<String> register({
    required String phone,
    required String firstName,
    required String lastName,
  }) async {
    try {
      final res = await _dio.post('/v1/customer/auth/register/initiate', data: {
        'phone': phone,
        'first_name': firstName,
        'last_name': lastName,
      });
      return res.data['otp_reference'] as String;
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<String> verifyOtp({
    required String otpReference,
    required String otpCode,
  }) async {
    try {
      final res = await _dio.post('/v1/customer/auth/register/verify', data: {
        'otp_reference': otpReference,
        'otp_code': otpCode,
      });
      return res.data['registration_token'] as String;
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<Customer> completeRegistration({
    required String registrationToken,
    required String pinHash,
    String? email,
  }) async {
    try {
      final res = await _dio.post(
        '/v1/customer/auth/register/complete',
        data: {
          'pin_hash': pinHash,
          if (email != null) 'email': email,
        },
        options: Options(headers: {'Authorization': 'Bearer $registrationToken'}),
      );
      final dto = AuthResponseDto.fromJson(res.data as Map<String, dynamic>);
      await _storage.writeAccessToken(dto.accessToken);
      await _storage.writeRefreshToken(dto.refreshToken);
      return dto.toCustomer();
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<({String challengeToken, String method})> initiateLogin({
    required String phone,
    required String method,
  }) async {
    try {
      final res = await _dio.post('/v1/customer/auth/login', data: {
        'phone': phone,
        'method': method,
      });
      return (
        challengeToken: res.data['challenge_token'] as String,
        method: res.data['method'] as String,
      );
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<Customer> verifyLogin({
    required String challengeToken,
    required String pinHash,
  }) async {
    try {
      final res = await _dio.post('/v1/customer/auth/login/verify', data: {
        'challenge_token': challengeToken,
        'pin_hash': pinHash,
      });
      final dto = AuthResponseDto.fromJson(res.data as Map<String, dynamic>);
      await _storage.writeAccessToken(dto.accessToken);
      await _storage.writeRefreshToken(dto.refreshToken);
      return dto.toCustomer();
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _dio.post('/v1/customer/auth/logout');
    } catch (_) {
      // Always clear local storage regardless of network result
    } finally {
      await _storage.deleteAll();
    }
  }

  @override
  Future<String> refreshToken(String refreshToken) async {
    final res = await _dio.post(
      '/v1/customer/auth/refresh',
      data: {'refresh_token': refreshToken},
    );
    return res.data['access_token'] as String;
  }

  @override
  Future<String> initiatePinReset(String phone) async {
    try {
      final res = await _dio.post('/v1/customer/auth/reset-pin/initiate', data: {'phone': phone});
      return res.data['otp_reference'] as String;
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<void> completePinReset({
    required String otpReference,
    required String otpCode,
    required String newPinHash,
  }) async {
    try {
      await _dio.post('/v1/customer/auth/reset-pin/complete', data: {
        'otp_reference': otpReference,
        'otp_code': otpCode,
        'new_pin_hash': newPinHash,
      });
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<void> changePin({
    required String currentPinHash,
    required String newPinHash,
  }) async {
    try {
      await _dio.post('/v1/customer/auth/change-pin', data: {
        'current_pin_hash': currentPinHash,
        'new_pin_hash': newPinHash,
      });
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  @override
  Future<void> registerPushToken({
    required String deviceId,
    required String fcmToken,
  }) async {
    try {
      await _dio.post('/v1/customer/devices/$deviceId/push-token', data: {'fcm_token': fcmToken});
    } catch (_) {
      // Non-fatal — token registration can be retried
    }
  }

  @override
  Future<void> unregisterPushToken(String deviceId) async {
    try {
      await _dio.delete('/v1/customer/devices/$deviceId/push-token');
    } catch (_) {}
  }

  Never _handleDioError(DioException e) {
    if (e.response?.statusCode == 422) {
      final errors = (e.response!.data['errors'] as Map<String, dynamic>).map(
        (k, v) => MapEntry(k, (v as List).cast<String>()),
      );
      throw ValidationException(errors);
    }
    if (e.response?.statusCode == 401) {
      throw AuthException(
        e.response?.data?['message'] as String? ?? 'Unauthorized',
      );
    }
    throw AuthException(e.message ?? 'Network error');
  }
}
