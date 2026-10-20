/// AuthState sealed class hierarchy for E01 Customer authentication flows.
/// Mirrors spec Section 19.1 state machine: unauthenticated → otp_pending →
/// pin_pending → authenticated, with mfa_pending, step_up_pending, locked branches.
import '../models/auth_models.dart';

sealed class AuthState {
  const AuthState();
}

/// No active session. Shown on first launch or after logout/expiry.
final class AuthUnauthenticated extends AuthState {
  const AuthUnauthenticated({this.errorCode, this.errorMessage});

  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;
}

/// OTP verification in progress — covers registration and login OTP branches.
final class AuthOtpPending extends AuthState {
  const AuthOtpPending({
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
    this.resendAt,
    this.remainingSends,
    this.errorCode,
    this.errorMessage,
  });

  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;
  final DateTime? resendAt;
  final int? remainingSends;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;

  AuthOtpPending copyWith({
    DateTime? resendAt,
    int? remainingSends,
    String? errorCode,
    String? errorMessage,
  }) =>
      AuthOtpPending(
        challengeId: challengeId,
        expiresAt: expiresAt,
        maskedDestination: maskedDestination,
        resendAt: resendAt ?? this.resendAt,
        remainingSends: remainingSends ?? this.remainingSends,
        errorCode: errorCode,
        errorMessage: errorMessage,
      );
}

/// PIN setup required — post OTP verification during registration.
final class AuthPinPending extends AuthState {
  const AuthPinPending({
    required this.userId,
    required this.setupToken,
    this.errorCode,
    this.errorMessage,
  });

  final String userId;
  final String setupToken;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;
}

/// MFA challenge in progress — second factor required after PIN login.
final class AuthMfaPending extends AuthState {
  const AuthMfaPending({
    required this.mfaToken,
    required this.methods,
    required this.expiresIn,
    this.errorCode,
    this.errorMessage,
  });

  final String mfaToken;
  final List<String> methods;
  final int expiresIn;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;
}

/// Fully authenticated with a valid session.
final class AuthAuthenticated extends AuthState {
  const AuthAuthenticated({required this.sessionId});

  final String sessionId;
}

/// Step-up challenge is active. The previous authenticated state is preserved
/// so the in-progress operation context is not lost (spec US-AUTH-037).
final class AuthStepUpPending extends AuthState {
  const AuthStepUpPending({
    required this.stepUpToken,
    required this.methods,
    required this.expiresAt,
    required this.sessionId,
  });

  final String stepUpToken;
  final List<String> methods;
  final DateTime expiresAt;
  final String sessionId;
}

/// Account locked — PIN recovery CTA shown (spec US-AUTH-014).
final class AuthLocked extends AuthState {
  const AuthLocked({this.errorMessage});

  final String? errorMessage;
}

/// PIN unblock OTP challenge in progress (US-AUTH-032, US-AUTH-033).
/// Locked-account recovery via SMS OTP; no new PIN is set.
final class AuthPinUnblockPending extends AuthState {
  const AuthPinUnblockPending({
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
    this.errorCode,
    this.errorMessage,
  });

  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;
}

/// Unrecognised device — OTP device-verification flow required before re-login.
final class AuthDeviceVerificationRequired extends AuthState {
  const AuthDeviceVerificationRequired({
    required this.challengeId,
    this.errorCode,
    this.errorMessage,
  });

  final String challengeId;
  final String? errorCode;
  final String? errorMessage;
}
