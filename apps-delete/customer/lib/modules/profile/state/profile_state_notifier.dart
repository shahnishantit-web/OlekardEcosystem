import 'dart:io';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/profile_models.dart';
import '../services/profile_api_service.dart';
import '../utils/profile_utils.dart';
import 'profile_state.dart';

/// T125 — ProfileStateNotifier: owns all profile state transitions.
/// Screens are read-only consumers; all mutations go through this notifier.
final profileStateNotifierProvider =
    AsyncNotifierProvider<ProfileStateNotifier, ProfileState>(
  ProfileStateNotifier.new,
);

class ProfileStateNotifier extends AsyncNotifier<ProfileState> {
  ProfileApiService get _api => ref.read(profileApiServiceProvider);

  @override
  Future<ProfileState> build() async => _loadProfile();

  Future<ProfileState> _loadProfile() async {
    try {
      final profile = await _api.getProfile();
      return ProfileLoaded(profile: profile);
    } on ProfileApiException catch (e) {
      if (e.statusCode == 404) return const ProfileNotFound();
      return ProfileError(message: e.message, code: e.code);
    } catch (_) {
      return const ProfileError(message: 'Failed to load profile. Please retry.');
    }
  }

  // ── Reload ────────────────────────────────────────────────────────────────

  Future<void> reload() async {
    state = const AsyncLoading();
    state = AsyncData(await _loadProfile());
  }

  // ── Personal info update ──────────────────────────────────────────────────

  Future<void> updatePersonalInfo(Map<String, dynamic> fields) async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final updated = await _api.updatePersonalInfo(
        fields,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        profile: updated,
        successMessage: 'Profile updated successfully.',
      ));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Photo upload ──────────────────────────────────────────────────────────

  Future<void> uploadPhoto(File photo) async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final updated = await _api.uploadPhoto(
        photo,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        profile: updated,
        successMessage: 'Photo updated.',
      ));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Email change ──────────────────────────────────────────────────────────

  Future<void> initiateEmailChange(String newEmail) async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final challenge = await _api.initiateEmailChange(
        newEmail,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        contactChallenge: ContactChangeChallenge(
          type: 'email',
          newContact: newEmail,
          challengeId: challenge.challengeId,
          expiresAt: challenge.expiresAt,
          maskedDestination: challenge.maskedDestination,
        ),
      ));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  Future<void> verifyEmailChange(String otp) async {
    final current = _requireLoaded() ?? (return);
    final challenge = current.contactChallenge;
    if (challenge == null || challenge.type != 'email') return;

    state = AsyncData(current.copyWith(isSubmitting: true));
    try {
      await _api.verifyEmailChange(
        challenge.challengeId,
        otp,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      // Reload profile to reflect updated email
      state = const AsyncLoading();
      state = AsyncData(await _loadProfile());
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        contactChallenge: challenge.copyWith(
          errorCode: e.code,
          errorMessage: e.message,
        ),
      ));
    }
  }

  // ── Mobile change ─────────────────────────────────────────────────────────

  Future<void> initiateMobileChange(String newMobile) async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final challenge = await _api.initiateMobileChange(
        newMobile,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        contactChallenge: ContactChangeChallenge(
          type: 'mobile',
          newContact: newMobile,
          challengeId: challenge.challengeId,
          expiresAt: challenge.expiresAt,
          maskedDestination: challenge.maskedDestination,
        ),
      ));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  Future<void> verifyMobileChange(String otp) async {
    final current = _requireLoaded() ?? (return);
    final challenge = current.contactChallenge;
    if (challenge == null || challenge.type != 'mobile') return;

    state = AsyncData(current.copyWith(isSubmitting: true));
    try {
      await _api.verifyMobileChange(
        challenge.challengeId,
        otp,
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = const AsyncLoading();
      state = AsyncData(await _loadProfile());
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        contactChallenge: challenge.copyWith(
          errorCode: e.code,
          errorMessage: e.message,
        ),
      ));
    }
  }

  // ── Account status ────────────────────────────────────────────────────────

  Future<void> loadAccountStatus() async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final status = await _api.getAccountStatus();
      state = AsyncData(current.copyWith(accountStatus: status));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Closure ───────────────────────────────────────────────────────────────

  Future<void> requestClosure() async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      await _api.requestClosure(idempotencyKey: ProfileUtils.idempotencyKey());
      state = const AsyncLoading();
      state = AsyncData(await _loadProfile());
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  Future<void> cancelClosure() async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      await _api.cancelClosure(idempotencyKey: ProfileUtils.idempotencyKey());
      state = const AsyncLoading();
      state = AsyncData(await _loadProfile());
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Data export ───────────────────────────────────────────────────────────

  Future<void> requestDataExport() async {
    final current = _requireLoaded() ?? (return);
    state = AsyncData(current.copyWith(isSubmitting: true));

    try {
      final export = await _api.requestDataExport(
        idempotencyKey: ProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        dataExport: export,
        successMessage: 'Export requested. You will receive an email when ready.',
      ));
    } on ProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  ProfileLoaded? _requireLoaded() {
    final s = state.valueOrNull;
    return s is ProfileLoaded ? s : null;
  }
}

// ── Provider declarations ─────────────────────────────────────────────────────

final profileApiServiceProvider = Provider<ProfileApiService>((ref) {
  throw UnimplementedError('profileApiServiceProvider must be overridden');
});
