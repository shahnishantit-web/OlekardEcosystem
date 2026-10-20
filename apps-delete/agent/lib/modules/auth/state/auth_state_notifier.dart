import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/auth_models.dart';
import '../services/auth_api_service.dart';
import '../storage/auth_token_storage.dart';
import 'auth_state.dart';

/// Riverpod provider — exposed as the canonical auth state source for Agent.
final authStateNotifierProvider =
    AsyncNotifierProvider<AuthStateNotifier, AuthState>(
  AuthStateNotifier.new,
);

/// E01 Agent auth state machine (spec Section 18.2).
/// Agent scope: registration, PIN login, PIN management, device verification,
/// session management. No biometric, no MFA management, no step-up in Phase 18.
class AuthStateNotifier extends AsyncNotifier<AuthState> {
  AuthApiService get _api => ref.read(authApiServiceProvider);
  AuthTokenStorage get _storage => ref.read(authTokenStorageProvider);

  @override
  Future<AuthState> build() async {
    final hasTokens = await _storage.hasTokens();
    if (hasTokens) {
      final sessionId = await _storage.getSessionId();
      return AuthAuthenticated(sessionId: sessionId ?? '');
    }
    return const AuthUnauthenticated();
  }

  // ── Registration flow ─────────────────────────────────────────────────────

