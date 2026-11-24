import 'package:dio/dio.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/security/secure_storage_service.dart';
import 'package:wallet_agent/modules/auth/domain/entities/agent.dart';
import 'package:wallet_agent/modules/auth/domain/entities/auth_token.dart';
import 'package:wallet_agent/modules/auth/domain/repositories/auth_repository.dart';
import 'package:wallet_agent/modules/auth/data/models/auth_response_dto.dart';
import 'package:wallet_agent/modules/auth/data/models/login_request_dto.dart';

class AuthRepositoryImpl implements AuthRepository {
  final Dio _dio;
  final SecureStorageService _storage;

  AuthRepositoryImpl({required Dio dio, required SecureStorageService storage})
      : _dio = dio,
        _storage = storage;

  @override
  Future<({AuthToken token, Agent agent})> login({
    required String phone,
    required String pin,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/auth/login',
      data: LoginRequestDto(phone: phone, pin: pin).toJson(),
    );
    final dto = AuthResponseDto.fromJson(response.data!);
    final token = dto.toAuthToken();
    final agent = dto.agent.toEntity();

    await Future.wait([
      _storage.saveAccessToken(token.accessToken),
      _storage.saveRefreshToken(token.refreshToken),
    ]);
    AuthInterceptor.updateCachedTokens(
      accessToken: token.accessToken,
      refreshToken: token.refreshToken,
    );

    return (token: token, agent: agent);
  }

  @override
  Future<void> logout() async {
    try {
      await _dio.post<void>('/v1/auth/logout');
    } finally {
      await _storage.clearAuthTokens();
      AuthInterceptor.clearCachedTokens();
    }
  }

  @override
  Future<AuthToken> refreshToken(String refreshToken) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/auth/token/refresh',
      data: {'refresh_token': refreshToken},
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    final token = AuthToken(
      accessToken: data['access_token'] as String,
      refreshToken: data['refresh_token'] as String,
      expiresAt: DateTime.now().add(
        Duration(seconds: (data['expires_in'] as num?)?.toInt() ?? 900),
      ),
    );
    await Future.wait([
      _storage.saveAccessToken(token.accessToken),
      _storage.saveRefreshToken(token.refreshToken),
    ]);
    AuthInterceptor.updateCachedTokens(
      accessToken: token.accessToken,
      refreshToken: token.refreshToken,
    );
    return token;
  }

  @override
  Future<({String otpReference, DateTime expiresAt})> forgotPin(
    String phone,
  ) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/auth/pin/reset/initiate',
      data: {'mobile_number': phone},
    );
    final data =
        response.data?['data'] as Map<String, dynamic>? ?? response.data!;
    final challengeId = data['challenge_id'] as String?;
    if (challengeId == null || challengeId.isEmpty) {
      throw const FormatException('PIN reset challenge was not issued.');
    }
    return (
      otpReference: challengeId,
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
    );
  }

  @override
  Future<void> resetPin({
    required String otpReference,
    required String otpCode,
    required String newPin,
  }) async {
    await _dio.post<void>(
      '/v1/auth/pin/reset/complete',
      data: {
        'challenge_id': otpReference,
        'otp': otpCode,
        'new_pin': newPin,
      },
    );
  }

  @override
  Future<void> changePin({
    required String currentPin,
    required String newPin,
  }) async {
    await _dio.post<void>(
      '/v1/auth/pin/change',
      data: {'current_pin': currentPin, 'new_pin': newPin},
    );
  }

  @override
  Future<String?> stepUp(String pin) async {
    final initiation = await _dio.post<Map<String, dynamic>>(
      '/v1/auth/step-up/initiate',
      data: {'scope': 'agent_transaction'},
    );
    final challenge =
        initiation.data?['data'] as Map<String, dynamic>? ?? initiation.data!;
    if (challenge['step_up_required'] != true) return null;
    final token = challenge['step_up_token'] as String;
    final verified = await _dio.post<Map<String, dynamic>>(
      '/v1/auth/step-up/verify',
      data: {'step_up_token': token, 'method': 'pin', 'credential': pin},
    );
    final data =
        verified.data?['data'] as Map<String, dynamic>? ?? verified.data!;
    return data['step_up_access_token'] as String?;
  }

  @override
  Future<Agent> getProfile() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/profile',
    );
    final body = response.data!;
    final profile = body['data'] is Map<String, dynamic>
        ? body['data'] as Map<String, dynamic>
        : body;
    return AgentDto.fromJson(profile).toEntity();
  }
}
