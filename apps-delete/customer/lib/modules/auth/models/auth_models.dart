/// Data models for E01 auth API responses (Customer Flutter).
/// All fields mirror the authentication-api.md contracts exactly.

/// OTP challenge data returned on registration/resend/PIN-reset initiation.
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

/// Session tokens returned after successful authentication.
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

/// MFA-pending data returned when login requires MFA completion.
class MfaPendingData {
  const MfaPendingData({
    required this.mfaToken,
    required this.methods,
    required this.expiresIn,
  });

  final String mfaToken;
  final List<String> methods;
  final int expiresIn;

  factory MfaPendingData.fromJson(Map<String, dynamic> json) => MfaPendingData(
        mfaToken: json['mfa_token'] as String,
        methods: (json['methods'] as List<dynamic>).cast<String>(),
        expiresIn: json['expires_in'] as int,
      );
}

/// Discriminated result of the login API call.
sealed class LoginResult {
  const LoginResult();
}

final class LoginSuccess extends LoginResult {
  const LoginSuccess({
    required this.tokens,
    this.newDeviceDetected = false,
  });

  final SessionTokenData tokens;
  final bool newDeviceDetected;
}

final class LoginMfaRequired extends LoginResult {
  const LoginMfaRequired({required this.mfaPending});

  final MfaPendingData mfaPending;
}

final class LoginDeviceVerificationRequired extends LoginResult {
  const LoginDeviceVerificationRequired({required this.challengeId});

  final String challengeId;
}

/// Discriminated result of OTP verification.
sealed class OtpVerifyResult {
  const OtpVerifyResult();
}

/// OTP verified for registration — PIN must still be set.
final class OtpVerifyRegistration extends OtpVerifyResult {
  const OtpVerifyRegistration({required this.userId, required this.setupToken});

  final String userId;
  final String setupToken;
}

/// OTP verified for login — full session issued.
final class OtpVerifyAuthenticated extends OtpVerifyResult {
  const OtpVerifyAuthenticated({required this.tokens});

  final SessionTokenData tokens;
}

/// Step-up initiation response.
class StepUpData {
  const StepUpData({
    required this.stepUpRequired,
    this.stepUpToken,
    this.methods,
    this.expiresAt,
  });

  final bool stepUpRequired;
  final String? stepUpToken;
  final List<String>? methods;
  final DateTime? expiresAt;

  factory StepUpData.fromJson(Map<String, dynamic> json) => StepUpData(
        stepUpRequired: json['step_up_required'] as bool,
        stepUpToken: json['step_up_token'] as String?,
        methods: json['methods'] != null
            ? (json['methods'] as List<dynamic>).cast<String>()
            : null,
        expiresAt: json['expires_at'] != null
            ? DateTime.parse(json['expires_at'] as String)
            : null,
      );
}

/// Step-up verify response — scoped step-up access token.
class StepUpTokenData {
  const StepUpTokenData({
    required this.stepUpAccessToken,
    required this.scope,
    required this.expiresIn,
  });

  final String stepUpAccessToken;
  final String scope;
  final int expiresIn;

  factory StepUpTokenData.fromJson(Map<String, dynamic> json) => StepUpTokenData(
        stepUpAccessToken: json['step_up_access_token'] as String,
        scope: json['scope'] as String,
        expiresIn: json['expires_in'] as int,
      );
}

/// Session summary for the sessions list screen.
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

/// TOTP enrollment initiation data.
class TotpEnrollmentData {
  const TotpEnrollmentData({
    required this.totpUri,
    required this.enrollmentId,
    required this.expiresAt,
  });

  final String totpUri;
  final String enrollmentId;
  final DateTime expiresAt;

  factory TotpEnrollmentData.fromJson(Map<String, dynamic> json) =>
      TotpEnrollmentData(
        totpUri: json['totp_uri'] as String,
        enrollmentId: json['enrollment_id'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String),
      );
}

/// TOTP enrollment confirmation data.
class TotpEnrollmentConfirmData {
  const TotpEnrollmentConfirmData({
    required this.enrolledAt,
    required this.backupCodes,
  });

  final DateTime enrolledAt;
  final List<String> backupCodes;

  factory TotpEnrollmentConfirmData.fromJson(Map<String, dynamic> json) =>
      TotpEnrollmentConfirmData(
        enrolledAt: DateTime.parse(json['enrolled_at'] as String),
        backupCodes: (json['backup_codes'] as List<dynamic>).cast<String>(),
      );
}
