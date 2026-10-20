import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/models/auth_models.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';

// ── Test doubles ──────────────────────────────────────────────────────────────

class _MockTokenStorage implements AuthTokenStorage {
  String? _accessToken;
  String? _refreshToken;
  String? _sessionId;

  @override
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  }) async {
    _accessToken = accessToken;
    _refreshToken = refreshToken;
    _sessionId = sessionId;
  }

  @override
  Future<String?> getAccessToken() async => _accessToken;

  @override
  Future<String?> getRefreshToken() async => _refreshToken;

  @override
  Future<String?> getSessionId() async => _sessionId;

  @override
  Future<void> clearTokens() async {
    _accessToken = null;
    _refreshToken = null;
    _sessionId = null;
  }

  @override
  Future<bool> hasTokens() async => _accessToken != null;
}

class _MockAuthApiService implements AuthApiService {
  // Implements — no super call needed.

  // Overrideable callbacks for each method.
  Future<OtpChallengeData> Function(String)? onInitiateRegistration;
  Future<OtpVerifyResult> Function(String, String)? onVerifyOtp;
  Future<LoginResult> Function()? onLogin;
  Future<void> Function()? onLogout;
  Future<SessionTokenData> Function(String)? onRefreshToken;

  @override
  Future<OtpChallengeData> initiateRegistration(String mobileNumber) async {
    return await onInitiateRegistration?.call(mobileNumber) ??
        OtpChallengeData(
          challengeId: 'challenge-001',
          expiresAt: DateTime.now().add(const Duration(minutes: 5)),
          maskedDestination: '+267 *** *** 567',
        );
  }

  @override
  Future<OtpVerifyResult> verifyOtp(String challengeId, String otp) async {
    return await onVerifyOtp?.call(challengeId, otp) ??
        OtpVerifyRegistration(userId: 'user-001', setupToken: 'setup-tok-001');
  }

  @override
  Future<LoginResult> login({
    required String identifier,
    required String credential,
    required String credentialType,
    required String deviceFingerprint,
  }) async {
    return await onLogin?.call() ??
        LoginSuccess(
          tokens: SessionTokenData(
            accessToken: 'access-tok',
            refreshToken: 'refresh-tok',
            sessionId: 'session-001',
            expiresIn: 900,
          ),
        );
  }

  @override
  Future<void> logout() async => await onLogout?.call();

  @override
  Future<SessionTokenData> refreshToken(String refreshToken) async =>
      await onRefreshToken?.call(refreshToken) ??
      SessionTokenData(
        accessToken: 'new-access-tok',
        refreshToken: 'new-refresh-tok',
        sessionId: 'session-001',
        expiresIn: 900,
      );

  // Stub out remaining methods.
  @override
  Future<OtpChallengeData> resendOtp(String challengeId) async =>
      OtpChallengeData(
        challengeId: challengeId,
        expiresAt: DateTime.now().add(const Duration(minutes: 5)),
      );

  @override
  Future<void> setPin(String pin, {required String setupToken}) async {}

  @override
  Future<DateTime> changePin(String currentPin, String newPin) async =>
      DateTime.now();

  @override
  Future<OtpChallengeData> initiatePinReset(String mobileNumber) async =>
      OtpChallengeData(
        challengeId: 'reset-challenge',
        expiresAt: DateTime.now().add(const Duration(minutes: 5)),
      );

  @override
  Future<void> completePinReset(
      String challengeId, String otp, String newPin) async {}

  @override
  Future<String?> initiatePinUnblock(String mobileNumber) async => null;

  @override
  Future<void> completePinUnblock(String challengeId, String otp) async {}

  @override
  Future<({String nonce, int expiresIn})> getBiometricChallenge() async =>
      (nonce: 'test-nonce', expiresIn: 120);

  @override
  Future<SessionTokenData> loginBiometric({
    required String nonce,
    required String userId,
    required String credentialId,
    required String signedAssertion,
    required String deviceFingerprint,
  }) async =>
      SessionTokenData(
        accessToken: 'bio-access-tok',
        refreshToken: 'bio-refresh-tok',
        sessionId: 'bio-session-001',
        expiresIn: 900,
      );

  @override
  Future<SessionTokenData> verifyMfa({
    required String mfaToken,
    required String method,
    required String code,
  }) async =>
      SessionTokenData(
        accessToken: 'mfa-access-tok',
        refreshToken: 'mfa-refresh-tok',
        sessionId: 'mfa-session-001',
        expiresIn: 900,
      );

