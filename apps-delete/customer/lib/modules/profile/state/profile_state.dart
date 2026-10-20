import '../models/profile_models.dart';

/// Contact-change sub-flow state: tracks an in-progress OTP challenge.
final class ContactChangeChallenge {
  const ContactChangeChallenge({
    required this.type, // 'email' or 'mobile'
    required this.newContact,
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
    this.errorCode,
    this.errorMessage,
  });

  final String type;
  final String newContact;
  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;
  final String? errorCode;
  final String? errorMessage;

  bool get hasError => errorCode != null;

  ContactChangeChallenge copyWith({
    String? errorCode,
    String? errorMessage,
  }) =>
      ContactChangeChallenge(
        type: type,
        newContact: newContact,
        challengeId: challengeId,
        expiresAt: expiresAt,
        maskedDestination: maskedDestination,
        errorCode: errorCode,
        errorMessage: errorMessage,
      );
}

// ---------------------------------------------------------------------------
// Sealed ProfileState hierarchy (spec §15 state machine)
// ---------------------------------------------------------------------------

sealed class ProfileState {
  const ProfileState();
}

/// Initial fetch in progress.
final class ProfileLoading extends ProfileState {
  const ProfileLoading();
}

/// Profile data available — carries optional sub-flow state and inline errors.
final class ProfileLoaded extends ProfileState {
  const ProfileLoaded({
    required this.profile,
    this.accountStatus,
    this.contactChallenge,
    this.dataExport,
    this.errorCode,
    this.errorMessage,
    this.successMessage,
    this.isSubmitting = false,
  });

  final ProfileData profile;
  final AccountStatusData? accountStatus;
  final ContactChangeChallenge? contactChallenge;
  final DataExportData? dataExport;
  final String? errorCode;
  final String? errorMessage;
  final String? successMessage;
  final bool isSubmitting;

  bool get hasError => errorCode != null;

  ProfileLoaded copyWith({
    ProfileData? profile,
    AccountStatusData? accountStatus,
    Object? contactChallenge = _sentinel,
    Object? dataExport = _sentinel,
    String? errorCode,
    String? errorMessage,
    String? successMessage,
    bool? isSubmitting,
  }) =>
      ProfileLoaded(
        profile: profile ?? this.profile,
        accountStatus: accountStatus ?? this.accountStatus,
        contactChallenge: contactChallenge == _sentinel
            ? this.contactChallenge
            : contactChallenge as ContactChangeChallenge?,
        dataExport:
            dataExport == _sentinel ? this.dataExport : dataExport as DataExportData?,
        errorCode: errorCode,
        errorMessage: errorMessage,
        successMessage: successMessage,
        isSubmitting: isSubmitting ?? false,
      );

  static const Object _sentinel = Object();
}

/// Network or server error — offer retry.
final class ProfileError extends ProfileState {
  const ProfileError({required this.message, this.code});

  final String message;
  final String? code;
}

/// HTTP 404 — profile record not yet created (race condition after registration).
final class ProfileNotFound extends ProfileState {
  const ProfileNotFound();
}
