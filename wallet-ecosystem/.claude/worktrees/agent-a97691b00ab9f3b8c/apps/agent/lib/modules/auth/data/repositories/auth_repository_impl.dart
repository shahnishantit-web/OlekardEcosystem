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
    required String pinHash,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/auth/login',
      data: LoginRequestDto(phone: phone, pinHash: pinHash).toJson(),
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
      await _dio.post<void>('/v1/agent/auth/logout');
    } finally {
      await _storage.clearAuthTokens();
      AuthInterceptor.clearCachedTokens();
    }
  }

  @override
  Future<AuthToken> refreshToken(String refreshToken) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/auth/refresh',
      data: {'refresh_token': refreshToken},
    );
    final dto = AuthResponseDto.fromJson(response.data!);
    final token = dto.toAuthToken();
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
      '/v1/agent/auth/forgot-pin',
      data: ForgotPinRequestDto(phone: phone).toJson(),
    );
    final data = response.data!;
    return (
      otpReference: data['otp_reference'] as String,
      expiresAt: DateTime.parse(data['expires_at'] as String),
    );
  }

  @override
  Future<void> resetPin({
    required String otpReference,
    required String otpCode,
    required String newPinHash,
  }) async {
    await _dio.post<void>(
      '/v1/agent/auth/reset-pin',
      data: ResetPinRequestDto(
        otpReference: otpReference,
        otpCode: otpCode,
        newPinHash: newPinHash,
      ).toJson(),
    );
  }

  @override
  Future<void> changePin({
    required String currentPinHash,
    required String newPinHash,
  }) async {
    await _dio.post<void>(
      '/v1/agent/auth/change-pin',
      data: ChangePinRequestDto(
        currentPinHash: currentPinHash,
        newPinHash: newPinHash,
      ).toJson(),
    );
  }

  @override
  Future<String> stepUp(String pinHash) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/v1/agent/auth/step-up',
      data: StepUpRequestDto(pinHash: pinHash).toJson(),
    );
    return response.data!['step_up_token'] as String;
  }

  @override
  Future<Agent> getProfile() async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/v1/agent/profile',
    );
    return AgentDto.fromJson(response.data!).toEntity();
  }
}