  @override
  Future<TotpEnrollmentData> enrollTotp() async => TotpEnrollmentData(
        totpUri: 'otpauth://totp/OleKard:test?secret=TEST',
        enrollmentId: 'enrollment-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      );

  @override
  Future<TotpEnrollmentConfirmData> confirmTotpEnrollment(
          String enrollmentId, String totpCode) async =>
      TotpEnrollmentConfirmData(
        enrolledAt: DateTime.now(),
        backupCodes: ['code1', 'code2'],
      );

  @override
  Future<void> disableMfa(String method) async {}

  @override
  Future<StepUpData> initiateStepUp(
          String scope, Map<String, dynamic> operationContext) async =>
      const StepUpData(stepUpRequired: false);

  @override
  Future<StepUpTokenData> verifyStepUp({
    required String stepUpToken,
    required String method,
    required String credential,
  }) async =>
      StepUpTokenData(
        stepUpAccessToken: 'step-up-tok',
        scope: 'payment:high-value',
        expiresIn: 300,
      );

  @override
  Future<List<SessionInfo>> listSessions() async => [];

  @override
  Future<void> revokeSession(String sessionId) async {}

  @override
  Future<void> revokeAllSessions() async {}

  @override
  Future<SessionTokenData> verifyDevice(
          String challengeId, String otp) async =>
      SessionTokenData(
        accessToken: 'device-access-tok',
        refreshToken: 'device-refresh-tok',
        sessionId: 'device-session-001',
        expiresIn: 3600,
      );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

ProviderContainer _makeContainer({
  _MockTokenStorage? storage,
  _MockAuthApiService? api,
}) {
  final st = storage ?? _MockTokenStorage();
  final ap = api ?? _MockAuthApiService();

  return ProviderContainer(
    overrides: [
      authTokenStorageProvider.overrideWithValue(st),
      authApiServiceProvider.overrideWithValue(ap),
    ],
  );
}

// ── Tests ──────────────────────────────────────────────────────────────────────

void main() {
  group('AuthStateNotifier — initial state (T158)', () {
    test('starts unauthenticated when no tokens stored', () async {
      final container = _makeContainer();
      addTearDown(container.dispose);

      await container.read(authStateNotifierProvider.future);
      final state = container.read(authStateNotifierProvider).valueOrNull;

      expect(state, isA<AuthUnauthenticated>());
    });

    test('restores authenticated state when tokens exist in storage', () async {
      final storage = _MockTokenStorage();
      await storage.saveTokens(
        accessToken: 'existing-access',
        refreshToken: 'existing-refresh',
        sessionId: 'existing-session',
      );

      final container = _makeContainer(storage: storage);
      addTearDown(container.dispose);

      await container.read(authStateNotifierProvider.future);
      final state = container.read(authStateNotifierProvider).valueOrNull;

      expect(state, isA<AuthAuthenticated>());
      expect((state as AuthAuthenticated).sessionId, 'existing-session');
    });
  });

  group('Registration flow (T158)', () {
    test('transitions to otpPending on initiateRegistration', () async {
      final container = _makeContainer();
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthOtpPending>());
      expect((state as AuthOtpPending).challengeId, 'challenge-001');
      expect(state.maskedDestination, '+267 *** *** 567');
    });

    test('transitions to pinPending on OTP verify (registration path)', () async {
      final container = _makeContainer();
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');
      await container
          .read(authStateNotifierProvider.notifier)
          .verifyOtp('challenge-001', '123456');

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthPinPending>());
      expect((state as AuthPinPending).setupToken, 'setup-tok-001');
    });
  });

  group('Login flow (T158)', () {
    test('transitions to authenticated on successful PIN login', () async {
      final storage = _MockTokenStorage();
      final container = _makeContainer(storage: storage);
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: 'fp-001',
          );

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthAuthenticated>());
      expect(await storage.getAccessToken(), 'access-tok');
      expect(await storage.getRefreshToken(), 'refresh-tok');
    });

    test('transitions to mfaPending when server requires MFA', () async {
      final api = _MockAuthApiService()
        ..onLogin = () async => LoginMfaRequired(
              mfaPending: MfaPendingData(
                mfaToken: 'mfa-tok',
                methods: ['totp'],
                expiresIn: 300,
              ),
            );

      final container = _makeContainer(api: api);
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: 'fp-001',
          );

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthMfaPending>());
      expect((state as AuthMfaPending).mfaToken, 'mfa-tok');
    });

    test('transitions to deviceVerificationRequired on unrecognised device',
        () async {
      final api = _MockAuthApiService()
        ..onLogin = () async =>
            const LoginDeviceVerificationRequired(challengeId: 'dev-challenge');

      final container = _makeContainer(api: api);
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: 'fp-001',
          );

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthDeviceVerificationRequired>());
      expect((state as AuthDeviceVerificationRequired).challengeId,
          'dev-challenge');
    });
  });

  group('Logout (T158)', () {
    test('clears tokens from storage and transitions to unauthenticated',
        () async {
      final storage = _MockTokenStorage();
      await storage.saveTokens(
        accessToken: 'a', refreshToken: 'r', sessionId: 's');
      final container = _makeContainer(storage: storage);
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container.read(authStateNotifierProvider.notifier).logout();

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthUnauthenticated>());
      expect(await storage.hasTokens(), isFalse);
    });
  });

  group('API error handling (T158)', () {
    test('maps AuthApiException to errorCode on state', () async {
      final api = _MockAuthApiService()
        ..onInitiateRegistration = (_) async =>
            throw const AuthApiException(
                code: 'IDENTITY_007', message: 'Already registered');

      final container = _makeContainer(api: api);
      addTearDown(container.dispose);
      await container.read(authStateNotifierProvider.future);

      await container
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');

      final state = container.read(authStateNotifierProvider).valueOrNull;
      expect(state, isA<AuthUnauthenticated>());
      expect((state as AuthUnauthenticated).errorCode, 'IDENTITY_007');
    });
  });
}
