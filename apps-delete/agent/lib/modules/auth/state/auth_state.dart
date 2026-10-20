/// AuthState sealed class hierarchy for E01 Agent authentication flows.
/// Mirrors spec Section 18.2 state machine (agent subset):
/// unauthenticated → otp_pending → pin_pending → authenticated.
/// No biometric, no step-up, no MFA management in Phase 18 scope.
import '../models/auth_models.dart';

sealed class AuthState {
  const AuthState();
}

/// No active session — shown on first launch or after logout/expiry.
final class AuthUnauthenticated extends AuthState {
  const AuthUnauthenticated({this.errorCode, this.errorMessage});

  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;

  bool get isOffline => errorCode == 'NETWORK_OFFLINE';
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

/// Fully authenticated with a valid session.
final class AuthAuthenticated extends AuthState {
  const AuthAuthenticated({required this.sessionId});

  final String sessionId;
}

/// Account locked — PIN recovery CTA shown (spec US-AUTH-014).
final class AuthLocked extends AuthState {
  const AuthLocked({this.errorMessage});

  final String? errorMessage;
}

/// PIN unblock OTP challenge in progress (US-AUTH-032, US-AUTH-033).
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

  bool get hasError => errorCode != null;
}
