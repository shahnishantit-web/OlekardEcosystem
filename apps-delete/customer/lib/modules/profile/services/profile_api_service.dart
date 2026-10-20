import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import '../../../core/api/api_client.dart';
import '../models/profile_models.dart';

/// Typed exception for Profile API errors.
class ProfileApiException implements Exception {
  const ProfileApiException({
    required this.code,
    required this.message,
    this.statusCode,
  });

  final String code;
  final String message;
  final int? statusCode;

  @override
  String toString() => 'ProfileApiException($code): $message';
}

/// All E02 Profile API calls. Maps profile-api.md endpoints to typed return values.
class ProfileApiService {
  const ProfileApiService(this._client);

  @visibleForTesting
  ProfileApiService.forTest() : _client = null;

  final ApiClient? _client;
  ApiClient get _c => _client!;

  // ── Profile ───────────────────────────────────────────────────────────────

  Future<ProfileData> getProfile() => _guard(() async {
        final response = await _c.get<ProfileData>(
          '/v1/users/profile',
          (json) =>
              ProfileData.fromJson((json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
        );
        return response.data!;
      });

  Future<ProfileData> updatePersonalInfo(Map<String, dynamic> fields,
      {required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.put<ProfileData>(
          '/v1/users/profile/personal',
          fields,
          (json) =>
              ProfileData.fromJson((json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  Future<ProfileData> uploadPhoto(File photo,
      {required String idempotencyKey}) =>
      _guard(() async {
        final formData = FormData.fromMap({
          'photo': await MultipartFile.fromFile(
            photo.path,
            filename: photo.path.split('/').last,
          ),
        });
        final response = await _c.postMultipart<ProfileData>(
          '/v1/users/profile/photo',
          formData,
          (json) =>
              ProfileData.fromJson((json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  // ── Contact change ────────────────────────────────────────────────────────

  Future<ContactChallengeData> initiateEmailChange(String newEmail,
      {required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.post<ContactChallengeData>(
          '/v1/users/profile/email/change',
          {'new_email': newEmail},
          (json) => ContactChallengeData.fromJson(
              (json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
        );
        return response.data!;
      });

  Future<void> verifyEmailChange(String challengeId, String otp,
      {required String idempotencyKey}) =>
      _guard(() async {
        await _c.post<void>(
          '/v1/users/profile/email/verify',
          {'challenge_id': challengeId, 'otp': otp},
          (_) {},
          idempotencyKey: idempotencyKey,
        );
      });

  Future<ContactChallengeData> initiateMobileChange(String newMobile,
      {required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.post<ContactChallengeData>(
          '/v1/users/profile/mobile/change',
          {'new_mobile': newMobile},
          (json) => ContactChallengeData.fromJson(
              (json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
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

  Future<AccountStatusData> getAccountStatus() => _guard(() async {
        final response = await _c.get<AccountStatusData>(
          '/v1/users/profile/status',
          (json) => AccountStatusData.fromJson(
              (json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
        );
        return response.data!;
      });

  // ── Account closure ───────────────────────────────────────────────────────

  Future<void> requestClosure({required String idempotencyKey}) =>
      _guard(() async {
        await _c.post<void>(
          '/v1/users/profile/closure/request',
          {},
          (_) {},
          idempotencyKey: idempotencyKey,
        );
      });

  Future<void> cancelClosure({required String idempotencyKey}) =>
      _guard(() async {
        await _c.delete<void>(
          '/v1/users/profile/closure/cancel',
          (_) {},
          idempotencyKey: idempotencyKey,
        );
      });

  // ── Data export ───────────────────────────────────────────────────────────

  Future<DataExportData> requestDataExport({required String idempotencyKey}) =>
      _guard(() async {
        final response = await _c.post<DataExportData>(
          '/v1/users/profile/data-export',
          {},
          (json) => DataExportData.fromJson(
              (json as Map<String, dynamic>)['data']
                  as Map<String, dynamic>),
          idempotencyKey: idempotencyKey,
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
      throw ProfileApiException(
        code: (error['code'] as String?) ?? 'UNKNOWN',
        message: (error['message'] as String?) ?? e.message ?? 'Unknown error',
        statusCode: e.response?.statusCode,
      );
    }
  }
}