  Future<void> initiateRegistration(String mobileNumber) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final challenge = await _api.initiateRegistration(mobileNumber);
      return AuthOtpPending(
        challengeId: challenge.challengeId,
        expiresAt: challenge.expiresAt,
        maskedDestination: challenge.maskedDestination,
        resendAt: challenge.resendAt,
        remainingSends: challenge.remainingSends,
      );
    });
    _mapApiError();
  }

  // ── OTP flows ─────────────────────────────────────────────────────────────

  Future<void> verifyOtp(String challengeId, String otp) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final result = await _api.verifyOtp(challengeId, otp);
      return switch (result) {
        OtpVerifyRegistration(:final userId, :final setupToken) =>
          AuthPinPending(userId: userId, setupToken: setupToken),
        OtpVerifyAuthenticated(:final tokens) => await _authenticated(tokens),
      };
    });
    _mapApiError();
  }

  Future<void> resendOtp(String challengeId) async {
    final current = state.valueOrNull;
    if (current is! AuthOtpPending) return;

    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final updated = await _api.resendOtp(challengeId);
      return current.copyWith(
        resendAt: updated.resendAt,
        remainingSends: updated.remainingSends,
      );
    });
    _mapApiError();
  }

  // ── PIN flows ─────────────────────────────────────────────────────────────

  Future<void> setPin(String pin) async {
    final current = state.valueOrNull;
    if (current is! AuthPinPending) return;

    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      await _api.setPin(pin, setupToken: current.setupToken);
      return const AuthUnauthenticated();
    });
    _mapApiError();
  }

  Future<void> changePin(String currentPin, String newPin) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      await _api.changePin(currentPin, newPin);
      final sessionId = await _storage.getSessionId();
      return AuthAuthenticated(sessionId: sessionId ?? '');
    });
    _mapApiError();
  }

  Future<void> initiatePinReset(String mobileNumber) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final challenge = await _api.initiatePinReset(mobileNumber);
      return AuthOtpPending(
        challengeId: challenge.challengeId,
        expiresAt: challenge.expiresAt,
        maskedDestination: challenge.maskedDestination,
      );
    });
    _mapApiError();
  }

  Future<void> completePinReset(
      String challengeId, String otp, String newPin) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      await _api.completePinReset(challengeId, otp, newPin);
      return const AuthUnauthenticated();
    });
    _mapApiError();
  }

  /// US-AUTH-032 — Send OTP to the registered mobile to initiate PIN unblock.
  Future<void> initiatePinUnblock(String mobileNumber) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final challengeId = await _api.initiatePinUnblock(mobileNumber);
      return AuthPinUnblockPending(
        challengeId: challengeId ?? '',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      );
    });
    _mapApiError();
  }

  /// US-AUTH-033 — Verify OTP and lift the PIN lock.
  Future<void> completePinUnblock(String challengeId, String otp) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      await _api.completePinUnblock(challengeId, otp);
      return const AuthUnauthenticated();
    });
    _mapApiError();
  }

  // ── Login flow ─────────────────────────────────────────────────────────────

  Future<void> login({
    required String identifier,
    required String credential,
    required String deviceFingerprint,
    String credentialType = 'pin',
  }) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final result = await _api.login(
        identifier: identifier,
        credential: credential,
        credentialType: credentialType,
        deviceFingerprint: deviceFingerprint,
      );
      return switch (result) {
        LoginSuccess(:final tokens) =>
          await _authenticated(tokens),
        LoginMfaRequired() => const AuthUnauthenticated(
            errorCode: 'IDENTITY_050',
            errorMessage:
                'Multi-factor authentication is not yet supported in the Agent application. Contact your administrator.',
          ),
        LoginDeviceVerificationRequired(:final challengeId) =>
          AuthDeviceVerificationRequired(challengeId: challengeId),
      };
    });
    _mapApiError();
  }

  // ── Device verification ───────────────────────────────────────────────────

  Future<void> verifyDevice(String challengeId, String otp) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(() async {
      final tokens = await _api.verifyDevice(challengeId, otp);
      return _authenticated(tokens);
    });
    _mapApiError();
  }

  // ── Session management ────────────────────────────────────────────────────

  Future<void> logout() async {
    state = const AsyncLoading();
    try {
      await _api.logout();
    } catch (_) {
      // Best-effort logout — clear local tokens regardless of server response.
    }
    await _storage.clearTokens();
    state = const AsyncData(AuthUnauthenticated());
  }

  // ── Internal helpers ──────────────────────────────────────────────────────

  Future<AuthAuthenticated> _authenticated(SessionTokenData tokens) async {
    await _storage.saveTokens(
      accessToken: tokens.accessToken,
      refreshToken: tokens.refreshToken,
      sessionId: tokens.sessionId,
    );
    return AuthAuthenticated(sessionId: tokens.sessionId);
  }

  void _mapApiError() {
    final s = state;
    if (s is AsyncError && s.error is AuthApiException) {
      final ex = s.error as AuthApiException;
      final current = state.valueOrNull ?? const AuthUnauthenticated();
      final withError = switch (current) {
        AuthUnauthenticated() =>
          AuthUnauthenticated(errorCode: ex.code, errorMessage: ex.message),
        AuthOtpPending() => (current as AuthOtpPending)
            .copyWith(errorCode: ex.code, errorMessage: ex.message),
        AuthPinPending() => AuthPinPending(
            userId: (current as AuthPinPending).userId,
            setupToken: current.setupToken,
            errorCode: ex.code,
            errorMessage: ex.message,
          ),
        AuthDeviceVerificationRequired() => AuthDeviceVerificationRequired(
            challengeId:
                (current as AuthDeviceVerificationRequired).challengeId,
            errorCode: ex.code,
            errorMessage: ex.message,
          ),
        AuthPinUnblockPending() => AuthPinUnblockPending(
            challengeId: (current as AuthPinUnblockPending).challengeId,
            expiresAt: current.expiresAt,
            maskedDestination: current.maskedDestination,
            errorCode: ex.code,
            errorMessage: ex.message,
          ),
        _ => AuthUnauthenticated(errorCode: ex.code, errorMessage: ex.message),
      };
      state = AsyncData(withError);
    }
  }
}

// ── Provider declarations ─────────────────────────────────────────────────────

final authTokenStorageProvider = Provider<AuthTokenStorage>((ref) {
  throw UnimplementedError('authTokenStorageProvider must be overridden');
});

final authApiServiceProvider = Provider<AuthApiService>((ref) {
  throw UnimplementedError('authApiServiceProvider must be overridden');
});
