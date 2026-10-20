import 'dart:io';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/agent_profile_models.dart';
import '../services/agent_profile_api_service.dart';
import '../state/agent_profile_state.dart';
import '../utils/profile_utils.dart';

/// T137 — AgentProfileStateNotifier: owns all agent profile state transitions.
/// Agent scope: view, edit personal info, photo, mobile change, account status.
/// Email change and account closure are NOT in agent scope (per plan §15.2).
final agentProfileStateNotifierProvider =
    AsyncNotifierProvider<AgentProfileStateNotifier, AgentProfileState>(
  AgentProfileStateNotifier.new,
);

class AgentProfileStateNotifier
    extends AsyncNotifier<AgentProfileState> {
  AgentProfileApiService get _api =>
      ref.read(agentProfileApiServiceProvider);

  @override
  Future<AgentProfileState> build() async => _loadProfile();

  Future<AgentProfileState> _loadProfile() async {
    try {
      final profile = await _api.getProfile();
      return AgentProfileLoaded(profile: profile);
    } on AgentProfileApiException catch (e) {
      if (e.statusCode == 404) return const AgentProfileNotFound();
      return AgentProfileError(message: e.message, code: e.code);
    } catch (_) {
      return const AgentProfileError(
          message: 'Failed to load profile. Please retry.');
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
        idempotencyKey: AgentProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        profile: updated,
        successMessage: 'Profile updated successfully.',
      ));
    } on AgentProfileApiException catch (e) {
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
        idempotencyKey: AgentProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        profile: updated,
        successMessage: 'Photo updated.',
      ));
    } on AgentProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
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
        idempotencyKey: AgentProfileUtils.idempotencyKey(),
      );
      state = AsyncData(current.copyWith(
        mobileChallenge: AgentContactChangeChallenge(
          newContact: newMobile,
          challengeId: challenge.challengeId,
          expiresAt: challenge.expiresAt,
          maskedDestination: challenge.maskedDestination,
        ),
      ));
    } on AgentProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  Future<void> verifyMobileChange(String otp) async {
    final current = _requireLoaded() ?? (return);
    final challenge = current.mobileChallenge;
    if (challenge == null) return;

    state = AsyncData(current.copyWith(isSubmitting: true));
    try {
      await _api.verifyMobileChange(
        challenge.challengeId,
        otp,
        idempotencyKey: AgentProfileUtils.idempotencyKey(),
      );
      state = const AsyncLoading();
      state = AsyncData(await _loadProfile());
    } on AgentProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        mobileChallenge: challenge.copyWith(
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
    } on AgentProfileApiException catch (e) {
      state = AsyncData(current.copyWith(
        errorCode: e.code,
        errorMessage: e.message,
      ));
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  AgentProfileLoaded? _requireLoaded() {
    final s = state.valueOrNull;
    return s is AgentProfileLoaded ? s : null;
  }
}

// ── Provider declarations ──────────────────────────────────────────────────────

final agentProfileApiServiceProvider = Provider<AgentProfileApiService>((ref) {
  throw UnimplementedError('agentProfileApiServiceProvider must be overridden');
});
