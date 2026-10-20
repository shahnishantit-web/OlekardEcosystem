import '../models/agent_profile_models.dart';

/// Mobile-change sub-flow state: tracks an in-progress OTP challenge.
final class AgentContactChangeChallenge {
  const AgentContactChangeChallenge({
    required this.newContact,
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
    this.errorCode,
    this.errorMessage,
  });

  final String newContact;
  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;

  AgentContactChangeChallenge copyWith({
    String? errorCode,
    String? errorMessage,
  }) =>
      AgentContactChangeChallenge(
        newContact: newContact,
        challengeId: challengeId,
        expiresAt: expiresAt,
        maskedDestination: maskedDestination,
        errorCode: errorCode,
        errorMessage: errorMessage,
      );
}

// ---------------------------------------------------------------------------
// Sealed AgentProfileState hierarchy
// ---------------------------------------------------------------------------

sealed class AgentProfileState {
  const AgentProfileState();
}

final class AgentProfileLoading extends AgentProfileState {
  const AgentProfileLoading();
}

final class AgentProfileLoaded extends AgentProfileState {
  const AgentProfileLoaded({
    required this.profile,
    this.accountStatus,
    this.mobileChallenge,
    this.errorCode,
    this.errorMessage,
    this.successMessage,
    this.isSubmitting = false,
  });

  final AgentProfileData profile;
  final AgentAccountStatusData? accountStatus;
  final AgentContactChangeChallenge? mobileChallenge;
  final String? errorCode;
  final String? errorMessage;
  final String? successMessage;
  final bool isSubmitting;

  bool get hasError => errorCode != null;

  AgentProfileLoaded copyWith({
    AgentProfileData? profile,
    AgentAccountStatusData? accountStatus,
    Object? mobileChallenge = _sentinel,
    String? errorCode,
    String? errorMessage,
    String? successMessage,
    bool? isSubmitting,
  }) =>
      AgentProfileLoaded(
        profile: profile ?? this.profile,
        accountStatus: accountStatus ?? this.accountStatus,
        mobileChallenge: mobileChallenge == _sentinel
            ? this.mobileChallenge
            : mobileChallenge as AgentContactChangeChallenge?,
        errorCode: errorCode,
        errorMessage: errorMessage,
        successMessage: successMessage,
        isSubmitting: isSubmitting ?? false,
      );

  static const Object _sentinel = Object();
}

final class AgentProfileError extends AgentProfileState {
  const AgentProfileError({required this.message, this.code});

  final String message;
  final String? code;
}

final class AgentProfileNotFound extends AgentProfileState {
  const AgentProfileNotFound();
}
