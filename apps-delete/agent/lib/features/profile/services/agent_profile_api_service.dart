import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import '../../../core/api/api_client.dart';
import '../models/agent_profile_models.dart';

/// Typed exception for Agent Profile API errors.
class AgentProfileApiException implements Exception {
  const AgentProfileApiException({
    required this.code,
    required this.message,
    this.statusCode,
  });

  final String code;
  final String message;
  final int? statusCode;

  @override
  String toString() => 'AgentProfileApiException($code): $message';
}

/// Agent-scoped E02 Profile API calls.
/// Subset of customer profile: no email change, no closure, no data export.
class AgentProfileApiService {
  const AgentProfileApiService(this._client);

  @visibleForTesting
  AgentProfileApiService.forTest() : _client = null;

  final ApiClient? _client;
  ApiClient get _c => _client!;

  // ── Profile ───────────────────────────────────────────────────────────────

  Future<AgentProfileData> getProfile() => _guard(() async {
        final response = await _c.get<AgentProfileData>(
          '/v1/users/profile',
          (json) => AgentProfileData.fromJson(
              (json as Map<String, dynamic>)['data'] as Map<String, dynamic>),
        );
        return response.data!;
      });

  Future<AgentProfileData> updatePersonalInfo(Map<String, dynamic> fields,
          {required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.put<AgentProfileData>(
          '/v1/users/profile/personal',
          fields,
          (json) => AgentProfileData.fromJson(
              (json as Map<String, dynamic>)['data'] as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  Future<AgentProfileData> uploadPhoto(File photo,
          {required String idempotencyKey}) =>
      _guard(() async {
        final formData = FormData.fromMap({
          'photo': await MultipartFile.fromFile(
            photo.path,
            filename: photo.path.split('/').last,
          ),
        });
        final response = await _c.postMultipart<AgentProfileData>(
          '/v1/users/profile/photo',
          formData,
          (json) => AgentProfileData.fromJson(
              (json as Map<String, dynamic>)['data'] as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  // ── Mobile change (no email change for agents) ────────────────────────────

  Future<AgentContactChallengeData> initiateMobileChange(String newMobile,
          {required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.post<AgentContactChallengeData>(
          '/v1/users/profile/mobile/change',
          {'new_mobile': newMobile},
          (json) => AgentContactChallengeData.fromJson(
              (json as Map<String, dynamic>)['data'] as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  Future<void> verifyMobileChange(String challengeId, String otp,
          {required String idempotencyKey}) =>
      _guard(() async {
        await _c.post<void>(
          '/v1/users/profile/mobile/verify',
          {'challenge_id': challengeId, 'otp': otp},
          (_) {},
          idempotencyKey: idempotencyKey,
        );
      });

  // ── Account status ────────────────────────────────────────────────────────

  Future<AgentAccountStatusData> getAccountStatus() => _guard(() async {
        final response = await _c.get<AgentAccountStatusData>(
          '/v1/users/profile/status',
          (json) => AgentAccountStatusData.fromJson(
              (json as Map<String, dynamic>)['data'] as Map<String, dynamic>),
        );
        return response.data!;
      });

  // ── Guard ─────────────────────────────────────────────────────────────────

  Future<T> _guard<T>(Future<T> Function() fn) async {
    try {
      return await fn();
    } on DioException catch (e) {
      final data =
          e.response?.data is Map ? e.response!.data as Map<String, dynamic> : {};
      final error = data['error'] as Map<String, dynamic>? ?? {};
      throw AgentProfileApiException(
        code: (error['code'] as String?) ?? 'UNKNOWN',
        message:
            (error['message'] as String?) ?? e.message ?? 'Unknown error',
        statusCode: e.response?.statusCode,
      );
    }
  }
}
