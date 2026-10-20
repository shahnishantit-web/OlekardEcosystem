/// Data models for E01 auth API responses (Agent Flutter).
/// All fields mirror the authentication-api.md contracts exactly.

class OtpChallengeData {
  const OtpChallengeData({
    required this.challengeId,
    required this.expiresAt,
    this.resendAt,
    this.maskedDestination,
    this.remainingSends,
  });

  final String challengeId;
  final DateTime expiresAt;
  final DateTime? resendAt;
  final String? maskedDestination;
  final int? remainingSends;

  factory OtpChallengeData.fromJson(Map<String, dynamic> json) =>
      OtpChallengeData(
        challengeId: json['challenge_id'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String),
        resendAt: json['resend_at'] != null
            ? DateTime.parse(json['resend_at'] as String)
            : null,
        maskedDestination: json['masked_destination'] as String?,
        remainingSends: json['remaining_sends'] as int?,
      );
}

class SessionTokenData {
  const SessionTokenData({
    required this.accessToken,
    required this.refreshToken,
    required this.sessionId,
    required this.expiresIn,
  });

  final String accessToken;
  final String refreshToken;
  final String sessionId;
  final int expiresIn;

  factory SessionTokenData.fromJson(Map<String, dynamic> json) =>
      SessionTokenData(
        accessToken: json['access_token'] as String,
        refreshToken: json['refresh_token'] as String,
        sessionId: json['session_id'] as String,
        expiresIn: json['expires_in'] as int,
      );
}

sealed class LoginResult {
  const LoginResult();
}

final class LoginSuccess extends LoginResult {
  const LoginSuccess({required this.tokens, this.newDeviceDetected = false});

  final SessionTokenData tokens;
  final bool newDeviceDetected;
}

final class LoginMfaRequired extends LoginResult {
  const LoginMfaRequired({required this.mfaToken});

  final String mfaToken;
}

final class LoginDeviceVerificationRequired extends LoginResult {
  const LoginDeviceVerificationRequired({required this.challengeId});

  final String challengeId;
}

sealed class OtpVerifyResult {
  const OtpVerifyResult();
}

final class OtpVerifyRegistration extends OtpVerifyResult {
  const OtpVerifyRegistration({required this.userId, required this.setupToken});

  final String userId;
  final String setupToken;
}

final class OtpVerifyAuthenticated extends OtpVerifyResult {
  const OtpVerifyAuthenticated({required this.tokens});

  final SessionTokenData tokens;
}

class SessionInfo {
  const SessionInfo({
    required this.sessionId,
    required this.lastActivityAt,
    required this.expiresAt,
    required this.isCurrent,
    this.deviceName,
    this.platform,
  });

  final String sessionId;
  final DateTime lastActivityAt;
  final DateTime expiresAt;
  final bool isCurrent;
  final String? deviceName;
  final String? platform;

  factory SessionInfo.fromJson(Map<String, dynamic> json) => SessionInfo(
        sessionId: json['session_id'] as String,
        lastActivityAt: DateTime.parse(json['last_activity_at'] as String),
        expiresAt: DateTime.parse(json['expires_at'] as String),
        isCurrent: json['is_current'] as bool,
        deviceName: json['device_name'] as String?,
        platform: json['platform'] as String?,
      );
}
