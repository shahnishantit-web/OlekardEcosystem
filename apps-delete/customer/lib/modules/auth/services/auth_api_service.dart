import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import '../../../core/api/api_client.dart';
import '../models/auth_models.dart';
import '../utils/auth_utils.dart';

/// Typed API exception carrying the E01 error code.
/// Credentials are NEVER included in the message or stack trace.
class AuthApiException implements Exception {
  const AuthApiException({
    required this.code,
    required this.message,
    this.statusCode,
  });

  final String code;
  final String message;
  final int? statusCode;

  @override
  String toString() => 'AuthApiException($code): $message';
}

/// All E01 auth API calls. Maps authentication-api.md endpoints to typed
/// return values. DioException is converted to AuthApiException.
class AuthApiService {
  const AuthApiService(this._client);

  /// No-op constructor for subclassing in tests. Never calls any _client method
  /// because the subclass overrides all methods before they reach this class.
  @visibleForTesting
  AuthApiService.forTest() : _client = null;

  final ApiClient? _client;

  ApiClient get _c => _client!;

  // ── Registration ──────────────────────────────────────────────────────────

  Future<OtpChallengeData> initiateRegistration(String mobileNumber) async {
    return _guard(() async {
      final response = await _c.post<OtpChallengeData>(
        '/v1/auth/register',
        {'mobile_number': mobileNumber, 'actor_type': 'CUSTOMER'},
        (json) => OtpChallengeData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<OtpVerifyResult> verifyOtp(String challengeId, String otp) async {
    return _guard(() async {
      final response = await _c.post<Map<String, dynamic>>(
        '/v1/auth/otp/verify',
        {'challenge_id': challengeId, 'otp': otp},
        (json) => json as Map<String, dynamic>,
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      final data = response.data!;
      if (data.containsKey('setup_token')) {
        return OtpVerifyRegistration(
          userId: data['user_id'] as String,
          setupToken: data['setup_token'] as String,
        );
      }
      return OtpVerifyAuthenticated(tokens: SessionTokenData.fromJson(data));
    });
  }

  Future<OtpChallengeData> resendOtp(String challengeId) async {
    return _guard(() async {
      final response = await _c.post<OtpChallengeData>(
        '/v1/auth/otp/resend',
        {'challenge_id': challengeId},
        (json) => OtpChallengeData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  // ── PIN Management ─────────────────────────────────────────────────────────

  /// Set PIN after OTP verification. Uses X-Setup-Token header (single-use).
  Future<void> setPin(String pin, {required String setupToken}) async {
    await _guard(() async {
      await _c.post<void>(
        '/v1/auth/pin/set',
        {'pin': pin},
        (_) {},
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
        setupToken: setupToken,
      );
    });
  }

  Future<DateTime> changePin(String currentPin, String newPin) async {
    return _guard(() async {
      final response = await _c.post<DateTime>(
        '/v1/auth/pin/change',
        {'current_pin': currentPin, 'new_pin': newPin},
        (json) => DateTime.parse(
            (json as Map<String, dynamic>)['updated_at'] as String),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<OtpChallengeData> initiatePinReset(String mobileNumber) async {
    return _guard(() async {
      final response = await _c.post<OtpChallengeData>(
        '/v1/auth/pin/reset/initiate',
        {'mobile_number': mobileNumber},
        (json) => OtpChallengeData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<void> completePinReset(
      String challengeId, String otp, String newPin) async {
    await _guard(() async {
      await _c.post<void>(
        '/v1/auth/pin/reset/complete',
        {'challenge_id': challengeId, 'otp': otp, 'new_pin': newPin},
        (_) {},
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
    });
  }

  /// Returns `challengeId` or null (no-disclosure when account absent).
  Future<String?> initiatePinUnblock(String mobileNumber) async {
    return _guard(() async {
      final response = await _c.post<String?>(
        '/v1/auth/pin/unblock',
        {'mobile_number': mobileNumber},
        (json) => (json as Map<String, dynamic>)['challenge_id'] as String?,
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data;
    });
  }

  Future<void> completePinUnblock(String challengeId, String otp) async {
    await _guard(() async {
      await _c.post<void>(
        '/v1/auth/pin/unblock',
        {'challenge_id': challengeId, 'otp': otp},
        (_) {},
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
    });
  }

  // ── Authentication ─────────────────────────────────────────────────────────

  Future<LoginResult> login({
    required String identifier,
    required String credential,
    required String credentialType,
    required String deviceFingerprint,
  }) async {
    return _guardLogin(() async {
      final response = await _c.post<Map<String, dynamic>>(
        '/v1/auth/login',
        {
          'identifier': identifier,
          'credential_type': credentialType,
          'credential': credential,
          'device_fingerprint': deviceFingerprint,
        },
        (json) => json as Map<String, dynamic>,
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      final data = response.data!;
      if (data['mfa_required'] == true) {
        return LoginMfaRequired(mfaPending: MfaPendingData.fromJson(data));
      }
      return LoginSuccess(
        tokens: SessionTokenData.fromJson(data),
        newDeviceDetected: data['new_device_detected'] as bool? ?? false,
      );
    });
  }

  // ── Biometric Login ────────────────────────────────────────────────────────

  Future<({String nonce, int expiresIn})> getBiometricChallenge() async {
    return _guard(() async {
      final response = await _c.get<({String nonce, int expiresIn})>(
        '/v1/auth/login/biometric/challenge',
        (json) {
          final m = json as Map<String, dynamic>;
          return (nonce: m['nonce'] as String, expiresIn: m['expires_in'] as int);
        },
      );
      return response.data!;
    });
  }

  Future<SessionTokenData> loginBiometric({
    required String nonce,
    required String userId,
    required String credentialId,
    required String signedAssertion,
    required String deviceFingerprint,
  }) async {
    return _guard(() async {
      final response = await _c.post<SessionTokenData>(
        '/v1/auth/login/biometric',
        {
          'nonce': nonce,
          'user_id': userId,
          'credential_id': credentialId,
          'signed_assertion': signedAssertion,
          'device_fingerprint': deviceFingerprint,
        },
        (json) => SessionTokenData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  // ── MFA ───────────────────────────────────────────────────────────────────

  Future<SessionTokenData> verifyMfa({
    required String mfaToken,
    required String method,
    required String code,
  }) async {
    return _guard(() async {
      final response = await _c.post<SessionTokenData>(
        '/v1/auth/mfa/verify',
        {'mfa_token': mfaToken, 'method': method, 'code': code},
        (json) => SessionTokenData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<TotpEnrollmentData> enrollTotp() async {
    return _guard(() async {
      final response = await _c.post<TotpEnrollmentData>(
        '/v1/auth/mfa/enroll/totp',
        {},
        (json) => TotpEnrollmentData.fromJson(json as Map<String, dynamic>),
      );
      return response.data!;
    });
  }

  Future<TotpEnrollmentConfirmData> confirmTotpEnrollment(
      String enrollmentId, String totpCode) async {
    return _guard(() async {
      final response = await _c.post<TotpEnrollmentConfirmData>(
        '/v1/auth/mfa/enroll/totp/confirm',
        {'enrollment_id': enrollmentId, 'totp_code': totpCode},
        (json) =>
            TotpEnrollmentConfirmData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<void> disableMfa(String method) async {
    await _guard(() async {
      await _c.post<void>(
        '/v1/auth/mfa/disable',
        {'method': method},
        (_) {},
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
    });
  }

  // ── Token Management ──────────────────────────────────────────────────────

  Future<SessionTokenData> refreshToken(String refreshToken) async {
    return _guard(() async {
      final response = await _c.post<SessionTokenData>(
        '/v1/auth/token/refresh',
        {'refresh_token': refreshToken},
        (json) => SessionTokenData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  Future<void> logout() async {
    await _guard(() async {
      await _c.post<void>('/v1/auth/logout', {}, (_) {});
    });
  }

  // ── Step-Up ───────────────────────────────────────────────────────────────

  Future<StepUpData> initiateStepUp(
      String scope, Map<String, dynamic> operationContext) async {
    return _guard(() async {
      final response = await _c.post<StepUpData>(
        '/v1/auth/step-up/initiate',
        {'scope': scope, 'operation_context': operationContext},
        (json) => StepUpData.fromJson(json as Map<String, dynamic>),
      );
      return response.data!;
    });
  }

  Future<StepUpTokenData> verifyStepUp({
    required String stepUpToken,
    required String method,
    required String credential,
  }) async {
    return _guard(() async {
      final response = await _c.post<StepUpTokenData>(
        '/v1/auth/step-up/verify',
        {'step_up_token': stepUpToken, 'method': method, 'credential': credential},
        (json) => StepUpTokenData.fromJson(json as Map<String, dynamic>),
        idempotencyKey: AuthUtils.generateIdempotencyKey(),
      );
      return response.data!;
    });
  }

  // ── Sessions ──────────────────────────────────────────────────────────────

  Future<List<SessionInfo>> listSessions() async {
    return _guard(() async {
      final response = await _c.get<List<SessionInfo>>(
        '/v1/auth/sessions',
        (json) {
          final sessions =
              (json as Map<String, dynamic>)['sessions'] as List<dynamic>;
          return sessions
              .map((s) => SessionInfo.fromJson(s as Map<String, dynamic>))
              .toList();
        },
      );
      return response.data!;
    });
  }

  Future<void> revokeSession(String sessionId) async {
    await _guard(() async {
      await _c.delete<void>('/v1/auth/sessions/$sessionId', (_) {});
    });
  }

  Future<void> revokeAllSessions() async {
    await _guard(() async {
      await _c.delete<void>('/v1/auth/sessions', (_) {});
    });
  }

  // ── Device Verification ────────────────────────────────────────────────────

  Future<SessionTokenData> verifyDevice(
      String challengeId, String otp) async {
    return _guard(() async {
      final response = await _c.post<SessionTokenData>(
        '/v1/auth/devices/verify',
        {'challenge_id': challengeId, 'otp': otp},
        (json) => SessionTokenData.fromJson(json as Map<String, dynamic>),
      );
      return response.data!;
    });
  }

  // ── Error handling ─────────────────────────────────────────────────────────

  Future<T> _guard<T>(Future<T> Function() action) async {
    try {
      return await action();
    } on DioException catch (e) {
      throw _extractException(e);
    }
  }

  /// Login variant: intercepts 403 IDENTITY_023 (device verification required)
  /// and returns a typed result instead of throwing.
  Future<LoginResult> _guardLogin(
      Future<LoginResult> Function() action) async {
    try {
      return await action();
    } on DioException catch (e) {
      if (e.response?.statusCode == 403) {
        final body = e.response?.data as Map<String, dynamic>?;
        final error = body?['error'] as Map<String, dynamic>?;
        final challengeId = error?['challenge_id'] as String?;
        if (challengeId != null) {
          return LoginDeviceVerificationRequired(challengeId: challengeId);
        }
      }
      throw _extractException(e);
    }
  }

  AuthApiException _extractException(DioException e) {
    final body = e.response?.data as Map<String, dynamic>?;
    final error = body?['error'] as Map<String, dynamic>?;
    return AuthApiException(
      code: error?['code'] as String? ?? 'NETWORK_ERROR',
      message: error?['message'] as String? ?? 'Request failed',
      statusCode: e.response?.statusCode,
    );
  }
}
